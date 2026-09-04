<?php

declare(strict_types=1);

namespace Tests\Libs\Console;

use App\Libs\Config;
use App\Libs\Console\ConsoleExecutionService;
use App\Libs\Console\ConsoleSessionService;
use App\Libs\TestCase;
use Psr\Log\NullLogger;

final class ConsoleSessionServiceTest extends TestCase
{
    private ConsoleSessionService $sessions;

    private mixed $previousConsoleAll;

    private mixed $previousTmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initTempDir();

        $this->previousConsoleAll = Config::get('console.enable.all', null);
        $this->previousTmpDir = Config::get('tmpDir', null);
        Config::save('console.enable.all', true);
        Config::save('tmpDir', self::$tmpPath);
        mkdir(self::$tmpPath . '/console', 0o755, true);

        $this->sessions = new ConsoleSessionService(new NullLogger());
    }

    protected function tearDown(): void
    {
        if (null === $this->previousConsoleAll) {
            Config::remove('console.enable.all');
        } else {
            Config::save('console.enable.all', $this->previousConsoleAll);
        }

        if (null === $this->previousTmpDir) {
            Config::remove('tmpDir');
        } else {
            Config::save('tmpDir', $this->previousTmpDir);
        }

        parent::tearDown();
    }

    public function test_claim_once(): void
    {
        $token = $this->queue('$ printf claimed');
        $lock = $this->sessions->claim($token);

        self::assertIsResource($lock);
        self::assertNull($this->sessions->claim($token));

        $this->sessions->releaseLock($lock);
    }

    public function test_execute_output(): void
    {
        $token = $this->queue('$ printf worker-output');
        $lock = $this->sessions->claim($token);
        self::assertIsResource($lock);

        $execution = new ConsoleExecutionService($this->sessions, new NullLogger());
        self::assertSame(0, $execution->execute($token, $lock));

        $state = $this->sessions->getState($token);
        self::assertSame('completed', ag($state, 'status'));
        self::assertSame('success', ag($state, 'outcome'));
        self::assertSame(0, ag($state, 'exit_code'));
        self::assertStringContainsString('worker-output', (string) file_get_contents($this->path($token) . '/stream.log'));
    }

    public function test_execute_timeout(): void
    {
        $token = $this->sessions->queue(
            ['command' => '$ sleep 5', 'pty' => false, 'timeout' => 1],
            make_date(strtotime('+5 minutes'))->format(DATE_ATOM),
        );
        $lock = $this->sessions->claim($token);
        self::assertIsResource($lock);

        $execution = new ConsoleExecutionService($this->sessions, new NullLogger());
        self::assertSame(124, $execution->execute($token, $lock));

        $state = $this->sessions->getState($token);
        self::assertSame('completed', ag($state, 'status'));
        self::assertSame('timed_out', ag($state, 'outcome'));
        self::assertSame(124, ag($state, 'exit_code'));
    }

    public function test_cancel_claimed(): void
    {
        $token = $this->queue('$ sleep 5');
        $lock = $this->sessions->claim($token);
        self::assertIsResource($lock);
        self::assertSame('Command cancellation requested.', $this->sessions->cancel($token));

        $execution = new ConsoleExecutionService($this->sessions, new NullLogger());
        self::assertNotSame(0, $execution->execute($token, $lock));

        $state = $this->sessions->getState($token);
        self::assertSame('completed', ag($state, 'status'));
        self::assertSame('cancelled', ag($state, 'outcome'));
    }

    public function test_cancel_queue(): void
    {
        $token = $this->queue('$ printf cancelled');

        self::assertSame('Command cancellation completed.', $this->sessions->cancel($token));
        self::assertSame('cancelled', ag($this->sessions->getState($token), 'outcome'));
        self::assertFileExists($this->path($token) . '/stream.log');
    }

    public function test_recover_legacy(): void
    {
        $token = $this->queue('$ printf stale');
        $statePath = $this->path($token) . '/state.json';
        $state = json_decode((string) file_get_contents($statePath), true);
        $state['status'] = 'running';
        $state['started_at'] = make_date(strtotime('-1 hour'))->format(DATE_ATOM);
        file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE));

        self::assertTrue($this->sessions->recover($token));

        $state = $this->sessions->getState($token);
        self::assertSame('completed', ag($state, 'status'));
        self::assertSame('worker_lost', ag($state, 'outcome'));
        self::assertStringContainsString('worker stopped', (string) file_get_contents($this->path($token) . '/stream.log'));
    }

    public function test_append_failure(): void
    {
        $token = $this->queue('$ printf broken');
        $streamPath = $this->path($token) . '/stream.log';
        unlink($streamPath);
        mkdir($streamPath);

        self::assertFalse($this->sessions->append($token, 'data', 'broken'));
        self::assertSame(0, ag($this->sessions->getState($token), 'last_sequence'));
    }

    private function queue(string $command): string
    {
        return $this->sessions->queue(
            ['command' => $command, 'pty' => false, 'timeout' => 5],
            make_date(strtotime('+5 minutes'))->format(DATE_ATOM),
        );
    }

    private function path(string $token): string
    {
        return self::$tmpPath . '/console/' . $token;
    }
}
