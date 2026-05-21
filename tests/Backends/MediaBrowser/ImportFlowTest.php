<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Common\Request;
use App\Backends\Common\Response;
use App\Backends\Emby\Action\GetMetaData as EmbyGetMetaData;
use App\Backends\Emby\Action\Import as EmbyImport;
use App\Backends\Emby\EmbyGuid;
use App\Backends\Jellyfin\Action\GetMetaData as JellyfinGetMetaData;
use App\Backends\Jellyfin\Action\Import as JellyfinImport;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Options;
use Monolog\LogRecord;
use ReflectionMethod;
use Symfony\Component\HttpClient\Response\MockResponse;

class ImportFlowTest extends MediaBrowserTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // These tests exercise mocked failed responses directly; retries only add backoff delay.
        Config::save('http.default.maxRetries', 0);
    }

    public function test_process_adds(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName);
            $mapper = $context->userContext->mapper;
            $item = $this->fixture('metadata');
            $item['UserData']['PlaybackPositionTicks'] = 0;

            $action = new $actionClass($this->makeHttpClient(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                [],
            );

            $result = $mapper->commit();

            $this->assertSame(1, $result['movie']['added']);
        }
    }

    public function test_missing_date(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName);
            $mapper = $context->userContext->mapper;
            $item = $this->fixture('metadata');
            unset($item['DateCreated']);
            $item['UserData']['PlaybackPositionTicks'] = 0;

            $action = new $actionClass($this->makeHttpClient(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                [],
            );

            $result = $mapper->commit();

            $this->assertSame(0, $result['movie']['added']);
        }
    }

    public function test_show_cache(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName);
            $item = [
                'Id' => 'series-1',
                'Name' => 'Test Show',
                'Type' => 'Series',
                'ProductionYear' => 2020,
                'ProviderIds' => [
                    'Imdb' => 'tt123',
                ],
            ];

            $action = new $actionClass($this->makeHttpClient(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcessShow($action, $context, $guid, $item, []);

            $cacheKey = 'Series.' . $item['Id'];
            $this->assertNotSame([], $context->cache->get($cacheKey, []));
        }
    }

    public function test_missing_guid(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName);
            $mapper = $context->userContext->mapper;
            $item = $this->fixture('metadata');
            $item['ProviderIds'] = [];
            $item['UserData']['PlaybackPositionTicks'] = 0;

            $action = new $actionClass($this->makeHttpClient(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                [],
            );

            $result = $mapper->commit();

            $this->assertSame(0, $result['movie']['added']);
        }
    }

    public function test_episode_adds(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass, $metaClass]) {
            $context = $this->makeContext($clientName);
            $mapper = $context->userContext->mapper;

            $item = $this->fixture('metadata_episode');
            $item['SeriesId'] = 'series-1';
            $item['UserData']['Played'] = false;
            $item['UserData']['PlaybackPositionTicks'] = 0;

            $showPayload = [
                'Id' => 'series-1',
                'Name' => 'Test Show',
                'Type' => 'Series',
                'ProductionYear' => 2020,
                'ProviderIds' => ['Imdb' => 'tt123'],
            ];

            Container::add($metaClass, fn() => new class($showPayload) {
                public function __construct(private array $payload)
                {
                }

                public function __invoke(\App\Backends\Common\Context $context, string|int $id, array $opts = []): Response
                {
                    return new Response(status: true, response: $this->payload);
                }
            });

            $action = new $actionClass($this->makeHttpClient(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-2']],
                [],
            );

            $result = $mapper->commit();

            $this->assertSame(1, $result['episode']['added']);
        }
    }

    public function test_prefetched_genres(): void
    {
        $context = $this->makeContext('Jellyfin');
        $mapper = $context->userContext->mapper;
        $item = $this->fixture('metadata_episode');
        $item['SeriesId'] = 'series-1';
        $item['SeriesName'] = 'Test Show';
        $item['UserData']['Played'] = false;
        $item['UserData']['PlaybackPositionTicks'] = 0;

        $show = [
            'Id' => 'series-1',
            'Name' => 'Test Show',
            'Type' => 'Series',
            'ProductionYear' => 2020,
            'ProviderIds' => ['Imdb' => 'tt123'],
            'Genres' => ['Drama', 'Sci-Fi'],
        ];

        $metaAction = new class($show) {
            public function __construct(private array $payload)
            {
            }

            public int $calls = 0;

            public function __invoke(\App\Backends\Common\Context $context, string|int $id, array $opts = []): Response
            {
                $this->calls++;

                return new Response(status: true, response: $this->payload);
            }
        };

        Container::add(JellyfinGetMetaData::class, fn() => $metaAction);

        $action = new JellyfinImport($this->makeHttpClient(), $this->logger);
        $guid = (new JellyfinGuid($this->logger))->withContext($context);

        $this->invokeProcessShow($action, $context, $guid, $show, []);
        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 'lib-2']],
            [],
        );

        $result = $mapper->commit();

        $this->assertSame(1, $result['episode']['added']);
        $this->assertSame(0, $metaAction->calls);
    }

    public function test_prefetch_parent(): void
    {
        $context = $this->makeContext('Jellyfin', [
            Options::LIBRARY_SELECT => ['lib-2'],
        ]);

        $action = new JellyfinImport(
            $this->makeHttpClient(
                $this->makeResponse($this->fixture('libraries')),
                $this->makeResponse(['TotalRecordCount' => 1, 'Items' => []]),
            ),
            $this->logger,
        );
        $guid = (new JellyfinGuid($this->logger))->withContext($context);
        $mapper = $context->userContext->mapper;

        $result = $action($context, $guid, $mapper);

        $this->assertTrue($result->isSuccessful());

        $prefetchRequests = array_values(array_filter(
            $result->response,
            static fn($request) => $request instanceof Request
                && str_contains((string) $request->url, 'recursive=false')
                && str_contains((string) $request->url, 'includeItemTypes=Series'),
        ));

        $this->assertNotEmpty($prefetchRequests);
        $this->assertFalse(str_contains((string) $prefetchRequests[0]->url, 'filters=IsNotFolder'));
    }

    public function test_invalid_type(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName);
            $mapper = $context->userContext->mapper;
            $item = $this->fixture('metadata');
            $item['Type'] = 'Audio';

            $action = new $actionClass($this->makeHttpClient(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                [],
            );

            $result = $mapper->commit();

            $this->assertSame(0, $result['movie']['added']);
        }
    }

    public function test_handle_status_failed(): void
    {
        foreach ($this->provideBackends() as $backend) {
            [$clientName, $actionClass] = $backend;

            $context = $this->makeContext($clientName);
            $http = $this->makeHttpClient(
                new MockResponse('', ['http_code' => 500]),
                new MockResponse('', ['http_code' => 500]),
                new MockResponse('', ['http_code' => 500]),
                new MockResponse('', ['http_code' => 500]),
            );
            $action = new $actionClass($http, $this->logger);

            $this->invokeHandle(
                $action,
                $context,
                $this->makeHandleResponse($action, 'http://mediabrowser.test/library'),
                static function (array $item, array $logContext = []): void {
                },
                [
                    'action' => 'Emby' === $clientName ? 'emby.import' : 'jellyfin.import',
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'user' => $context->userContext->name,
                    'library' => ['title' => 'Shows'],
                    'segment' => ['number' => 1, 'of' => 1],
                ],
            );

            $record = $this->lastRecord('backend.response.failed');
            $this->assertSame('backend.import', $record->context['subsystem'] ?? null);
            $this->assertSame('request_library', $record->context['operation'] ?? null);
            $this->assertSame('unexpected_status', $record->context['reason'] ?? null);
        }
    }

    public function test_handle_item_failed(): void
    {
        foreach ($this->provideBackends() as $backend) {
            [$clientName, $actionClass] = $backend;

            $context = $this->makeContext($clientName);
            $http = $this->makeHttpClient(new MockResponse(json_encode([
                'Items' => [
                    ['Id' => 'item-1', 'Type' => 'Movie'],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]));
            $action = new $actionClass($http, $this->logger);

            $this->invokeHandle(
                $action,
                $context,
                $this->makeHandleResponse($action, 'http://mediabrowser.test/library'),
                static function (array $item, array $logContext = []): void {
                    throw new \RuntimeException('boom');
                },
                [
                    'action' => 'Emby' === $clientName ? 'emby.import' : 'jellyfin.import',
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'user' => $context->userContext->name,
                    'library' => ['title' => 'Shows'],
                    'segment' => ['number' => 1, 'of' => 1],
                ],
            );

            $record = $this->lastRecord('backend.operation.failed');
            $this->assertSame('backend.import', $record->context['subsystem'] ?? null);
            $this->assertSame('process_item', $record->context['operation'] ?? null);
        }
    }

    public function test_handle_parse_failed(): void
    {
        foreach ($this->provideBackends() as $backend) {
            [$clientName, $actionClass] = $backend;

            $context = $this->makeContext($clientName);
            $http = $this->makeHttpClient(new MockResponse(
                '{"Items":[{"Id":"item-1"},{"Id":}]}',
                ['http_code' => 200],
            ));
            $action = new $actionClass($http, $this->logger);

            $this->invokeHandle(
                $action,
                $context,
                $this->makeHandleResponse($action, 'http://mediabrowser.test/library'),
                static function (array $item, array $logContext = []): void {
                },
                [
                    'action' => 'Emby' === $clientName ? 'emby.import' : 'jellyfin.import',
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'user' => $context->userContext->name,
                    'library' => ['title' => 'Shows'],
                    'segment' => ['number' => 1, 'of' => 1],
                ],
            );

            $record = $this->lastRecord('backend.operation.failed');
            $this->assertSame('backend.import', $record->context['subsystem'] ?? null);
            $this->assertSame('parse_library_response', $record->context['operation'] ?? null);
        }
    }

    public function test_handle_completed(): void
    {
        foreach ($this->provideBackends() as $backend) {
            [$clientName, $actionClass] = $backend;

            $context = $this->makeContext($clientName);
            $http = $this->makeHttpClient(new MockResponse(json_encode([
                'Items' => [
                    ['Id' => 'item-1', 'Type' => 'Movie'],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]));
            $action = new $actionClass($http, $this->logger);
            $seen = [];

            $this->invokeHandle(
                $action,
                $context,
                $this->makeHandleResponse($action, 'http://mediabrowser.test/library'),
                static function (array $item, array $logContext = []) use (&$seen): void {
                    $seen[] = $item['Id'] ?? null;
                },
                [
                    'action' => 'Emby' === $clientName ? 'emby.import' : 'jellyfin.import',
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'user' => $context->userContext->name,
                    'library' => ['title' => 'Shows'],
                    'segment' => ['number' => 1, 'of' => 1],
                ],
            );

            $this->assertSame(['item-1'], $seen);

            $records = array_values(array_filter(
                $this->handler->getRecords(),
                static fn(LogRecord $record): bool => 'backend.response.processing' === ($record->context['event_name'] ?? null),
            ));
            $records = array_slice($records, -2);

            $this->assertCount(2, $records);
            $this->assertSame('started', $records[0]->context['outcome'] ?? null);
            $this->assertSame('completed', $records[1]->context['outcome'] ?? null);
        }
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

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinImport::class, JellyfinGuid::class, JellyfinGetMetaData::class],
            ['Emby', EmbyImport::class, EmbyGuid::class, EmbyGetMetaData::class],
        ];
    }
}
