<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Common\Response;
use App\Backends\Plex\Action\Backup;
use App\Backends\Plex\Action\Export;
use App\Backends\Plex\Action\GetMetaData;
use App\Backends\Plex\Action\Import;
use App\Backends\Plex\PlexGuid;
use App\Libs\Container;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use App\Libs\Extends\RetryableHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
use Symfony\Contracts\HttpClient\ResponseStreamInterface as iResponseStream;

class ImportFlowTest extends PlexTestCase
{
    #[DataProvider('inheritedActions')]
    public function test_inherited_handle_streams(string $actionClass): void
    {
        $source = new MockHttpClient(new MockResponse('{"MediaContainer":{"Metadata":[{"ratingKey":"1","type":"movie"}]}}'));
        $http = new HttpClient($source);
        $action = new $actionClass($http, $this->logger);
        $items = [];
        $response = $source->request('GET', 'http://plex.test');
        $stream = $source->stream($response);
        $retryable = $this->createMock(RetryableHttpClient::class);
        $retryable->expects($this->once())->method('stream')->willReturn($stream);
        new ReflectionProperty($action, 'http')->setValue($action, $retryable);

        $this->invokeHandle($action, $this->makeContext(), $response, static function (array $item, array $logContext = []) use (
            &$items,
        ): void {
            $items[] = $item;
        });

        $this->assertCount(1, $items);
        $this->assertSame('1', $items[0]['ratingKey']);
    }

    public function test_import_handle_replays_buffered_response(): void
    {
        $http = new class(new MockHttpClient(
            new MockResponse('{"MediaContainer":{"Metadata":[{"ratingKey":"1","type":"movie"}]}}'),
        )) extends HttpClient {
            public int $streamCalls = 0;

            public function stream(iterable|iResponse $responses, ?float $timeout = null): iResponseStream
            {
                $this->streamCalls++;
                return parent::stream($responses, $timeout);
            }
        };
        $action = new Import($http, $this->logger);
        $items = [];
        $response = $http->request('GET', 'http://plex.test', [
            'body' => json_encode([
                'MediaContainer' => [
                    'Metadata' => [['ratingKey' => '1', 'type' => 'movie']],
                ],
            ]),
        ]);

        $this->invokeHandle(
            $action,
            $this->makeContext(),
            $response,
            static function (array $item, array $logContext = []) use (&$items): void {
                $items[] = $item;
            },
            [],
            http_client_chunks($response),
        );

        $this->assertSame(0, $http->streamCalls);
        $this->assertCount(1, $items);
        $this->assertSame('1', $items[0]['ratingKey']);
    }

    public static function inheritedActions(): array
    {
        return [[Export::class], [Backup::class]];
    }

    public function test_import_process_adds_items(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;
        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');

        $action = new Import($this->makeHttpClient(), $this->logger);
        $guid = new PlexGuid($this->logger)->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
            [],
        );

        $result = $mapper->commit();

