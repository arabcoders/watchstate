<?php

declare(strict_types=1);

namespace Tests\Listeners;

use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\PackageMigrationFactory;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Events\DataEvent;
use App\Libs\Extends\JsonlFormatter;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\QueueRequests;
use App\Libs\TestCase;
use App\Listeners\ProcessPushEvent;
use App\Model\Events\Event;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use Psr\SimpleCache\CacheInterface as iCache;

final class ProcessPushEventTest extends TestCase
{
    public function test_missing_metadata_logs_to_event_only(): void
    {
        $this->initTempApp();
        $this->seedTestServersConfig();

        $logger = new Logger('test');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $pdo = Container::get(PDO::class);
        $migrations = new PackageMigrationFactory();
        if (false === $migrations->isMigrated($pdo)) {
            $migrations->migrate($pdo, dryRun: false);
        }
        ensure_indexes($pdo, $logger);

        $db = Container::get(iDB::class);
        assert($db instanceof iDB, 'Expected app database service for push event test.');

        $entity = StateEntity::fromArray([
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_UPDATED => time(),
            iState::COLUMN_WATCHED => 1,
            iState::COLUMN_VIA => 'test_jellyfin',
            iState::COLUMN_TITLE => 'Test Movie',
            iState::COLUMN_YEAR => 2024,
            iState::COLUMN_META_DATA => [],
        ]);
        $entity = $db->insert($entity);

        $cache = Container::get(iCache::class);
        assert($cache instanceof iCache, 'Expected cache service for push event test.');

        $queue = Container::get(QueueRequests::class);
        assert($queue instanceof QueueRequests, 'Expected queue service for push event test.');

        $listener = new ProcessPushEvent(new DirectMapper($logger, $db, $cache), $logger, $queue);
        $event = $this->event($entity);

        $listener($event);

        self::assertSame(EventStatus::RUNNING, $event->getStatus());
        self::assertNotEmpty(
            array_filter(
                $event->getLogs(),
                static function (string $log) use ($entity): bool {
                    if (false === JsonlFormatter::isJsonlRecord($log)) {
                        return false;
                    }

                    $payload = json_decode(trim($log), true);

                    if (!is_array($payload)) {
                        return false;
                    }

                    return 'warning' === ($payload['level'] ?? null)
                        && str_contains((string) ($payload['message'] ?? ''), "Ignoring '#{$entity->id}: {$entity->getName()}'")
                        && str_contains((string) ($payload['message'] ?? ''), 'No metadata was found.');
                },
            ),
        );
        self::assertFalse($handler->hasWarningRecords());
    }

    private function event(StateEntity $entity): DataEvent
    {
        return new DataEvent(new Event([
            EventsTable::COLUMN_ID => generate_uuid(),
            EventsTable::COLUMN_STATUS => EventStatus::RUNNING->value,
            EventsTable::COLUMN_EVENT => ProcessPushEvent::NAME,
            EventsTable::COLUMN_EVENT_DATA => json_encode($entity->getAll()),
            EventsTable::COLUMN_OPTIONS => json_encode([]),
            EventsTable::COLUMN_ATTEMPTS => 1,
            EventsTable::COLUMN_LOGS => json_encode([]),
            EventsTable::COLUMN_CREATED_AT => '2024-01-01 00:00:00',
            EventsTable::COLUMN_UPDATED_AT => '2024-01-01 00:00:01',
        ]));
    }
}
