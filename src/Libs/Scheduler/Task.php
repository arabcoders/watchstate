<?php

declare(strict_types=1);

namespace App\Libs\Scheduler;

use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class Task
{
    public const WHEN_OVER_LAPPING_CALL = 'whenOverlappingCall';
    public const RUN_IN_FOREGROUND = 'runInBackground';
    public const BEFORE_CALL = 'beforeCall';
    public const RUN_AT = 'RunAt';
    public const COMMAND = 'Command';
    public const ARGS = 'Args';
    public const NAME = 'Name';
    public const ENABLED = 'enabled';
    public const USE_CLOSURE_AS_COMMAND = 'closureAsCommand';
    public const CONFIG = 'Config';
    public const CONFIG_TMP_DIR = 'CONFIG_TMP_DIR';
    public const CONFIG_CWD = 'CONFIG_CWD';
    public const CONFIG_TIMEOUT = 'CONFIG_TIMEOUT';
    public const CONFIG_ENV = 'CONFIG_ENV';
    public const CONFIG_INPUT = 'CONFIG_INPUT';
    public const CONFIG_NO_LOCK = 'ManualLock';
    public const CONFIG_NO_OUTPUT = 'noOutput';

    /**
     * Task identifier.
     */
    private string $name;

    /**
     * Command to Execute.
     */
    private string $command;

    /**
     * Defines if the task should run in background.
     */
    private bool $runInBackground = true;

    /**
     * Creation time.
     */
    private DateTimeInterface $creationTime;

    /**
     * task schedule time.
     */
    private CronExpression|null $executionTime = null;

    private LoggerInterface|null $logger = null;
    /**
     * Lock file
     */
    private string $lockFile;

    /**
     * The output of the executed task.
     */
    private mixed $output = '';

    /**
     * @phpstan-ignore-next-line
     */
    private ?Process $process = null;

    private array $config;

    /**
     * @psalm-var Closure(Task $this): bool
     */
    private ?Closure $beforeCall = null;

    /**
     * Command Arguments.
     */
    private array|string $args = [];

    /**
     * A function to ignore an overlapping task.
     * If true, the task will run also if it's overlapping.
     * @psalm-var Closure(string $lockFile): bool
     */
    private Closure $whenOverlappingCall;

    private ?int $exitCode = null;
    private ?string $exitCodeText = null;

    public function __construct(string $name, string $command, array|string $args = [], array $config = [])
    {
        $this->command = $command;
        $this->name = $name;
        $this->config = $config;
        $this->args = $args;

        $this->creationTime = new DateTimeImmutable('now');
        $this->whenOverlappingCall = fn(string $Lockfile = ''): bool => false;

        if (array_key_exists(self::CONFIG_TMP_DIR, $config) && is_dir($config[self::CONFIG_TMP_DIR])
            && is_writable($config[self::CONFIG_TMP_DIR])) {
            $tempDir = $config[self::CONFIG_TMP_DIR];
        } else {
            $tempDir = sys_get_temp_dir();
        }

        $this->lockFile = rtrim($tempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "{$name}.lock";
    }

    public static function newTask(string $name, string $command, array|string $args = [], array $config = []): self
    {
        return new self($name, $command, $args, $config);
    }

    public function runAt(CronExpression $at): self
    {
        $this->executionTime = $at;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Check if the task is due to run.
     * It accepts as input a DateTimeInterface used to check if
     * the task is due. Defaults to task creation time.
     * It also defaults the execution time if not previously defined.
     */
    public function isDue(DateTimeInterface|null $date = null): bool
    {
        $date = $date ?? $this->creationTime;

        return null !== $this->executionTime && $this->executionTime->isDue($date);
    }

    public function isOverlapping(): bool
    {
        if (array_key_exists(self::CONFIG_NO_LOCK, $this->config) && true === $this->config[self::CONFIG_NO_LOCK]) {
            return false;
        }

        $func = $this->whenOverlappingCall;

        return $this->lockFile &&
            file_exists($this->lockFile) &&
            (null !== $func && false === $func($this->lockFile));
    }

    public function inForeground(): self
    {
        $this->runInBackground = false;

        return $this;
    }

    public function canRunInBackground(): bool
    {
        return true === $this->runInBackground;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getArgs(): string
    {
        if (false === is_array($this->args)) {
            return trim($this->args);
        }

        $args = '';

        foreach ($this->args as $key => $value) {
            $args .= ' ' . $key;
            if ($value !== null) {
                $args .= ' ' . escapeshellarg($value);
            }
        }

        return trim($args);
    }

    public function run(): bool
    {
        // If overlapping, don't run
        if ($this->isOverlapping()) {
            return false;
        }

        try {
            if (null !== $this->beforeCall) {
                $fn = $this->beforeCall;
                if (true !== $fn($this)) {
                    $this->output = 'Task did not execute as beforeCall returned value other than true.';
                    return false;
                }
            }

            $cmd = $this->getCommand();

            $args = $this->getArgs();
            if (!empty($args)) {
                $cmd .= ' ' . $args;
            }

            $this->process = Process::fromShellCommandline(
                command: $cmd,
                cwd:     $this->config[self::CONFIG_CWD] ?? null,
                env:     $this->config[self::CONFIG_ENV] ?? null,
                input:   $this->config[self::CONFIG_INPUT] ?? null,
                timeout: $this->config[self::CONFIG_TIMEOUT] ?? null,
            );

            if (array_key_exists('tty', $this->config) && true === $this->config['tty']) {
                $this->process->setTty(true);
            }

            $this->acquireLock();

            if (!($this->process instanceof Process)) {
                throw new RuntimeException(sprintf('Unable to create child process for \'%s\'.', $this->getName()));
            }

            if ($this->canRunInBackground()) {
                $this->process->start();
            } else {
                $this->process->run();
                $this->output = $this->getOutput();
            }

            return true;
        } catch (Throwable $e) {
            $this->output .= sprintf(
                'Task \'%s\' has thrown unhandled exception. (%s). (%s:%d)',
                'Task-' . $this->getName(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
            $this->logger?->error($e->getMessage(), $e->getTrace());
        }

        return false;
    }

    public function getLockFile(): string
    {
        if (array_key_exists(self::CONFIG_NO_LOCK, $this->config) && true !== $this->config[self::CONFIG_NO_LOCK]) {
            return '';
        }

        return $this->lockFile;
    }

    public function acquireLock(): void
    {
        if (array_key_exists(self::CONFIG_NO_LOCK, $this->config) && true !== $this->config[self::CONFIG_NO_LOCK]) {
            return;
        }

        if (!$this->lockFile) {
            return;
        }

        file_put_contents($this->getLockFile(), $this->getName());
    }

    /**
     * Remove the task lock file.
     */
    public function releaseLock(): void
    {
        if (array_key_exists(self::CONFIG_NO_LOCK, $this->config) && true !== $this->config[self::CONFIG_NO_LOCK]) {
            return;
        }

        if ($this->lockFile && file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }

    /**
     * Get the task output.
     */
    public function getOutput(): mixed
    {
        if (null === $this->process) {
            return $this->output;
        }

        if (array_key_exists(self::CONFIG_NO_OUTPUT, $this->config) && true === $this->config[self::CONFIG_NO_OUTPUT]) {
            return '';
        }

        if ($this->process->isRunning()) {
            $this->process->wait();
        }

        $this->exitCode = $this->process->getExitCode();
        $this->exitCodeText = $this->process->getExitCodeText();

        $stdout = $this->process->getOutput();
        $stderr = $this->process->getErrorOutput();

        if (!empty($stdout)) {
            $this->output .= $stdout;
        }

        if (!empty($stderr)) {
            if (!empty($this->output)) {
                $this->output .= PHP_EOL;
            }
            $this->output .= $stderr;
        }

        $this->process = null;

        return $this->output;
    }

    /**
     * Set function to be called if task is overlapping.
     */
    public function whenOverlapping(Closure $fn): self
    {
        $this->whenOverlappingCall = $fn;

        return $this;
    }

    public function setBeforeCall(?Closure $fn = null): self
    {
        $this->beforeCall = $fn;

        return $this;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function getExitCodeText(): ?string
    {
        return $this->exitCodeText;
    }

    public function __destruct()
    {
        $this->releaseLock();
    }
}
