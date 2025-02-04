<?php

declare(strict_types=1);

namespace Tests\Libs\Events;

use App\Libs\Container;
use App\Libs\Events\DataEvent;
use App\Libs\TestCase;
use App\Model\Events\Event;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;

class DataEventTest extends TestCase
{
    protected function getDataEvent(array $data = []): DataEvent
    {
        if (count($data) < 1) {
            $data = [
                EventsTable::COLUMN_ID => generateUUID(),
                EventsTable::COLUMN_STATUS => EventStatus::PENDING->value,
                EventsTable::COLUMN_REFERENCE => 'test',
                EventsTable::COLUMN_EVENT => 'test',
                EventsTable::COLUMN_EVENT_DATA => json_encode(['foo' => 'bar']),
                EventsTable::COLUMN_OPTIONS => json_encode(['timeout' => 60,]),
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
        Container::init();
        foreach ((array)require __DIR__ . '/../../../config/services.php' as $name => $definition) {
            Container::add($name, $definition);
        }
    }

    public function __destruct()
    {
        Container::reset();
    }

    public function test_initial_state()
    {
        $dataEvent = $this->getDataEvent();

        $this->assertInstanceOf(DataEvent::class, $dataEvent, 'DataEvent is not instance of DataEvent');
        $this->assertInstanceOf(Event::class, $dataEvent->getEvent(), 'Event is not instance of Event');
        $this->assertSame('test', $dataEvent->getReference(), 'getReference() does not return the expected value');
        $this->assertSame(['test entry'], $dataEvent->getLogs(), 'getLogs() does not return the expected value');
        $this->assertSame(['foo' => 'bar'], $dataEvent->getData(), 'getData() does not return the expected value');
        $this->assertSame(['timeout' => 60],
            $dataEvent->getOptions(),
            'getOptions() does not return the expected value');
    }

    public function test_logs_mutation()
    {
        $dataEvent = $this->getDataEvent();

        $this->assertSame(['test entry'], $dataEvent->getLogs(), 'getLogs() does not return the expected value');
        $dataEvent->addLog('new entry');

        $this->assertSame(['test entry', 'new entry'],
            $dataEvent->getLogs(),
            'addLog() does not return the expected value');

        for ($i = 0; $i < 203; $i++) {
            $dataEvent->addLog('new entry');
        }

        $this->assertCount(200, $dataEvent->getLogs(), 'addLog() Logs should not exceed 200 entries per event.');
    }
}
