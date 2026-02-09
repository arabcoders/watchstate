<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

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
use ReflectionMethod;
use Symfony\Component\HttpClient\Response\MockResponse;

class ExportFlowTest extends MediaBrowserTestCase
{
    public function test_export_queues_requests(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName, [Options::IGNORE_DATE => true]);
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

            $this->assertSame(2, $queue->count());
        }
    }

    public function test_export_unplayed_queues_one_request(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName, [Options::IGNORE_DATE => true]);
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
        }
    }

    public function test_export_ignores_state_unchanged(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName, [Options::IGNORE_DATE => true]);
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
        }
    }

    public function test_export_ignores_date_is_newer(): void
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
        }
    }

    public function test_export_ignores_not_found_in_db(): void
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
        }
    }

    public function test_export_ignores_no_supported_guids(): void
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
