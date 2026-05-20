<?php

declare(strict_types=1);

namespace Tests\Listeners;

use App\Backends\Jellyfin\Action\GetSessions;
use App\Backends\Common\Response;
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
use App\Listeners\ProcessProgressEvent;
use App\Model\Events\Event;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use Psr\SimpleCache\CacheInterface as iCache;

final class ProcessProgressEventTest extends TestCase
{
    public function test_metadata_missing_event_log(): void
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
        assert($db instanceof iDB, 'Expected app database service for progress event test.');

        $entity = StateEntity::fromArray([
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_UPDATED => time(),
            iState::COLUMN_WATCHED => 0,
            iState::COLUMN_VIA => 'Other',
            iState::COLUMN_TITLE => 'Test Movie',
            iState::COLUMN_YEAR => 2024,
            iState::COLUMN_META_DATA => [
                'Other' => [
                    iState::COLUMN_META_DATA_PROGRESS => 120000,
                ],
            ],
            iState::COLUMN_EXTRA => [],
        ]);
        $entity = $db->insert($entity);

        Container::add(GetSessions::class, fn() => new class() {
            public function __invoke(): Response
            {
                return new Response(status: true, response: ['sessions' => []]);
            }
        });

        $cache = Container::get(iCache::class);
        assert($cache instanceof iCache, 'Expected cache service for progress event test.');

        $queue = Container::get(QueueRequests::class);
        assert($queue instanceof QueueRequests, 'Expected queue service for progress event test.');

        $listener = new ProcessProgressEvent(new DirectMapper($logger, $db, $cache), $logger, $queue);
        $event = $this->event($entity);

        $listener($event);

        self::assertSame(EventStatus::RUNNING, $event->getStatus());
        self::assertNotEmpty(
            array_filter(
                $event->getLogs(),
                static function (string $log): bool {
                    if (false === JsonlFormatter::isJsonlRecord($log)) {
                        return false;
                    }

                    $payload = json_decode($log, true, 512, JSON_THROW_ON_ERROR);

                    return 'warning' === ag($payload, 'level')
                        && str_contains((string) ag($payload, 'message', ''), 'No metadata was found.');
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
            EventsTable::COLUMN_EVENT => ProcessProgressEvent::NAME,
            EventsTable::COLUMN_EVENT_DATA => json_encode($entity->getAll()),
            EventsTable::COLUMN_OPTIONS => json_encode([]),
            EventsTable::COLUMN_ATTEMPTS => 1,
            EventsTable::COLUMN_LOGS => json_encode([]),
            EventsTable::COLUMN_CREATED_AT => '2024-01-01 00:00:00',
            EventsTable::COLUMN_UPDATED_AT => '2024-01-01 00:00:01',
        ]));
    }
}
