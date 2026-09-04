<?php

declare(strict_types=1);

namespace Tests\API\System;

use App\API\System\Scheduler;
use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use App\Libs\TestCase;

final class SchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->initTempDir();
        Config::save('worker.pid_file', self::$tmpPath . '/worker.pid');
    }

    public function test_status_down(): void
    {
        $response = new Scheduler()->status();
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(Status::OK->value, $response->getStatusCode());
        self::assertFalse(ag($payload, 'status'));
        self::assertSame('No worker PID file was found.', ag($payload, 'message'));
    }

    public function test_status_up(): void
    {
        file_put_contents((string) Config::get('worker.pid_file'), (string) getmypid());

        $response = new Scheduler()->status();
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(Status::OK->value, $response->getStatusCode());
        self::assertTrue(ag($payload, 'status'));
        self::assertSame((string) getmypid(), ag($payload, 'pid'));
    }
}