        $this->assertSame(1, $result['movie']['added']);
    }

    public function test_import_ignores_missing_date(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;
        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');
        unset($item['addedAt'], $item['lastViewedAt']);

        $action = new Import($this->makeHttpClient(), $this->logger);
        $guid = new PlexGuid($this->logger)->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
            [],
        );

        $result = $mapper->commit();

        $this->assertSame(0, $result['movie']['added']);
    }

    public function test_import_episode_adds(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;

        $item = [
            'ratingKey' => '11',
            'type' => 'episode',
            'title' => 'Pilot',
            'grandparentTitle' => 'Test Show',
            'parentIndex' => 1,
            'index' => 1,
            'addedAt' => 1000,
            'Guid' => [
                ['id' => 'imdb://tt123'],
            ],
            'grandparentRatingKey' => 'show-1',
        ];

        $showPayload = [
            'MediaContainer' => [
                'Metadata' => [
                    [
                        'ratingKey' => 'show-1',
                        'type' => 'show',
                        'title' => 'Test Show',
                        'Guid' => [
                            ['id' => 'imdb://tt123'],
                        ],
                        'guid' => 'imdb://tt123',
                    ],
                ],
            ],
        ];

        Container::add(GetMetaData::class, fn() => new class($showPayload) {
            public function __construct(
                private array $payload,
            ) {}

            public function __invoke(\App\Backends\Common\Context $context, string|int $id, array $opts = []): Response
            {
                return new Response(status: true, response: $this->payload);
            }
        });

        $action = new Import($this->makeHttpClient(), $this->logger);
        $guid = new PlexGuid($this->logger)->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
            [],
        );

        $result = $mapper->commit();

        $this->assertSame(1, $result['episode']['added']);
    }

    public function test_import_prefetched_genres(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;

        $item = [
            'ratingKey' => '11',
            'type' => 'episode',
            'title' => 'Pilot',
            'grandparentTitle' => 'Test Show',
            'parentIndex' => 1,
            'index' => 1,
            'addedAt' => 1000,
            'Guid' => [
                ['id' => 'imdb://tt123'],
            ],
            'grandparentRatingKey' => 'show-1',
        ];

        $show = [
            'ratingKey' => 'show-1',
            'type' => 'show',
            'title' => 'Test Show',
            'Guid' => [
                ['id' => 'imdb://tt123'],
            ],
            'guid' => 'imdb://tt123',
            'Genre' => [
                ['tag' => 'Drama'],
                ['tag' => 'Sci-Fi'],
            ],
        ];

        $metaAction = new class($show) {
            public function __construct(
                private array $payload,
            ) {}

            public int $calls = 0;

            public function __invoke(\App\Backends\Common\Context $context, string|int $id, array $opts = []): Response
            {
                $this->calls++;

                return new Response(
                    status: true,
                    response: ['MediaContainer' => ['Metadata' => [$this->payload]]],
                );
            }
        };

        Container::add(GetMetaData::class, fn() => $metaAction);

        $action = new Import($this->makeHttpClient(), $this->logger);
        $guid = new PlexGuid($this->logger)->withContext($context);

        $this->invokeProcessShow($action, $context, $guid, $show, []);
        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
            [],
        );

        $result = $mapper->commit();

        $this->assertSame(1, $result['episode']['added']);
        $this->assertSame(0, $metaAction->calls);
    }

    public function test_import_ignores_no_guids(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;
        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');
        $item['Guid'] = [];
        $item['guid'] = 'plex://local';

        $action = new Import($this->makeHttpClient(), $this->logger);
        $guid = new PlexGuid($this->logger)->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
            [],
        );

        $result = $mapper->commit();

        $this->assertSame(0, $result['movie']['added']);
    }

    private function invokeProcess(
        object $action,
        \App\Backends\Common\Context $context,
        \App\Backends\Common\GuidInterface $guid,
        \App\Libs\Mappers\ImportInterface $mapper,
        array $item,
        array $logContext,
        array $opts,
    ): void {
        $method = new ReflectionMethod($action, 'process');
        $method->invoke($action, $context, $guid, $mapper, $item, $logContext, $opts);
    }

    private function invokeProcessShow(
        object $action,
        \App\Backends\Common\Context $context,
        \App\Backends\Common\GuidInterface $guid,
        array $item,
        array $logContext,
    ): void {
        $method = new ReflectionMethod($action, 'processShow');
        $method->invoke($action, $context, $guid, $item, $logContext);
    }

    private function invokeHandle(
        object $action,
        \App\Backends\Common\Context $context,
        iResponse $response,
        callable $callback,
        array $logContext = [],
        ?iterable $chunks = null,
    ): void {
        $method = new ReflectionMethod($action, 'handle');
        $method->invoke(
            $action,
            $context,
            $response,
            $callback,
            $logContext
            + [
                'library' => ['title' => 'Movies'],
                'segment' => ['number' => 1, 'of' => 1],
                'identity' => ['user' => 'Plex', 'backend' => 'Plex'],
            ],
            $chunks,
        );
    }
}
