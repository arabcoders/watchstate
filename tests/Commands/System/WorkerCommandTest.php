<?php

declare(strict_types=1);

namespace Tests\Commands\System;

use App\Commands\System\TasksCommand;
use App\Commands\System\WorkerCommand;
use App\Libs\Config;
use App\Libs\Console\ConsoleExecutionService;
use App\Libs\Console\ConsoleSessionService;
use App\Libs\LogSuppressor;
use App\Libs\TestCase;
use App\Model\Events\EventsRepository;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
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
        $state = $this->sessions->getState($token);
        self::assertIsArray($state);
        self::assertSame('success', ag($state, 'outcome'));
    }

    public function test_incomplete_session(): void
    {
        $token = hash('sha256', 'incomplete-session');
        mkdir(self::$tmpPath . '/console/' . $token);

        $path = realpath(__DIR__ . '/../../../');
        self::assertIsString($path);
        $process = new Process(
            command: ["{$path}/bin/console", WorkerCommand::ROUTE],
            cwd: $path,
            env: [
                'WS_CACHE_NULL' => '1',
                'WS_TMP_DIR' => self::$tmpPath,
                'WS_WORKER_PID_FILE' => self::$tmpPath . '/worker-process.pid',
            ],
            timeout: 5,
        );

        try {
            $process->start();
            self::assertTrue($process->waitUntil(
                static fn(string $type, string $output): bool => str_contains($output, 'Worker started and waiting for tasks.'),
            ));
            usleep(500_000);
            self::assertTrue($process->isRunning(), $process->getErrorOutput());
        } finally {
            $process->stop(1, 9);
        }
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

        $now = time();
        $since = $now - (14 * 60);
        $timer = make_date($now - (2 * 60))->format('i G') . ' * * *';
        Config::save('tasks.list', [
            'missed' => ['name' => 'missed', 'command' => 'test:missed', 'enabled' => true, 'timer' => $timer],
            'prune' => [
                'name' => 'prune',
                'command' => 'system:prune',
                'enabled' => true,
                'timer' => make_date($now - (10 * 60))->format('i') . ',' . make_date($now - (5 * 60))->format('i') . ' * * * *',
            ],
            'later' => [
                'name' => 'later',
                'command' => 'test:later',
                'enabled' => true,
                'timer' => make_date($now + (20 * 60))->format('i G') . ' * * *',
            ],
        ]);
        $cache = new Psr16Cache(new ArrayAdapter());
        $cache->set('tasks.schedule', ['missed' => $since, 'prune' => $since]);
        $scheduler = new class(
            $this->createStub(EventsRepository::class),
            new LogSuppressor([]),
            $cache,
            new Logger('test', [new NullHandler()]),
        ) extends TasksCommand {
            /** @var array<array<string,mixed>> */
            public array $calls = [];

            protected function runTask(array $task, InputInterface $input, OutputInterface $output): int
            {
                $this->calls[] = $task;

                return self::FAILURE;
            }
        };
        $tester = new CommandTester($scheduler);

        self::assertSame(Command::SUCCESS, $tester->execute(['--run' => true]));
        self::assertSame(['missed', 'prune'], array_column($scheduler->calls, 'name'));
        $until = $scheduler->calls[0]['schedule']['to'];
        self::assertSame(['from' => $since, 'to' => $until], $scheduler->calls[1]['schedule']);
        self::assertGreaterThanOrEqual($now, $until);
        self::assertLessThanOrEqual(time(), $until);
        $checkpoints = $cache->get('tasks.schedule');
        self::assertSame($until, $checkpoints['missed']);
        self::assertSame($until, $checkpoints['prune']);
        self::assertIsInt($checkpoints['later']);

        self::assertSame(Command::SUCCESS, $tester->execute(['--run' => true]));
        self::assertCount(2, $scheduler->calls);
        self::assertSame(Command::SUCCESS, $tester->execute(['--run' => true, '--task' => 'missed']));
        self::assertCount(3, $scheduler->calls);
        self::assertArrayNotHasKey('schedule', $scheduler->calls[2]);
        self::assertSame($checkpoints, $cache->get('tasks.schedule'));

        $runProcess = new ReflectionMethod(TasksCommand::class, 'runProcess');
        $input = new ArrayInput([], $scheduler->getDefinition());
        $output = new BufferedOutput();
        $cmd = [
            escapeshellarg(PHP_BINARY),
            '-r',
            escapeshellarg('echo getenv("WS_TASK_SCHEDULE_FROM"), ":", getenv("WS_TASK_SCHEDULE_TO");'),
            '--',
        ];
        self::assertSame(Command::SUCCESS, $runProcess->invoke($scheduler, $cmd, $input, $output, $scheduler->calls[1]));
        self::assertSame($since . ':' . $until . PHP_EOL, $output->fetch());
        self::assertSame(Command::SUCCESS, $runProcess->invoke($scheduler, $cmd, $input, $output));
        self::assertSame(':' . PHP_EOL, $output->fetch());
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
