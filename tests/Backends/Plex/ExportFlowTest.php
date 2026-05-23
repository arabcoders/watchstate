<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Common\Request;
use App\Backends\Plex\Action\Export;
use App\Backends\Plex\PlexGuid;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use App\Libs\Options;
use App\Libs\QueueRequests;
use Monolog\LogRecord;
use ReflectionMethod;
use Symfony\Component\HttpClient\Response\MockResponse;

class ExportFlowTest extends PlexTestCase
{
    public function test_export_queues_requests(): void
    {
        $context = $this->makeContext([Options::IGNORE_DATE => true]);
        $queue = new QueueRequests();

        $localEntity = $this->makeLocalEntity($context, watched: 1, updated: 2000);
        $mapper = $this->buildMapper($context, $localEntity);

        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');

        $action = new Export($this->makeQueueHttp(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
            ['queue' => $queue],
        );

        $this->assertSame(1, $queue->count());
        $this->assertContainsOnlyInstancesOf(Request::class, $queue->getQueue());

        $request = $queue->getQueue()[0];
        ($request->success)(new MockResponse('', ['http_code' => 200]));

        $records = $this->handler?->getRecords() ?? [];
        $completed = array_filter(
            $records,
            static fn(LogRecord $record): bool => 'backend.state_update.completed' === ($record->context['event_name'] ?? null)
                && 'backend.export' === ($record->context['subsystem'] ?? null)
                && 'update_state' === ($record->context['operation'] ?? null),
        );

        $this->assertNotEmpty($completed);

        $record = end($completed);
        $this->assertSame((string) make_date(2000), $record->context['local_time'] ?? null);
        $this->assertSame((string) make_date($item['addedAt']), $record->context['remote_time'] ?? null);
        $this->assertSame(2000 - $item['addedAt'], $record->context['diff_time'] ?? null);
    }

    public function test_export_ignores_state_unchanged(): void
    {
        $context = $this->makeContext([
            Options::IGNORE_DATE => true,
            Options::DEBUG_TRACE => true,
        ]);
        $queue = new QueueRequests();

        $localEntity = $this->makeLocalEntity($context, watched: 1, updated: 2000);
        $mapper = $this->buildMapper($context, $localEntity);

        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');
        $item['viewCount'] = 1;
        $item['lastViewedAt'] = 2000;

        $action = new Export($this->makeQueueHttp(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
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
        $this->assertSame('1', $record->context['item']['remote_id'] ?? null);
    }

    public function test_export_ignores_newer(): void
    {
        $context = $this->makeContext();
        $queue = new QueueRequests();

        $localEntity = $this->makeLocalEntity($context, watched: 0, updated: 1000);
        $mapper = $this->buildMapper($context, $localEntity);

        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');
        $item['viewCount'] = 1;
        $item['lastViewedAt'] = 3000;

        $action = new Export($this->makeQueueHttp(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
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
        $this->assertSame('1', $record->context['item']['remote_id'] ?? null);
    }

    public function test_export_ignores_not_found(): void
    {
        $context = $this->makeContext([Options::IGNORE_DATE => true]);
        $queue = new QueueRequests();

        $mapper = $this->buildMapper($context, null);
        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');

        $action = new Export($this->makeQueueHttp(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
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
        $this->assertSame('1', $record->context['item']['remote_id'] ?? null);
    }

    public function test_export_ignores_no_guids(): void
    {
        $context = $this->makeContext([Options::IGNORE_DATE => true]);
        $queue = new QueueRequests();

        $localEntity = $this->makeLocalEntity($context, watched: 0, updated: 2000);
        $mapper = $this->buildMapper($context, $localEntity);

        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');
        $item['Guid'] = [];

        $action = new Export($this->makeQueueHttp(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
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
        $this->assertSame('1', $record->context['item']['remote_id'] ?? null);
    }

    public function test_export_ignores_missing_date(): void
    {
        $context = $this->makeContext([Options::IGNORE_DATE => true]);
        $queue = new QueueRequests();

        $localEntity = $this->makeLocalEntity($context, watched: 0, updated: 2000);
        $mapper = $this->buildMapper($context, $localEntity);

        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');
        unset($item['addedAt'], $item['lastViewedAt']);

        $action = new Export($this->makeQueueHttp(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
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
        $this->assertSame('1', $record->context['item']['remote_id'] ?? null);
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
            iState::COLUMN_TITLE => 'Ferengi: Rules of Acquisition',
            iState::COLUMN_META_DATA => [
                $context->backendName => [
                    iState::COLUMN_ID => '1',
                    iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                    iState::COLUMN_WATCHED => (string) $watched,
                    iState::COLUMN_TITLE => 'Ferengi: Rules of Acquisition',
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
}
