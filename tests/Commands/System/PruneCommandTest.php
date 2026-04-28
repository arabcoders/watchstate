<?php

declare(strict_types=1);

namespace Tests\Commands\System;

use App\Commands\System\PruneCommand;
use App\Libs\Config;
use App\Libs\Database\DBLayer;
use App\Libs\TestCase;
use Monolog\Logger;
use PDO;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PruneCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/watchstate_prune_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);
        mkdir($this->tmpDir . '/console', 0o755, true);

        Config::init([
            'tmpDir' => $this->tmpDir,
            'path' => $this->tmpDir,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        Config::reset();
        parent::tearDown();
    }

    public function test_prune_removes_completed_console_sessions_older_than_retention(): void
    {
        $expired = $this->createConsoleSession('expired', [
            'status' => 'completed',
            'connections' => 0,
            'finished_at' => make_date(strtotime('-2 days'))->format(DATE_ATOM),
        ]);
        $recent = $this->createConsoleSession('recent', [
            'status' => 'completed',
            'connections' => 0,
            'finished_at' => make_date(strtotime('-2 hours'))->format(DATE_ATOM),
        ]);
        $running = $this->createConsoleSession('running', [
            'status' => 'running',
            'connections' => 1,
            'finished_at' => null,
        ]);

        $tester = $this->makeTester();
        $status = $tester->execute([]);

        self::assertSame(PruneCommand::SUCCESS, $status);
        self::assertFalse(is_dir($expired));
        self::assertTrue(is_dir($recent));
        self::assertTrue(is_dir($running));
    }

    public function test_prune_dry_run_keeps_completed_console_sessions_older_than_retention(): void
    {
        $expired = $this->createConsoleSession('expired', [
            'status' => 'completed',
            'connections' => 0,
            'finished_at' => make_date(strtotime('-2 days'))->format(DATE_ATOM),
        ]);

        $tester = $this->makeTester();
        $status = $tester->execute(['--dry-run' => true]);

        self::assertSame(PruneCommand::SUCCESS, $status);
        self::assertTrue(is_dir($expired));
    }

    private function makeTester(): CommandTester
    {
        $application = new Application();
        $db = new DBLayer(new PDO('sqlite::memory:'));
        $db->exec('CREATE TABLE events (id INTEGER PRIMARY KEY, created_at TEXT)');
        $db->exec('CREATE TABLE playlists (id INTEGER PRIMARY KEY, deleted_at INTEGER NULL)');
        $application->addCommand(new PruneCommand(new Logger('test'), $db));

        return new CommandTester($application->find(PruneCommand::ROUTE));
    }

    private function createConsoleSession(string $name, array $state): string
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
