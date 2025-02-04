<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Exceptions\RuntimeException;
use Closure;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Class Server
 *
 * This class can act as simple HTTP server for development purposes.
 */
final class Server
{
    public const string CONFIG_HOST = 'host';
    public const string CONFIG_PORT = 'port';
    public const string CONFIG_ROOT = 'root';
    public const string CONFIG_PHP = 'php';
    public const string CONFIG_ENV = 'env';
    public const string CONFIG_ROUTER = 'router';
    public const string CONFIG_THREADS = 'threads';

    /**
     * @var array $config The configuration settings for the server
     */
    private array $config = [];

    /**
     * @var bool Indicate whether the server is currently running
     */
    private bool $running = false;

    /**
     * @var Process|null The initiated process.
     */
    private Process|null $process = null;

    /**
     * Constructor
     *
     * @param array $config (optional) configuration options.
     *
     * @return void
     */
    public function __construct(array $config = [])
    {
        $classExists = class_exists(PhpExecutableFinder::class);

        $this->config = [
            self::CONFIG_HOST => '0.0.0.0',
            self::CONFIG_PORT => 8080,
            self::CONFIG_ROUTER => null,
            self::CONFIG_ROOT => realpath(__DIR__ . '/../../public'),
            self::CONFIG_PHP => $classExists ? new PhpExecutableFinder()->find(false) : PHP_BINARY,
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
     * Set the PHP binary path for the server.
     *
     * @param string $php The path to the PHP binary.
     *
     * @return self Returns a new instance of the class with the PHP binary path set.
     * @throws RuntimeException if the PHP binary is not executable.
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
     * Update the host of the server instance.
     *
     * @param string $host The new host to be set for the server.
     *
     * @return self Returns a new instance of the server with the updated host.
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
     * Set the port for the server.
     *
     * @param int $port The port number for the server.
     * @return self Returns a new instance of the class with the updated port configuration.
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
     * Set the number of threads for the server.
     *
     * @param int $threads The number of threads to set.
     *
     * @return self Returns a new instance of the class with the updated thread count.
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
     * Set the root path for the server instance.
     *
     * @param string $root The root path for the server instance.
     *
     * @return self Returns a new instance of the server with the updated root path.
     * @throws RuntimeException If the provided root path is not a directory.
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
     * Set a new router file for the server instance.
     *
     * @param string $router The path to the router file.
     *
     * @return self Returns a new instance of the server with the updated router file.
     * @throws RuntimeException If the specified router file does not exist.
     *
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
     * Set environment variables and return a new instance of the current object.
     *
     * @param array<string,string|int|bool> $vars The variables to set in the environment.
     * @param bool $clear Determines whether to clear the existing environment variables or not. Default is false.
     *
     * @return self Returns a new instance of the class with the updated environment variables.
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
     * Remove specified environment variables from the current configuration.
     *
     * @param array<string,string|int|bool> $vars The array of environment variables to be removed.
     *
     * @return self Returns a new instance of the class with the specified environment variables removed from the configuration.
     *              If the configuration remains unchanged, returns the current instance.
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
     * Runs the server process and waits for it.
     *
     * @param Closure|null $output The callback function to handle the process output.
     *                             If not provided, the output will be discarded.
     *
     * @return int The exit code of the server process.
     */
    public function run(Closure|null $output = null): int
    {
        $this->process = $this->makeServer($output);

        return $this->process->wait();
    }

    /**
     * Waits for the server process to complete and returns the exit code.
     *
     * @return int The exit code of the server process.
     * @throws RuntimeException If no server was started.
     */
    public function wait(): int
    {
        if (false === $this->isRunning()) {
            throw new RuntimeException('No server was started.');
        }

        return $this->process->wait();
    }

    /**
     * Runs the server process in the background.
     *
     * @param Closure|null $output The callback function to handle the process output.
     *                             If not provided, the output will be discarded.
     *
     * @return self Returns the current instance of the class.
     */
    public function runInBackground(Closure|null $output = null): self
    {
        $this->process = $this->makeServer($output);

        return $this;
    }

    /**
     * Stops the server process.
     *
     * @param int $timeout The number of seconds to wait for the process to stop. Default is 10 seconds.
     * @param int|null $signal The signal to send to the process. If not provided, the default signal defined by the operating system will be used.
     *
     * @return int The exit code of the server process, or 20002 if the process was not running.
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

    /**
     * Retrieves the interface specified in the configuration.
     *
     * @return string The interface specified in the configuration.
     */
    public function getInterface(): string
    {
        return $this->config[self::CONFIG_HOST];
    }

    /**
     * Retrieves the port number from the configuration.
     * The port number is a required configuration parameter for the server.
     *
     * @return int The port number specified in the configuration.
     */
    public function getPort(): int
    {
        return $this->config[self::CONFIG_PORT];
    }

    /**
     * Checks if the server process is running.
     *
     * @return bool Whether the server process is running or not.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Retrieves the configuration array.
     *
     * @return array The configuration array containing various settings
     *               for the application.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Retrieve the server process instance.
     *
     * @return Process|null The server process instance or null if it does not exist.
     */
    public function getProcess(): Process|null
    {
        return $this->process;
    }

    /**
     * Builds the command to run the server process.
     *
     * @return array The command to run the server process.
     */
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

    /**
     * Creates and starts the server process.
     *
     * @param Closure|null $output The callback function to handle the process output.
     *                             If not provided, the output will be discarded.
     *
     * @return Process The server process instance.
     */
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

    /**
     * Destructor method that stops the server if it is running.
     */
    public function __destruct()
    {
        if (true === $this->isRunning()) {
            $this->stop();
        }
    }
}
