<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Common\Request;
use App\Backends\Emby\Action\Export as EmbyExport;
use App\Backends\Emby\EmbyGuid;
use App\Backends\Jellyfin\Action\Export as JellyfinExport;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use App\Libs\Options;
use App\Libs\QueueRequests;
use Monolog\LogRecord;
use ReflectionMethod;
use Symfony\Component\HttpClient\Response\MockResponse;

class ExportFlowTest extends MediaBrowserTestCase
{
    public function test_export_queues_requests(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName, [
                Options::IGNORE_DATE => true,
                Options::DEBUG_TRACE => true,
            ]);
            $queue = new QueueRequests();

            $localEntity = $this->makeLocalEntity($context, watched: 1, updated: 2000);
            $mapper = $this->buildMapper($context, $localEntity);

            $item = $this->fixture('metadata');

            $http = new HttpClient(new MockHttpClient(
                fn(string $method, string $url, array $options) => new MockResponse('', [
                    'http_code' => 200,
                    'user_data' => $options['user_data'] ?? null,
                ]),
            ));
            $action = new $actionClass($http, $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                ['queue' => $queue],
            );

            $this->assertSame(1, $queue->count());
            $this->assertContainsOnlyInstancesOf(Request::class, $queue->getQueue());

            $request = $queue->getQueue()[0];
            $this->assertSame('POST', $request->method->value);
            $this->assertStringContainsString('/Users/user-1/PlayedItems/item-1', (string) $request->url);

