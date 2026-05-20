<?php

declare(strict_types=1);

namespace Tests\API\System;

use App\API\System\Events;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\TestCase;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventStatus;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

final class EventsTest extends TestCase
{
    private EventsRepository $repo;

    private PDOAdapter $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
        $this->db = $this->createDb(new Logger('test', [new NullHandler()]));
        $this->repo = new EventsRepository($this->db->getDBLayer());
    }

    public function test_read_returns_raw_logs(): void
    {
        $event = $this->repo->getObject([]);
        $event->event = 'system.test';
        $event->status = EventStatus::FAILED;
        $event->reference = 'test://raw-logs';
        $event->event_data = ['ok' => true];
        $event->logs = [
            '{"id":"one","datetime":"2026-05-20T12:00:00.123+00:00","level":"info","logger":"event","message":"first"}',
            'NOTICE: legacy second',
        ];
        $event->created_at = make_date('2026-05-20T12:00:00+00:00');
        $event->updated_at = make_date('2026-05-20T12:05:00+00:00');

        $id = $this->repo->save($event);

        $response = (new Events($this->repo))->read($id);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($id, ag($payload, 'id'));
        self::assertSame($event->logs, ag($payload, 'logs'));
        self::assertIsString(ag($payload, 'logs.0'));
        self::assertSame('Failed', ag($payload, 'status_name'));
    }
}
