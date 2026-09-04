<?php

declare(strict_types=1);

namespace App\Libs\Console;

use App\Libs\Config;
use App\Libs\Shlex;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final class ConsoleExecutionService
{
    private const int DEFAULT_TIMEOUT_SECONDS = 7_200;
    private const int POLL_INTERVAL_MICROSECONDS = 200_000;
    private const int HEARTBEAT_AFTER_ITERATIONS = 5;

    public function __construct(
        private readonly ConsoleSessionService $sessions,
        private readonly iLogger $logger,
    ) {}

    /** @param resource $writerLock */
    public function execute(#[\SensitiveParameter] string $token, mixed $writerLock): int
    {
        $process = null;
        $started = false;

        try {
            $request = $this->sessions->getRequest($token);
            $command = ag($request ?? [], 'command');
            if (!is_array($request) || !is_string($command) || '' === trim($command)) {
                $this->sessions->complete($token, 1, 'process_start_failed', 'No command was given.');
                return 1;
            }

            $cwd = ag($request, 'cwd', Config::get('path', getcwd(...)));
            if (!is_string($cwd) || '' === trim($cwd)) {
                $cwd = (string) Config::get('path', getcwd(...));
            }

            $timeout = ag($request, 'timeout', self::DEFAULT_TIMEOUT_SECONDS);
            $timeoutSeconds = is_numeric($timeout) ? max(1, (int) $timeout) : self::DEFAULT_TIMEOUT_SECONDS;
            $commandParts = $this->getCommand($command);
            $this->sessions->markRunning($token, $cwd, $timeoutSeconds);

            if (
                false === $this->sessions->append($token, 'cmd', (string) json_encode($commandParts))
                || false === $this->sessions->append($token, 'cwd', $cwd)
            ) {
                $this->sessions->complete($token, 1, 'storage_failure', 'Unable to persist command metadata.');
                return 1;
            }

            $storageFailed = false;
            $process = new Process(
                command: $commandParts,
                cwd: $cwd,
                env: $this->getEnvironment($request, $cwd),
                timeout: $timeoutSeconds,
            );
            if (false !== ag($request, 'pty', true)) {
                $process->setPty(true);
            }

            $process->start(function (string $type, string $data) use ($token, &$storageFailed): void {
                $payload = json_encode(['data' => $data, 'type' => $type], JSON_INVALID_UTF8_IGNORE);
                if (!is_string($payload) || false === $this->sessions->append($token, 'data', $payload)) {
                    $storageFailed = true;
                }
            });
            $started = true;
            $storageFailed = false === $this->sessions->heartbeat($token, $process->getPid());

            $counter = self::HEARTBEAT_AFTER_ITERATIONS;
            while ($process->isRunning()) {
                $process->checkTimeout();

                if ($storageFailed) {
                    $process->stop(1, 9);
                    break;
                }

                if ($this->sessions->isCancellationRequested($token)) {
                    $process->stop(1, 9);
                    break;
                }

                usleep(self::POLL_INTERVAL_MICROSECONDS);
                $counter--;
                if (1 > $counter) {
                    $counter = self::HEARTBEAT_AFTER_ITERATIONS;
                    if (false === $this->sessions->heartbeat($token)) {
                        $storageFailed = true;
                    }
                }
            }

            $exitCode = $process->getExitCode() ?? 1;
            if ($storageFailed) {
                $this->sessions->complete($token, 1, 'storage_failure', 'Unable to persist command output.');
            } elseif ($this->sessions->isCancellationRequested($token)) {
                $this->sessions->complete($token, $exitCode, 'cancelled');
            } elseif (0 === $exitCode) {
                $this->sessions->complete($token, 0, 'success');
            } else {
                $this->sessions->complete($token, $exitCode, 'command_failure');
            }

            return $exitCode;
        } catch (ProcessTimedOutException $e) {
            $this->sessions->complete($token, 124, 'timed_out', $e->getMessage());
            return 124;
        } catch (Throwable $e) {
            $outcome = $started ? 'command_failure' : 'process_start_failed';
            $this->logger->error("Console session worker failed while handling session '{session}': {exception.message}", [
                'operation' => 'console.execute',
                'session' => substr(hash('sha256', $token), 0, 12),
                'error' => $outcome,
                ...exception_log($e),
            ]);
            $this->sessions->complete($token, 1, $outcome, $e->getMessage());
            return 1;
        } finally {
            if ($process instanceof Process && $process->isRunning()) {
                $process->stop(1, 9);
            }
            $this->sessions->releaseLock($writerLock);
        }
    }

    /** @return array<string> */
    private function getCommand(string $command): array
    {
        if (true === (bool) Config::get('console.enable.all') && str_starts_with($command, '$')) {
            return ['sh', '-c', trim(after($command, '$'))];
        }

        $root = realpath(__DIR__ . '/../../../');
        if (false === $root) {
            throw new \RuntimeException('Unable to resolve application path.');
        }

        return Shlex::split("{$root}/bin/console -n " . trim(after($command, 'console')));
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,string>
     */
    private function getEnvironment(array $request, string $cwd): array
    {
        $environment = [
            'LANG' => 'en_US.UTF-8',
            'LC_ALL' => 'en_US.UTF-8',
            'PWD' => $cwd,
        ];

        if (false !== ag($request, 'pty', true)) {
            $environment = array_replace($environment, [
                'TERM' => 'xterm-256color',
                'COLORTERM' => 'truecolor',
                'FORCE_COLOR' => (string) ag($request, 'force_color', '1'),
                'CLICOLOR' => '1',
            ]);
        }

        return array_replace_recursive($environment, $_ENV);
    }
}