            $followUps = ($request->success)(new MockResponse('', ['http_code' => 200]));
            $this->assertCount(1, $followUps);
            $this->assertContainsOnlyInstancesOf(Request::class, $followUps);
            $this->assertStringContainsString('/Users/user-1/Items/item-1/UserData', (string) $followUps[0]->url);
        }
    }

    public function test_export_unplayed_queues(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName, [
                Options::IGNORE_DATE => true,
                Options::DEBUG_TRACE => true,
            ]);
            $queue = new QueueRequests();

            $localEntity = $this->makeLocalEntity($context, watched: 0, updated: 2000);
            $mapper = $this->buildMapper($context, $localEntity);

            $item = $this->fixture('metadata');
            $item['UserData']['Played'] = true;
            $item['UserData']['LastPlayedDate'] = '2024-01-02T00:00:00Z';

            $http = $this->makeQueueHttp();
            $action = new $actionClass($http, $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                ['queue' => $queue],
            );

            $this->assertSame(1, $queue->count());
            $this->assertContainsOnlyInstancesOf(Request::class, $queue->getQueue());
        }
    }

    public function test_export_ignores_state_unchanged(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName, [
                Options::IGNORE_DATE => true,
                Options::DEBUG_TRACE => true,
            ]);
            $queue = new QueueRequests();

            $localEntity = $this->makeLocalEntity($context, watched: 1, updated: 2000);
            $mapper = $this->buildMapper($context, $localEntity);

            $item = $this->fixture('metadata');
            $item['UserData']['Played'] = true;
            $item['UserData']['LastPlayedDate'] = '2024-01-02T00:00:00Z';

            $action = new $actionClass($this->makeQueueHttp(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                ['queue' => $queue],
            );

            $this->assertSame(0, $queue->count());

            $records = array_values(array_filter(
                $this->handler->getRecords(),
                static fn(LogRecord $record): bool => 'backend.item.ignored' === ($record->context['event_name'] ?? null),
            ));

            $this->assertNotEmpty($records);
            $record = end($records);
            $this->assertSame('state_unchanged', $record->context['reason'] ?? null);
            $this->assertSame('backend.export', $record->context['subsystem'] ?? null);
        }
    }

    public function test_export_ignores_newer(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName);
            $queue = new QueueRequests();

            $localEntity = $this->makeLocalEntity($context, watched: 0, updated: 1000);
            $mapper = $this->buildMapper($context, $localEntity);

            $item = $this->fixture('metadata');
            $item['UserData']['Played'] = true;
            $item['UserData']['LastPlayedDate'] = '2024-01-02T00:00:00Z';
            $item['DateCreated'] = '2024-01-02T00:00:00Z';

            $action = new $actionClass($this->makeQueueHttp(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                ['queue' => $queue],
            );

            $this->assertSame(0, $queue->count());

            $records = array_values(array_filter(
                $this->handler->getRecords(),
                static fn(LogRecord $record): bool => 'backend.item.ignored' === ($record->context['event_name'] ?? null),
            ));

            $this->assertNotEmpty($records);
            $record = end($records);
            $this->assertSame('date_not_newer_than_local_history', $record->context['reason'] ?? null);
            $this->assertSame('backend.export', $record->context['subsystem'] ?? null);
        }
    }

    public function test_export_ignores_not_found(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName, [Options::IGNORE_DATE => true]);
            $queue = new QueueRequests();

            $mapper = $this->buildMapper($context, null);
            $item = $this->fixture('metadata');
            $item['UserData']['Played'] = true;
            $item['UserData']['LastPlayedDate'] = '2024-01-02T00:00:00Z';

            $action = new $actionClass($this->makeQueueHttp(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                ['queue' => $queue],
            );

            $this->assertSame(0, $queue->count());

            $records = array_values(array_filter(
                $this->handler->getRecords(),
                static fn(LogRecord $record): bool => 'backend.item.ignored' === ($record->context['event_name'] ?? null),
            ));

            $this->assertNotEmpty($records);
            $record = end($records);
            $this->assertSame('missing_local_state', $record->context['reason'] ?? null);
            $this->assertSame('backend.export', $record->context['subsystem'] ?? null);
        }
    }

    public function test_export_ignores_no_guids(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName, [Options::IGNORE_DATE => true]);
            $queue = new QueueRequests();

            $localEntity = $this->makeLocalEntity($context, watched: 0, updated: 2000);
            $mapper = $this->buildMapper($context, $localEntity);

            $item = $this->fixture('metadata');
            $item['ProviderIds'] = [];
            $item['UserData']['Played'] = false;

            $action = new $actionClass($this->makeQueueHttp(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                ['queue' => $queue],
            );

            $this->assertSame(0, $queue->count());

            $records = array_values(array_filter(
                $this->handler->getRecords(),
                static fn(LogRecord $record): bool => 'backend.item.ignored' === ($record->context['event_name'] ?? null),
            ));

            $this->assertNotEmpty($records);
            $record = end($records);
            $this->assertSame('missing_supported_guid', $record->context['reason'] ?? null);
            $this->assertSame('backend.export', $record->context['subsystem'] ?? null);
        }
    }

    public function test_export_ignores_missing_date(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName, [Options::IGNORE_DATE => true]);
            $queue = new QueueRequests();

            $localEntity = $this->makeLocalEntity($context, watched: 0, updated: 2000);
            $mapper = $this->buildMapper($context, $localEntity);

            $item = $this->fixture('metadata');
            unset($item['DateCreated']);

            $action = new $actionClass($this->makeQueueHttp(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                ['queue' => $queue],
            );

            $this->assertSame(0, $queue->count());

            $records = array_values(array_filter(
                $this->handler->getRecords(),
                static fn(LogRecord $record): bool => 'backend.item.ignored' === ($record->context['event_name'] ?? null),
            ));

            $this->assertNotEmpty($records);
            $record = end($records);
            $this->assertSame('missing_date', $record->context['reason'] ?? null);
            $this->assertSame('backend.export', $record->context['subsystem'] ?? null);
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

    private function makeQueueHttp(): HttpClient
    {
        return new HttpClient(new MockHttpClient(
            fn(string $method, string $url, array $options) => new MockResponse('', [
                'http_code' => 200,
                'user_data' => $options['user_data'] ?? null,
            ]),
        ));
    }

    private function makeLocalEntity(\App\Backends\Common\Context $context, int $watched, int $updated): iState
    {
        return StateEntity::fromArray([
            iState::COLUMN_ID => 10,
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_UPDATED => $updated,
            iState::COLUMN_WATCHED => $watched,
            iState::COLUMN_VIA => $context->backendName,
            iState::COLUMN_TITLE => 'Test Movie',
            iState::COLUMN_META_DATA => [
                $context->backendName => [
                    iState::COLUMN_ID => 'item-1',
                    iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                    iState::COLUMN_WATCHED => (string) $watched,
                    iState::COLUMN_TITLE => 'Test Movie',
                ],
            ],
        ]);
    }

    private function buildMapper(\App\Backends\Common\Context $context, ?iState $entity): \App\Libs\Mappers\ImportInterface
    {
        return new class($this->logger, $context->userContext->db, $context->userContext->cache, $entity) extends \App\Libs\Mappers\Import\DirectMapper {
            public function __construct($logger, $db, $cache, private ?iState $entity)
            {
                parent::__construct($logger, $db, $cache);
            }

            public function get(iState $entity): ?iState
            {
                return $this->entity;
            }
        };
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinExport::class, JellyfinGuid::class],
            ['Emby', EmbyExport::class, EmbyGuid::class],
        ];
    }
}
