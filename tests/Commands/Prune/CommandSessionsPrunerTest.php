<?php

declare(strict_types=1);

namespace Tests\Commands\Prune;

use App\Libs\Prune\CommandSessionsPruner;
use App\Libs\Attributes\Cli\Prune;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\TestCase;

final class CommandSessionsPrunerTest extends TestCase
{
    private string $tmpDir;
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = ROOT_PATH . '/var/tmp/watchstate_prune_' . uniqid('', true);
        @mkdir($this->tmpDir . '/console', 0o755, true);

        $this->originalConfig = Config::getAll();
        Config::init(array_replace_recursive(require ROOT_PATH . '/config/config.php', [
            'tmpDir' => $this->tmpDir,
            'path' => $this->tmpDir,
            'prune' => [
                'paths' => [
                    ROOT_PATH . '/src/Libs/Prune',
                ],
                'cache' => [
                    'time' => 0,
                ],
            ],
        ]));

        Container::reset();
        Container::init();
        foreach ((array) require ROOT_PATH . '/config/services.php' as $name => $definition) {
            Container::add($name, $definition);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        Config::init($this->originalConfig);
        Container::reset();
        parent::tearDown();
    }

    public function test_discover(): void
    {
        $pruners = discover_pruners();

        self::assertArrayHasKey('command_sessions', $pruners);
        self::assertSame('command_sessions', $pruners['command_sessions']['name']);
        self::assertSame('Remove expired command sessions.', $pruners['command_sessions']['desc']);
    }

    public function test_attr(): void
    {
        $reflection = new \ReflectionClass(CommandSessionsPruner::class);
        $attrs = $reflection->getAttributes(Prune::class);

        self::assertCount(1, $attrs);
        $attribute = $attrs[0]->newInstance();
        self::assertSame('Command Sessions', $attribute->name);
        self::assertSame('*/5 * * * *', $attribute->cron);
    }

    public function test_exec_done(): void
    {
        $expired = $this->createSession('expired', [
            'status' => 'completed',
            'connections' => 0,
            'finished_at' => make_date(strtotime('-2 days'))->format(DATE_ATOM),
        ]);
        $recent = $this->createSession('recent', [
            'status' => 'completed',
            'connections' => 0,
            'finished_at' => make_date(strtotime('-2 hours'))->format(DATE_ATOM),
        ]);
        $running = $this->createSession('running', [
            'status' => 'running',
            'connections' => 1,
            'finished_at' => null,
        ]);

        (new CommandSessionsPruner())->__invoke(true);

        self::assertFalse(is_dir($expired));
        self::assertTrue(is_dir($recent));
        self::assertTrue(is_dir($running));
    }

    public function test_exec_queue(): void
    {
        $expired = $this->createSession('expired-queued', [
            'status' => 'queued',
            'expires_at' => make_date(strtotime('-10 minutes'))->format(DATE_ATOM),
        ]);
        $fresh = $this->createSession('fresh-queued', [
            'status' => 'queued',
            'expires_at' => make_date(strtotime('+10 minutes'))->format(DATE_ATOM),
        ]);

        (new CommandSessionsPruner())->__invoke(true);

        self::assertFalse(is_dir($expired));
        self::assertTrue(is_dir($fresh));
    }

    public function test_dry_done(): void
    {
        $expired = $this->createSession('expired', [
            'status' => 'completed',
            'connections' => 0,
            'finished_at' => make_date(strtotime('-2 days'))->format(DATE_ATOM),
        ]);

        (new CommandSessionsPruner())->__invoke(false);

        self::assertTrue(is_dir($expired));
    }

    public function test_dry_queue(): void
    {
        $expired = $this->createSession('expired-queued', [
            'status' => 'queued',
            'expires_at' => make_date(strtotime('-10 minutes'))->format(DATE_ATOM),
        ]);

        (new CommandSessionsPruner())->__invoke(false);

        self::assertTrue(is_dir($expired));
    }

    public function test_lock(): void
    {
        $expired = $this->createSession('expired', [
            'status' => 'completed',
            'connections' => 0,
            'finished_at' => make_date(strtotime('-2 days'))->format(DATE_ATOM),
        ]);
        $writerLockPath = $expired . '/writer.lock';

        $lockHandle = fopen($writerLockPath, 'c+');
        self::assertIsResource($lockHandle);
        self::assertTrue(flock($lockHandle, LOCK_EX | LOCK_NB));

        (new CommandSessionsPruner())->__invoke(true);

        self::assertTrue(is_dir($expired));

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);

        (new CommandSessionsPruner())->__invoke(true);

        self::assertFalse(is_dir($expired));
    }

    private function createSession(string $name, array $state): string
    {
        $path = $this->tmpDir . '/console/' . $name;
        mkdir($path, 0o755, true);

        file_put_contents($path . '/request.json', json_encode([
            'command' => 'system:tasks',
        ], JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE));

        file_put_contents($path . '/state.json', json_encode(array_replace([
            'status' => 'queued',
            'command' => 'system:tasks',
            'cwd' => null,
            'created_at' => make_date()->format(DATE_ATOM),
            'expires_at' => make_date()->format(DATE_ATOM),
            'updated_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'exit_code' => null,
            'last_sequence' => 0,
            'connection_seq' => 0,
            'active_connection' => 0,
            'connections' => 0,
        ], $state), JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE));

        touch($path . '/stream.log');

        return $path;
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
