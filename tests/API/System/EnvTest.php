<?php

declare(strict_types=1);

namespace Tests\API\System;

use App\API\System\Env;
use App\Libs\Enums\Http\Status;
use App\Libs\TestCase;
use Tests\Support\RequestResponseTrait;

final class EnvTest extends TestCase
{
    use RequestResponseTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
    }

    public function test_logs_invalid(): void
    {
        $handler = new Env();

        $response = $handler->envUpdate(
            $this->getRequest(
                method: 'POST',
                post: ['value' => 'not-a-relative-time'],
                server: ['REQUEST_URI' => '/v1/api/system/env/WS_LOGS_PRUNE_AFTER'],
            ),
            'WS_LOGS_PRUNE_AFTER',
        );

        self::assertSame(Status::BAD_REQUEST->value, $response->getStatusCode());
        self::assertStringContainsString(
            'Value validation for',
            (string) $response->getBody(),
        );
    }

    public function test_backup_minimum(): void
    {
        $handler = new Env();

        $response = $handler->envUpdate(
            $this->getRequest(
                method: 'POST',
                post: ['value' => 7],
                server: ['REQUEST_URI' => '/v1/api/system/env/WS_BACKUP_PRUNE_AFTER'],
            ),
            'WS_BACKUP_PRUNE_AFTER',
        );

        self::assertSame(Status::BAD_REQUEST->value, $response->getStatusCode());
        self::assertStringContainsString(
            'Must be greater than 7 days.',
            (string) $response->getBody(),
        );
    }
}
