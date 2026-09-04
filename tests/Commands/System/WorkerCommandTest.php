<?php

declare(strict_types=1);

namespace Tests\Commands\System;

use App\Commands\System\WorkerCommand;
use App\Libs\Config;
use App\Libs\Console\ConsoleExecutionService;
use App\Libs\Console\ConsoleSessionService;
use App\Libs\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class WorkerCommandTest extends TestCase
{
    private ConsoleSessionService $sessions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initTempDir();
        mkdir(self::$tmpPath . '/console', 0o755, true);
        Config::save('tmpDir', self::$tmpPath);
        Config::save('worker.pid_file', self::$tmpPath . '/worker.pid');
        Config::save('console.enable.all', true);
        $this->sessions = new ConsoleSessionService(new NullLogger());
    }

    public function test_lock(): void
    {
        $lock = fopen((string) Config::get('worker.pid_file'), 'c+');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $tester = new CommandTester($this->command());
            self::assertSame(Command::SUCCESS, $tester->execute([]));
            self::assertStringContainsString('already running', $tester->getDisplay());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_token(): void
    {
        $token = $this->sessions->queue(
            ['command' => '$ printf unified-worker', 'pty' => false, 'timeout' => 5],
            make_date(strtotime('+5 minutes'))->format(DATE_ATOM),
        );

        $tester = new CommandTester($this->command());
        self::assertSame(Command::SUCCESS, $tester->execute(['--token' => $token]));
        self::assertSame('success', ag($this->sessions->getState($token), 'outcome'));
    }

    public function test_child_command(): void
    {
        $token = $this->sessions->queue(
            ['command' => '$ printf child-command', 'pty' => false, 'timeout' => 5],
            make_date(strtotime('+5 minutes'))->format(DATE_ATOM),
        );
        $method = new ReflectionMethod(WorkerCommand::class, 'startSessionChild');
        $process = $method->invoke($this->command(), $token);
        $path = realpath(__DIR__ . '/../../../');

        self::assertInstanceOf(Process::class, $process);
        self::assertIsString($path);
        self::assertSame($path, $process->getWorkingDirectory());
        self::assertTrue($process->isOutputDisabled());
        self::assertSame(
            escapeshellarg("{$path}/bin/console") . ' ' . escapeshellarg(WorkerCommand::ROUTE) . ' ' . escapeshellarg('--token') . ' '
                . escapeshellarg($token),
            $process->getCommandLine(),
        );

        self::assertSame(Command::SUCCESS, $process->wait());
    }

    private function command(): WorkerCommand
    {
        $logger = new NullLogger();
        return new WorkerCommand(
            $this->sessions,
            new ConsoleExecutionService($this->sessions, $logger),
            $logger,
        );
    }
}
