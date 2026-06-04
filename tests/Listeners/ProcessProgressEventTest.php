<?php

declare(strict_types=1);

namespace Tests\Listeners;

use App\Backends\Common\Response;
use App\Backends\Jellyfin\Action\GetSessions;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\PackageMigrationFactory;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Events\DataEvent;
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
use Tests\Support\StateCommandTestSupport;

final class ProcessProgressEventTest extends TestCase
{
    use StateCommandTestSupport;

    public function test_logs_shared_missing_metadata(): void
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
                static fn(string $log): bool => str_contains($log, 'WARNING:') && str_contains($log, 'No metadata was found.'),
            ),
        );
        self::assertTrue($handler->hasWarningRecords());
    }

    public function test_skip_debug_backend_logs(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_progress'));
        $db = $this->migrateMainDb($logger);
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $entity = StateEntity::fromArray([
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_UPDATED => time(),
            iState::COLUMN_WATCHED => 0,
            iState::COLUMN_VIA => 'other',
            iState::COLUMN_TITLE => 'Fake Progress Movie',
            iState::COLUMN_YEAR => 2024,
            iState::COLUMN_GUIDS => ['imdb' => 'tt-fake-progress'],
            iState::COLUMN_META_DATA => [
                'other' => [
                    iState::COLUMN_META_DATA_PROGRESS => 120000,
                ],
                'fake_progress' => [
                    iState::COLUMN_ID => 202,
                    iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                    iState::COLUMN_WATCHED => 0,
                ],
            ],
            iState::COLUMN_EXTRA => [
                'other' => [
                    iState::COLUMN_EXTRA_DATE => (string) make_date(time()),
                ],
            ],
            iState::COLUMN_CREATED_AT => time(),
            iState::COLUMN_UPDATED_AT => time(),
        ]);
        $entity = $db->insert($entity);

        $cache = Container::get(iCache::class);
        assert($cache instanceof iCache, 'Expected cache service for progress event test.');

        $queue = Container::get(QueueRequests::class);
        assert($queue instanceof QueueRequests, 'Expected queue service for progress event test.');

        $listener = new ProcessProgressEvent(new DirectMapper($logger, $db, $cache), $logger, $queue);
        $event = $this->event($entity);

        $listener($event);

        self::assertSame(EventStatus::RUNNING, $event->getStatus());
        self::assertFalse(array_any(
            $event->getLogs(),
            static fn(string $log): bool => str_contains($log, 'fake.progress: hidden debug'),
        ));
        self::assertTrue(array_any(
            $event->getLogs(),
            static fn(string $log): bool => str_contains($log, 'fake.progress: visible info'),
        ));
        self::assertTrue($handler->hasDebugRecords());
        self::assertTrue($handler->hasInfoRecords());
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
