<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\Import;
use App\Backends\Plex\Action\GetMetaData;
use App\Backends\Plex\PlexGuid;
use App\Backends\Common\Response;
use App\Libs\Config;
use App\Libs\Container;
use Monolog\LogRecord;
use ReflectionMethod;
use Symfony\Component\HttpClient\Response\MockResponse;

class ImportFlowTest extends PlexTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // These tests exercise mocked failed responses directly; retries only add backoff delay.
        Config::save('http.default.maxRetries', 0);
    }

    public function test_process_adds(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;
        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');

        $action = new Import($this->makeHttpClient(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

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

    public function test_missing_date(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;
        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');
        unset($item['addedAt'], $item['lastViewedAt']);

        $action = new Import($this->makeHttpClient(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

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

    public function test_episode_adds(): void
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
            public function __construct(private array $payload)
            {
            }

            public function __invoke(\App\Backends\Common\Context $context, string|int $id, array $opts = []): Response
            {
                return new Response(status: true, response: $this->payload);
            }
        });

        $action = new Import($this->makeHttpClient(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

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

    public function test_prefetched_genres(): void
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
            public function __construct(private array $payload)
            {
            }

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
        $guid = (new PlexGuid($this->logger))->withContext($context);

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

    public function test_missing_guid(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;
        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');
        $item['Guid'] = [];
        $item['guid'] = 'plex://local';

        $action = new Import($this->makeHttpClient(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

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

    public function test_handle_status_failed(): void
    {
        $context = $this->makeContext();
        $http = $this->makeHttpClient(
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 500]),
        );
        $action = new Import($http, $this->logger);

        $this->invokeHandle(
            $action,
            $context,
            $this->makeHandleResponse($action, 'http://plex.test/library'),
            static function (array $item, array $logContext = []): void {
            },
            [
                'action' => 'plex.import',
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'user' => $context->userContext->name,
                'library' => ['title' => 'Movies'],
                'segment' => ['number' => 1, 'of' => 1],
            ],
        );

        $record = $this->lastRecord('backend.response.failed');
        $this->assertSame('backend.import', $record->context['subsystem'] ?? null);
        $this->assertSame('request_library', $record->context['operation'] ?? null);
        $this->assertSame('unexpected_status', $record->context['reason'] ?? null);
    }

    public function test_handle_item_failed(): void
    {
        $context = $this->makeContext();
        $http = $this->makeHttpClient(new MockResponse(json_encode([
            'MediaContainer' => [
                'Metadata' => [
                    ['ratingKey' => '1', 'type' => 'movie'],
                ],
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]));
        $action = new Import($http, $this->logger);

        $this->invokeHandle(
            $action,
            $context,
            $this->makeHandleResponse($action, 'http://plex.test/library'),
            static function (array $item, array $logContext = []): void {
                throw new \RuntimeException('boom');
            },
            [
                'action' => 'plex.import',
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'user' => $context->userContext->name,
                'library' => ['title' => 'Movies'],
                'segment' => ['number' => 1, 'of' => 1],
            ],
        );

        $record = $this->lastRecord('backend.operation.failed');
        $this->assertSame('backend.import', $record->context['subsystem'] ?? null);
        $this->assertSame('process_item', $record->context['operation'] ?? null);
    }

    public function test_handle_parse_failed(): void
    {
        $context = $this->makeContext();
        $http = $this->makeHttpClient(new MockResponse(
            '{"MediaContainer":{"Metadata":[{"ratingKey":"1"},{"ratingKey":}]}}',
            ['http_code' => 200],
        ));
        $action = new Import($http, $this->logger);

        $this->invokeHandle(
            $action,
            $context,
            $this->makeHandleResponse($action, 'http://plex.test/library'),
            static function (array $item, array $logContext = []): void {
            },
            [
                'action' => 'plex.import',
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'user' => $context->userContext->name,
                'library' => ['title' => 'Movies'],
                'segment' => ['number' => 1, 'of' => 1],
            ],
        );

        $record = $this->lastRecord('backend.operation.failed');
        $this->assertSame('backend.import', $record->context['subsystem'] ?? null);
        $this->assertSame('parse_library_response', $record->context['operation'] ?? null);
    }

    public function test_handle_completed(): void
    {
        $context = $this->makeContext();
        $http = $this->makeHttpClient(new MockResponse(json_encode([
            'MediaContainer' => [
                'Metadata' => [
                    ['ratingKey' => '1', 'type' => 'movie'],
                ],
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]));
        $action = new Import($http, $this->logger);
        $seen = [];

        $this->invokeHandle(
            $action,
            $context,
            $this->makeHandleResponse($action, 'http://plex.test/library'),
            static function (array $item, array $logContext = []) use (&$seen): void {
                $seen[] = $item['ratingKey'] ?? null;
            },
            [
                'action' => 'plex.import',
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'user' => $context->userContext->name,
                'library' => ['title' => 'Movies'],
                'segment' => ['number' => 1, 'of' => 1],
            ],
        );

        $this->assertSame(['1'], $seen);

        $records = array_values(array_filter(
            $this->handler->getRecords(),
            static fn(LogRecord $record): bool => 'backend.response.processing' === ($record->context['event_name'] ?? null),
        ));

        $this->assertCount(2, $records);
        $this->assertSame('started', $records[0]->context['outcome'] ?? null);
        $this->assertSame('completed', $records[1]->context['outcome'] ?? null);
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

    private function invokeHandle(
        object $action,
        \App\Backends\Common\Context $context,
        \Symfony\Contracts\HttpClient\ResponseInterface $response,
        \Closure $callback,
        array $logContext,
    ): void {
        $method = new ReflectionMethod($action, 'handle');
        $method->invoke($action, $context, $response, $callback, $logContext);
    }

    private function makeHandleResponse(object $action, string $url): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $property = new \ReflectionProperty($action, 'http');

        return $property->getValue($action)->request('GET', $url);
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

    private function lastRecord(string $eventName): LogRecord
    {
        $records = array_values(array_filter(
            $this->handler->getRecords(),
            static fn(LogRecord $record): bool => $eventName === ($record->context['event_name'] ?? null),
        ));

        $this->assertNotEmpty($records);

        return end($records);
    }
}
