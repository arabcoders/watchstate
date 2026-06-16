<?php

declare(strict_types=1);

namespace Tests\Libs\Events;

use App\Libs\Container;
use App\Libs\Events\DataEvent;
use App\Libs\Options;
use App\Libs\TestCase;
use App\Model\Events\Event;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use Monolog\Level;

class DataEventTest extends TestCase
{
    protected function getDataEvent(array $data = []): DataEvent
    {
        if (count($data) < 1) {
            $data = [
                EventsTable::COLUMN_ID => generate_uuid(),
                EventsTable::COLUMN_STATUS => EventStatus::PENDING->value,
                EventsTable::COLUMN_REFERENCE => 'test',
                EventsTable::COLUMN_EVENT => 'test',
                EventsTable::COLUMN_EVENT_DATA => json_encode(['foo' => 'bar']),
                EventsTable::COLUMN_OPTIONS => json_encode(['timeout' => 60]),
                EventsTable::COLUMN_ATTEMPTS => 0,
                EventsTable::COLUMN_LOGS => json_encode(['test entry']),
                EventsTable::COLUMN_CREATED_AT => '2024-01-01 01:01:01',
                EventsTable::COLUMN_UPDATED_AT => '2024-01-02 02:02:02',
            ];
        }
        return new DataEvent(new Event($data));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->initContainer();
    }

    public function test_initial_state()
    {
        $dataEvent = $this->getDataEvent();

        $this->assertInstanceOf(DataEvent::class, $dataEvent, 'DataEvent is not instance of DataEvent');
        $this->assertInstanceOf(Event::class, $dataEvent->getEvent(), 'Event is not instance of Event');
        $this->assertSame('test', $dataEvent->getReference(), 'getReference() does not return the expected value');
        $this->assertSame(['test entry'], $dataEvent->getLogs(), 'getLogs() does not return the expected value');
        $this->assertSame(['foo' => 'bar'], $dataEvent->getData(), 'getData() does not return the expected value');
        $this->assertSame(['timeout' => 60], $dataEvent->getOptions(), 'getOptions() does not return the expected value');
    }

    public function test_logs_mutation()
    {
        $dataEvent = $this->getDataEvent();

        $this->assertSame(['test entry'], $dataEvent->getLogs(), 'getLogs() does not return the expected value');
        $dataEvent->addRawLog('new entry');

        self::assertCount(2, $dataEvent->getLogs());
        self::assertSame('test entry', $dataEvent->getLogs()[0]);
        self::assertSame('new entry', $dataEvent->getLogs()[1]);

        for ($i = 0; $i < 203; $i++) {
            $dataEvent->addRawLog('new entry');
        }

        $this->assertCount(200, $dataEvent->getLogs(), 'addLog() Logs should not exceed 200 entries per event.');
    }

    public function test_skip_debug(): void
    {
        $dataEvent = $this->getDataEvent([
            EventsTable::COLUMN_ID => generate_uuid(),
            EventsTable::COLUMN_STATUS => EventStatus::PENDING->value,
            EventsTable::COLUMN_REFERENCE => 'test',
            EventsTable::COLUMN_EVENT => 'test',
            EventsTable::COLUMN_EVENT_DATA => json_encode(['foo' => 'bar']),
            EventsTable::COLUMN_OPTIONS => json_encode([]),
            EventsTable::COLUMN_ATTEMPTS => 0,
            EventsTable::COLUMN_LOGS => json_encode([]),
            EventsTable::COLUMN_CREATED_AT => '2024-01-01 01:01:01',
            EventsTable::COLUMN_UPDATED_AT => '2024-01-02 02:02:02',
        ]);

        self::assertFalse($dataEvent->addLog(Level::Debug, 'hidden debug'));

        self::assertSame([], $dataEvent->getLogs());
    }

    public function test_allow_debug_trace(): void
    {
        $dataEvent = $this->getDataEvent([
            EventsTable::COLUMN_ID => generate_uuid(),
            EventsTable::COLUMN_STATUS => EventStatus::PENDING->value,
            EventsTable::COLUMN_REFERENCE => 'test',
            EventsTable::COLUMN_EVENT => 'test',
            EventsTable::COLUMN_EVENT_DATA => json_encode(['foo' => 'bar']),
            EventsTable::COLUMN_OPTIONS => json_encode([Options::DEBUG_TRACE => true]),
            EventsTable::COLUMN_ATTEMPTS => 0,
            EventsTable::COLUMN_LOGS => json_encode([]),
            EventsTable::COLUMN_CREATED_AT => '2024-01-01 01:01:01',
            EventsTable::COLUMN_UPDATED_AT => '2024-01-02 02:02:02',
        ]);

        self::assertTrue($dataEvent->addLog(Level::Debug, 'visible debug'));

        self::assertCount(1, $dataEvent->getLogs());
        self::assertStringContainsString('"message":"visible debug"', $dataEvent->getLogs()[0]);
    }

    public function test_visible_logs_flag(): void
    {
        $dataEvent = $this->getDataEvent([
            EventsTable::COLUMN_ID => generate_uuid(),
            EventsTable::COLUMN_STATUS => EventStatus::PENDING->value,
            EventsTable::COLUMN_REFERENCE => 'test',
            EventsTable::COLUMN_EVENT => 'test',
            EventsTable::COLUMN_EVENT_DATA => json_encode(['foo' => 'bar']),
            EventsTable::COLUMN_OPTIONS => json_encode([]),
            EventsTable::COLUMN_ATTEMPTS => 0,
            EventsTable::COLUMN_LOGS => json_encode([]),
            EventsTable::COLUMN_CREATED_AT => '2024-01-01 01:01:01',
            EventsTable::COLUMN_UPDATED_AT => '2024-01-02 02:02:02',
        ]);

        $dataEvent->setVisibleLevel(Level::Notice);
        self::assertFalse($dataEvent->hasVisibleLogs());

        $dataEvent->addLog(Level::Info, 'hidden info');
        self::assertFalse($dataEvent->hasVisibleLogs());

        $dataEvent->addLog(Level::Notice, 'visible notice');
        self::assertTrue($dataEvent->hasVisibleLogs());

        $dataEvent->resetVisibleLogs();
        self::assertFalse($dataEvent->hasVisibleLogs());
    }
}
