<?php

declare(strict_types=1);

namespace Tests\Libs\Events;

use App\Libs\Container;
use App\Libs\Events\DataEvent;
use App\Libs\Extends\JsonlFormatter;
use App\Libs\Options;
use App\Libs\TestCase;
use App\Model\Events\Event;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;

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
        $this->initContainer();
    }

    public function test_initial_state(): void
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

    public function test_logs_mutation(): void
    {
        $dataEvent = $this->getDataEvent();

        $this->assertSame(['test entry'], $dataEvent->getLogs(), 'getLogs() does not return the expected value');
        $dataEvent->addLog('new entry');

        $logs = $dataEvent->getLogs();

        $this->assertCount(2, $logs, 'addLog() should append a new entry.');
        $this->assertSame('test entry', $logs[0], 'Existing legacy entries should remain unchanged.');
        $this->assertTrue(JsonlFormatter::isJsonlRecord($logs[1]), 'New log entries should be normalized to JSONL.');

        for ($i = 0; $i < 203; $i++) {
            $dataEvent->addLog('new entry');
        }

        $this->assertCount(200, $dataEvent->getLogs(), 'addLog() Logs should not exceed 200 entries per event.');
    }

    public function test_log_string_jsonl(): void
    {
        $dataEvent = $this->getDataEvent();
        $dataEvent->clearLogs();

        $dataEvent->addLog('NOTICE: Listener visible');

        $logs = $dataEvent->getLogs();

        $this->assertCount(1, $logs);
        $this->assertTrue(JsonlFormatter::isJsonlRecord($logs[0]));

        $record = json_decode(trim($logs[0]), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('notice', ag($record, 'level'));
        $this->assertSame('NOTICE: Listener visible', ag($record, 'message'));
        $this->assertSame((string) $dataEvent->getEvent()->id, ag($record, 'fields.event_id'));
        $this->assertSame($dataEvent->getEvent()->event, ag($record, 'fields.event'));
    }

    public function test_log_jsonl_keep(): void
    {
        $dataEvent = $this->getDataEvent();
        $dataEvent->clearLogs();

        $line = (new JsonlFormatter())->formatValues(
            channel: 'event',
            level: Level::Info,
            message: 'already structured',
            context: ['event_id' => (string) $dataEvent->getEvent()->id],
        );

        $dataEvent->addLog($line);

        $this->assertSame($line, $dataEvent->getLogs()[0]);
    }

    public function test_debug_entry_skip(): void
    {
        $dataEvent = $this->getDataEvent();
        $dataEvent->clearLogs();

        $dataEvent->addLogEntry(Level::Debug, 'hidden debug');

        $this->assertSame([], $dataEvent->getLogs());
    }

    public function test_debug_entry_trace(): void
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

        $dataEvent->addLogEntry(Level::Debug, 'visible debug');

        $logs = $dataEvent->getLogs();

        $this->assertCount(1, $logs);
        $payload = json_decode(trim($logs[0]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('debug', ag($payload, 'level'));
        $this->assertSame('visible debug', ag($payload, 'message'));
    }

    public function test_debug_record_skip(): void
    {
        $dataEvent = $this->getDataEvent();
        $dataEvent->clearLogs();

        $dataEvent->addLog(
            'DEBUG: hidden debug',
            new LogRecord(
                datetime: new DateTimeImmutable('2026-05-20T12:00:00+00:00'),
                channel: 'event',
                level: Level::Debug,
                message: 'hidden debug',
                context: [],
            ),
        );

        $this->assertSame([], $dataEvent->getLogs());
    }

    public function test_info_record_keep(): void
    {
        $dataEvent = $this->getDataEvent();
        $dataEvent->clearLogs();

        $dataEvent->addLog(
            'INFO: visible info',
            new LogRecord(
                datetime: new DateTimeImmutable('2026-05-20T12:00:00+00:00'),
                channel: 'event',
                level: Level::Info,
                message: 'visible info',
                context: [],
            ),
        );

        $logs = $dataEvent->getLogs();

        $this->assertCount(1, $logs);
        $payload = json_decode(trim($logs[0]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('info', ag($payload, 'level'));
        $this->assertSame('visible info', ag($payload, 'message'));
    }
}
