<?php

declare(strict_types=1);

namespace App\Libs;

use Closure;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class Server
{
    public const CONFIG_HOST = 'host';
    public const CONFIG_PORT = 'port';
    public const CONFIG_ROOT = 'root';
    public const CONFIG_PHP = 'php';
    public const CONFIG_ENV = 'env';
    public const CONFIG_ROUTER = 'router';
    public const CONFIG_THREADS = 'threads';

    /**
     * The default and user merged configuration array
     */
    private array $config = [];

    /**
     * Indicate whether the server is currently running
     */
    private bool $running = false;

    /**
     * The initiated process.
     */
    private Process|null $process = null;

    public function __construct(array $config = [])
    {
        $classExists = class_exists(PhpExecutableFinder::class);

        $this->config = [
            self::CONFIG_HOST => '0.0.0.0',
            self::CONFIG_PORT => 8080,
            self::CONFIG_ROUTER => null,
            self::CONFIG_ROOT => realpath(__DIR__ . '/../../public'),
            self::CONFIG_PHP => $classExists ? (new PhpExecutableFinder())->find(false) : PHP_BINARY,
            self::CONFIG_ENV => array_replace_recursive($_ENV, getenv()),
            self::CONFIG_THREADS => 1,
        ];

        if (null !== ($config[self::CONFIG_HOST] ?? null)) {
            $this->withInterface($config[self::CONFIG_HOST]);
            $this->config[self::CONFIG_HOST] = $config[self::CONFIG_HOST];
        }

        if (null !== ($config[self::CONFIG_PORT] ?? null)) {
            $this->withPort($config[self::CONFIG_PORT]);
            $this->config[self::CONFIG_PORT] = $config[self::CONFIG_PORT];
        }

        if (null !== ($config[self::CONFIG_ROUTER] ?? null)) {
            $this->withRouter($config[self::CONFIG_ROUTER]);
            $this->config[self::CONFIG_ROUTER] = $config[self::CONFIG_ROUTER];
        }

        if (null !== ($config[self::CONFIG_ROOT] ?? null)) {
            $this->withRoot($config[self::CONFIG_ROOT]);
            $this->config[self::CONFIG_ROOT] = $config[self::CONFIG_ROOT];
        }

        if (null !== ($config[self::CONFIG_PHP] ?? null)) {
            $this->withPHP($config[self::CONFIG_PHP]);
            $this->config[self::CONFIG_PHP] = $config[self::CONFIG_PHP];
        }

        if (null !== ($config[self::CONFIG_THREADS] ?? null)) {
            $this->withThreads($config[self::CONFIG_THREADS]);
            $this->config[self::CONFIG_THREADS] = $config[self::CONFIG_THREADS];
        }

        if (null !== ($config[self::CONFIG_ENV] ?? null)) {
            $this->withENV($config[self::CONFIG_ENV]);
            $this->config[self::CONFIG_ENV] = array_replace_recursive(
                $this->config[self::CONFIG_ENV],
                $config[self::CONFIG_ENV]
            );
        }
    }

    /**
     * Set Path to PHP binary.
     *
     * @param string $php
     *
     * @return $this cloned instance.
     */
    public function withPHP(string $php): self
    {
        if (false === is_executable($php)) {
            throw new RuntimeException(sprintf('PHP binary \'%s\' is not executable.', $php));
        }

        if ($this->config[self::CONFIG_PHP] === $php) {
            return $this;
        }

        $instance = clone $this;
        $instance->process = null;
        $instance->running = false;

        $instance->config[self::CONFIG_PHP] = $php;

        return $instance;
    }

    /**
     * Set Host to bind to.
     *
     * @param string $host
     *
     * @return $this cloned instance.
     */
    public function withInterface(string $host): self
    {
        if ($this->config[self::CONFIG_HOST] === $host) {
            return $this;
        }

        $instance = clone $this;

        $instance->process = null;
        $instance->running = false;

        $instance->config[self::CONFIG_HOST] = $host;

        return $instance;
    }

    /**
     * Set Port.
     *
     * @param int $port
     *
     * @return $this cloned instance.
     */
    public function withPort(int $port): self
    {
        if ($this->config[self::CONFIG_PORT] === $port) {
            return $this;
        }

        $instance = clone $this;
        $instance->process = null;
        $instance->running = false;

        $instance->config[self::CONFIG_PORT] = $port;

        return $instance;
    }

    /**
     * Set How many threads to use.
     *
     * @param int $threads
     *
     * @return $this cloned instance.
     */
    public function withThreads(int $threads): self
    {
        if ($this->config[self::CONFIG_THREADS] === $threads) {
            return $this;
        }

        $instance = clone $this;
        $instance->process = null;
        $instance->running = false;

        $instance->config[self::CONFIG_THREADS] = $threads;

        return $instance;
    }

    /**
     * Set Root path.
     *
     * @param string $root
     *
     * @return $this cloned instance.
     */
    public function withRoot(string $root): self
    {
        if (!is_dir($root)) {
            throw new RuntimeException(sprintf('Root path \'%s\' is not a directory.', $root));
        }

        if ($this->config[self::CONFIG_ROOT] === $root) {
            return $this;
        }

        $instance = clone $this;
        $instance->process = null;
        $instance->running = false;

        $instance->config[self::CONFIG_ROOT] = $root;

        return $instance;
    }

    /**
     * Set PHP Router file.
     *
     * @param string $router
     *
     * @return $this cloned instance.
     */
    public function withRouter(string $router): self
    {
        if (false === file_exists($router)) {
            throw new RuntimeException(sprintf('The router file \'%s\' does not exist.', $router));
        }

        if ($this->config[self::CONFIG_ROUTER] === $router) {
            return $this;
        }

        $instance = clone $this;
        $instance->process = null;
        $instance->running = false;

        $instance->config[self::CONFIG_ROUTER] = $router;

        return $instance;
    }

    /**
     * Set Environment variables.
     *
     * @param array $vars key/value pair.
     * @param bool $clear Clear Currently loaded environment.
     *
     * @return $this cloned instance.
     */
    public function withENV(array $vars, bool $clear = false): self
    {
        $instance = clone $this;
        $instance->process = null;
        $instance->running = false;

        $instance->config[self::CONFIG_ENV] = array_replace_recursive(
            false === $clear ? $instance->config[self::CONFIG_ENV] : [],
            $vars
        );

        return $instance->config[self::CONFIG_ENV] === $this->config[self::CONFIG_ENV] ? $this : $instance;
    }

    /**
     * Exclude environment variables from loaded list.
     *
     * @param array $vars
     *
     * @return $this cloned instance.
     */
    public function withoutENV(array $vars): self
    {
        $instance = clone $this;

        $instance->process = null;
        $instance->running = false;

        $instance->config[self::CONFIG_ENV] = array_filter(
            $instance->config[self::CONFIG_ENV],
            fn($key) => false === in_array($key, $vars),
            ARRAY_FILTER_USE_KEY
        );

        return $instance->config[self::CONFIG_ENV] === $this->config[self::CONFIG_ENV] ? $this : $instance;
    }

    /**
     * Run Server in blocking mode.
     */
    public function run(Closure|null $output = null): int
    {
        $this->process = $this->makeServer($output);

        return $this->process->wait();
    }

    /**
     * Hang around until the server is killed.
     */
    public function wait(): int
    {
        if (false === $this->isRunning()) {
            throw new RuntimeException('No server was started.');
        }

        return $this->process->wait();
    }

    /**
     * Run server in background.
     */
    public function runInBackground(Closure|null $output = null): self
    {
        $this->process = $this->makeServer($output);

        return $this;
    }

    /**
     * Stop currently running server.
     *
     * @param int $timeout kill process if it does not exist in given seconds.
     * @param int|null $signal stop signal.
     *
     * @return int return 20002 if the server is not running. otherwise process exit code will be returned.
     */
    public function stop(int $timeout = 10, int|null $signal = null): int
    {
        if (null === $this->process) {
            return 20002;
        }

        $this->process->stop($timeout, $signal);

        $this->running = false;

        return $this->process->getExitCode();
    }

    public function getInterface(): string
    {
        return $this->config[self::CONFIG_HOST];
    }

    public function getPort(): int
    {
        return $this->config[self::CONFIG_PORT];
    }

    /**
     * @return bool Whether the process is running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * @return array Get loaded configuration.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @return Process|null Return server process or null if not initiated yet.
     */
    public function getProcess(): Process|null
    {
        return $this->process;
    }

    private function buildServeCommand(): array
    {
        $command = [
            $this->config[self::CONFIG_PHP],
            '-S',
            $this->config[self::CONFIG_HOST] . ':' . $this->config[self::CONFIG_PORT],
            '-t',
            $this->config[self::CONFIG_ROOT],
        ];

        if (null !== $this->config[self::CONFIG_ROUTER]) {
            $command[] = $this->config[self::CONFIG_ROUTER];
        }

        return $command;
    }

    private function makeServer(Closure|null $output = null): Process
    {
        $env = $this->config[self::CONFIG_ENV];

        if (null !== ($this->config[self::CONFIG_THREADS] ?? null) && $this->config[self::CONFIG_THREADS] > 1) {
            $env['PHP_CLI_SERVER_WORKERS'] = $this->config[self::CONFIG_THREADS];
        }

        $process = new Process(
            command: $this->buildServeCommand(),
            env: $env,
            timeout: null
        );

        $process->start($output);

        $this->running = $process->isRunning();

        return $process;
    }

    public function __destruct()
    {
        if (true === $this->isRunning()) {
            $this->stop();
        }
    }
}
