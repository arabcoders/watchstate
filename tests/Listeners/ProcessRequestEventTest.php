<?php

declare(strict_types=1);

namespace Tests\Listeners;

use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Events\DataEvent;
use App\Libs\Exceptions\DBLayerException;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Libs\TestCase;
use App\Listeners\ProcessRequestEvent;
use App\Model\Events\Event;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use Monolog\Logger;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class ProcessRequestEventTest extends TestCase
{
    public function test_lock_pending(): void
    {
        $this->initTempApp();
        $this->seedTestServersConfig();

        $logger = new Logger('test');
        $cache = new Psr16Cache(new ArrayAdapter());
        $db = $this->createDb($logger);

        Container::add(iDB::class, $db);
        Container::add(CacheInterface::class, $cache);

        $state = new StateEntity(require TESTS_PATH . '/Fixtures/MovieEntity.php');

        $mapper = new class($logger, $db, $cache) extends DirectMapper {
            public function add(iState $entity, array $opts = []): self
            {
                if (true === (bool) ag($opts, Options::FAIL_FAST_ON_LOCK, false)) {
                    throw new DBLayerException('database is locked');
                }

                return parent::add($entity, $opts);
            }
        };

        $listener = new ProcessRequestEvent($mapper, $logger);

        $event = new DataEvent(new Event([
            EventsTable::COLUMN_ID => generate_uuid(),
            EventsTable::COLUMN_STATUS => EventStatus::RUNNING->value,
            EventsTable::COLUMN_EVENT => ProcessRequestEvent::NAME,
            EventsTable::COLUMN_EVENT_DATA => json_encode($state->getAll()),
            EventsTable::COLUMN_OPTIONS => json_encode([
                Options::CONTEXT_USER => 'main',
                Options::FAIL_FAST_ON_LOCK => true,
            ]),
            EventsTable::COLUMN_ATTEMPTS => 1,
            EventsTable::COLUMN_LOGS => json_encode([]),
            EventsTable::COLUMN_CREATED_AT => '2024-01-01 00:00:00',
            EventsTable::COLUMN_UPDATED_AT => '2024-01-01 00:00:01',
        ]));

        $listener($event);

        self::assertSame(EventStatus::PENDING, $event->getStatus());
        self::assertArrayNotHasKey(Options::DELAY_BY, $event->getEvent()->options);
        self::assertStringContainsString('Re-queuing event.', implode("\n", $event->getLogs()));
    }
}
