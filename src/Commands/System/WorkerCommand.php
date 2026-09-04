<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Console\ConsoleExecutionService;
use App\Libs\Console\ConsoleSessionService;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

#[Cli(command: self::ROUTE)]
final class WorkerCommand extends Command
{
    public const string ROUTE = 'system:worker';

    private const int DEFAULT_CONCURRENCY = 4;
    private const int SCHEDULER_INTERVAL_SECONDS = 60;
    private const int SCHEDULER_TIMEOUT_SECONDS = 28_800;

    public function __construct(
        private readonly ConsoleSessionService $sessions,
        private readonly ConsoleExecutionService $execution,
        private readonly iLogger $logger,
    ) {
        set_time_limit(0);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Run one claimed session and exit.')
            ->addOption(
                'concurrency',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum simultaneous sessions.',
                (string) self::DEFAULT_CONCURRENCY,
            )
            ->setDescription('Run scheduled tasks and queued web-console sessions.');
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $token = $input->getOption('token');
        if (is_string($token) && '' !== trim($token)) {
            $writerLock = $this->sessions->claim($token);
            return is_resource($writerLock)
                ? $this->execution->execute($token, $writerLock)
                : self::SUCCESS;
        }

        $pidFile = (string) Config::get('worker.pid_file');
        $supervisorLock = @fopen($pidFile, 'c+');
        if (false === $supervisorLock || false === flock($supervisorLock, LOCK_EX | LOCK_NB)) {
            if (is_resource($supervisorLock)) {
                fclose($supervisorLock);
            }
            $output->writeln('The worker is already running.');
            return self::SUCCESS;
        }

        $sessionChildren = [];
        $schedulerChild = null;
        $nextTaskRun = microtime(true) + self::SCHEDULER_INTERVAL_SECONDS;
        $concurrency = max(1, (int) $input->getOption('concurrency'));

        try {
            $workerPid = (int) getmypid();
            ftruncate($supervisorLock, 0);
            fwrite($supervisorLock, (string) $workerPid);
            fflush($supervisorLock);
            $output->writeln(r('[{pid}] Worker started and waiting for tasks.', ['pid' => $workerPid]));

            while (true) {
                foreach ($sessionChildren as $sessionToken => $child) {
                    if ($child->isRunning()) {
                        continue;
                    }

                    $output->writeln(r(
                        "[{pid}] Console job '{session}' finished with exit code '{exit_code}'.",
                        [
                            'pid' => $workerPid,
                            'session' => substr(hash('sha256', $sessionToken), 0, 12),
                            'exit_code' => $child->getExitCode() ?? self::FAILURE,
                        ],
                    ), iOutput::VERBOSITY_VERBOSE);
                    unset($sessionChildren[$sessionToken]);
                }

                if ($schedulerChild instanceof Process) {
                    if (false === $schedulerChild->isRunning()) {
                        $exitCode = $schedulerChild->getExitCode() ?? self::FAILURE;
                        if (self::SUCCESS !== $exitCode) {
                            $this->logger->error("Scheduled task scan failed with exit code '{process.exit_code}'.", [
                                'operation' => 'scheduler.run',
                                'error' => 'command_failure',
                                'process' => ['exit_code' => $exitCode],
                            ]);
                        } else {
                            $output->writeln(r(
                                '[{pid}] Scheduled task scan finished successfully.',
                                ['pid' => $workerPid],
                            ), iOutput::VERBOSITY_VERY_VERBOSE);
                        }
                        $schedulerChild = null;
                        $nextTaskRun = microtime(true) + self::SCHEDULER_INTERVAL_SECONDS;
                    } else {
                        try {
                            $schedulerChild->checkTimeout();
                        } catch (ProcessTimedOutException $e) {
                            $this->logger->error("Scheduled task scan timed out after '{timeout_seconds}s'.", [
                                'operation' => 'scheduler.run',
                                'error' => 'timed_out',
                                'timeout_seconds' => self::SCHEDULER_TIMEOUT_SECONDS,
                                ...exception_log($e),
                            ]);
                            $schedulerChild = null;
                            $nextTaskRun = microtime(true) + self::SCHEDULER_INTERVAL_SECONDS;
                        }
                    }
                } elseif (microtime(true) >= $nextTaskRun) {
                    try {
                        $output->writeln(
                            r('[{pid}] Checking for scheduled tasks.', ['pid' => $workerPid]),
                            iOutput::VERBOSITY_VERY_VERBOSE,
                        );
                        $schedulerChild = $this->startSchedulerChild();
                    } catch (Throwable $e) {
                        $this->logger->error('Unable to start scheduled task scan: {exception.message}', [
                            'operation' => 'scheduler.run',
                            'error' => 'process_start_failed',
                            ...exception_log($e),
                        ]);
                        $nextTaskRun = microtime(true) + self::SCHEDULER_INTERVAL_SECONDS;
                    }
                }

                foreach ($this->sessions->getTokens() as $sessionToken) {
                    $state = $this->sessions->getState($sessionToken);
                    if ('running' === ag($state, 'status')) {
                        $this->sessions->recover($sessionToken);
                        continue;
                    }

                    if (
                        count($sessionChildren) >= $concurrency
                        || 'queued' !== ag($state, 'status')
                        || isset($sessionChildren[$sessionToken])
                    ) {
                        continue;
                    }

                    $child = $this->startSessionChild($sessionToken);
                    $sessionChildren[$sessionToken] = $child;
                    $output->writeln(r(
                        "[{pid}] Picked up console job '{session}' with child PID '{child_pid}'.",
                        [
                            'pid' => $workerPid,
                            'session' => substr(hash('sha256', $sessionToken), 0, 12),
                            'child_pid' => $child->getPid() ?? 0,
                        ],
                    ), iOutput::VERBOSITY_VERBOSE);
                }

                usleep(200_000);
            }
        } catch (Throwable $e) {
            $this->logger->error('Worker stopped unexpectedly: {exception.message}', [
                'operation' => 'worker.run',
                'error' => 'worker_failed',
                ...exception_log($e),
            ]);
            return self::FAILURE;
        } finally {
            if ($schedulerChild instanceof Process && $schedulerChild->isRunning()) {
                $schedulerChild->stop(1, 9);
            }
            $runningChildren = array_filter($sessionChildren, static fn(Process $child): bool => $child->isRunning());
            foreach ($runningChildren as $child) {
                $child->stop(1, 9);
            }
            flock($supervisorLock, LOCK_UN);
            fclose($supervisorLock);
            @unlink($pidFile);
        }
    }

    private function startSessionChild(#[\SensitiveParameter] string $token): Process
    {
        $binary = realpath(__DIR__ . '/../../../bin/console');
        if (false === $binary) {
            throw new \RuntimeException('Unable to resolve the console executable.');
        }

        $process = new Process(
            command: [PHP_BINARY, $binary, self::ROUTE, '--token', $token],
            timeout: null,
        );
        $process->disableOutput();
        $process->start();
        return $process;
    }

    private function startSchedulerChild(): Process
    {
        $binary = realpath(__DIR__ . '/../../../bin/console');
        if (false === $binary) {
            throw new \RuntimeException('Unable to resolve the console executable.');
        }

        $process = new Process(
            command: [PHP_BINARY, $binary, TasksCommand::ROUTE, '--run', '--save-log'],
            timeout: self::SCHEDULER_TIMEOUT_SECONDS,
        );
        $process->disableOutput();
        $process->start();
        return $process;
    }
}
