<?php

declare(strict_types=1);

namespace Tests\API\System;

use App\API\System\Env;
use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use App\Libs\TestCase;
use Tests\Support\RequestResponseTrait;

final class EnvTest extends TestCase
{
    use RequestResponseTrait;

    private array $originalConfig = [];
    private string $dataPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConfig = Config::getAll();
        $this->dataPath = ROOT_PATH . '/var/tmp/watchstate_env_' . uniqid('', true);
        @mkdir($this->dataPath . '/config', 0o755, true);

        Config::init(array_replace_recursive(require ROOT_PATH . '/config/config.php', [
            'path' => $this->dataPath,
            'tmpDir' => $this->dataPath,
        ]));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataPath);
        Config::init($this->originalConfig);
        parent::tearDown();
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

    public function test_logs_future(): void
    {
        $handler = new Env();

        $response = $handler->envUpdate(
            $this->getRequest(
                method: 'POST',
                post: ['value' => '+30 DAYS'],
                server: ['REQUEST_URI' => '/v1/api/system/env/WS_LOGS_PRUNE_AFTER'],
            ),
            'WS_LOGS_PRUNE_AFTER',
        );

        self::assertSame(Status::BAD_REQUEST->value, $response->getStatusCode());
        self::assertStringContainsString(
            'It must resolve to a time in the past.',
            (string) $response->getBody(),
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new \DirectoryIterator($path) as $item) {
            if ($item->isDot()) {
                continue;
            }

            $itemPath = $item->getRealPath();
            if (false === $itemPath) {
                continue;
            }

            if ($item->isDir()) {
                $this->removeDirectory($itemPath);
                continue;
            }

            @unlink($itemPath);
        }

        @rmdir($path);
    }
}
