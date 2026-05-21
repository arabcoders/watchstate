<?php

declare(strict_types=1);

namespace Tests\Listeners;

use App\Libs\Config;
use App\Libs\Events\DataEvent;
use App\Libs\Extends\JsonlFormatter;
use App\Libs\Extends\MockHttpClient;
use App\Libs\TestCase;
use App\Listeners\ProcessProfileEvent;
use App\Model\Events\Event;
use App\Model\Events\EventStatus;
use App\Model\Events\EventsTable;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProcessProfileEventTest extends TestCase
{
    public function test_profile_export_disabled_logs_structured_event(): void
    {
        $this->initTempApp();
        Config::init(['profiler' => ['save' => false]]);

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $listener = new ProcessProfileEvent($logger, new MockHttpClient());
        $event = $this->event();

        $listener($event);

        self::assertCount(1, $event->getLogs());
        self::assertTrue(JsonlFormatter::isJsonlRecord($event->getLogs()[0]));

        $payload = json_decode(trim($event->getLogs()[0]), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('profile.export.disabled', ag($payload, 'fields.event_name'));
        self::assertSame('profile-1', ag($payload, 'fields.profile_id'));
        self::assertTrue($handler->hasInfoRecords());
    }

    public function test_profile_collector_unexpected_status_logs_structured_event(): void
    {
        $this->initTempApp();
        Config::init(['profiler' => ['save' => false, 'collector' => 'https://collector.test/ingest']]);

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $listener = new ProcessProfileEvent(
            $logger,
            new MockHttpClient(new MockResponse('fail', ['http_code' => 500])),
        );
        $event = $this->event();

        $listener($event);

        self::assertCount(1, $event->getLogs());
        $payload = json_decode(trim($event->getLogs()[0]), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('profile.collector.unexpected_status', ag($payload, 'fields.event_name'));
        self::assertSame(500, $payload['fields']['http.status_code'] ?? null);
        self::assertTrue($handler->hasErrorRecords());
    }

    private function event(): DataEvent
    {
        return new DataEvent(new Event([
            EventsTable::COLUMN_ID => generate_uuid(),
            EventsTable::COLUMN_STATUS => EventStatus::RUNNING->value,
            EventsTable::COLUMN_EVENT => ProcessProfileEvent::NAME,
            EventsTable::COLUMN_EVENT_DATA => json_encode([
                'meta' => [
                    'id' => 'profile-1',
                ],
                'data' => ['x' => 'y'],
            ]),
            EventsTable::COLUMN_OPTIONS => json_encode([]),
            EventsTable::COLUMN_ATTEMPTS => 1,
            EventsTable::COLUMN_LOGS => json_encode([]),
            EventsTable::COLUMN_CREATED_AT => '2024-01-01 00:00:00',
            EventsTable::COLUMN_UPDATED_AT => '2024-01-01 00:00:01',
        ]));
    }
}
