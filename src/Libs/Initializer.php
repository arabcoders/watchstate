<?php

declare(strict_types=1);

namespace App\Libs;

use App\Cli;
use App\Libs\Extends\ConsoleOutput;
use Closure;
use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class Initializer
{
    private Cli $cli;
    private ConsoleOutput $cliOutput;

    public function __construct()
    {
        // -- Load user custom environment variables.
        (function () {
            if (file_exists(__DIR__ . '/../../.env')) {
                (new Dotenv())->usePutenv(true)->overload(__DIR__ . '/../../.env');
            }

            $dataPath = env('WS_DATA_PATH', fn() => env('IN_DOCKER') ? '/config' : __DIR__ . '/../../var');
            if (file_exists($dataPath . '/config/.env')) {
                (new Dotenv())->usePutenv(true)->overload($dataPath . '/config/.env');
            }
        })();

        Container::init();

        Config::init(require __DIR__ . '/../../config/config.php');

        foreach ((array)require __DIR__ . '/../../config/services.php' as $name => $definition) {
            Container::add($name, $definition);
        }

        $this->cliOutput = new ConsoleOutput();
        $this->cli = new Cli(Container::getContainer());
    }

    public function boot(): self
    {
        $this->createDirectories();

        (function () {
            $path = Config::get('path') . '/config/config.yaml';

            if (file_exists($path)) {
                Config::init(fn() => array_replace_recursive(Config::getAll(), Yaml::parseFile($path)));
            }

            $path = Config::get('path') . '/config/servers.yaml';

            if (file_exists($path)) {
                Config::save('servers', Yaml::parseFile($path));
            }
        })();

        date_default_timezone_set(Config::get('tz', 'UTC'));

        $logger = Container::get(LoggerInterface::class);

        $this->setupLoggers($logger, Config::get('logger'));

        set_error_handler(function (int $number, mixed $error, mixed $file, int $line) {
            $errno = $number & error_reporting();
            if (0 === $errno) {
                return;
            }

            Container::get(LoggerInterface::class)->error(
                trim(sprintf('%d: %s (%s:%d).' . PHP_EOL, $number, $error, $file, $line))
            );
            exit(1);
        });

        set_exception_handler(function (Throwable $e) {
            Container::get(LoggerInterface::class)->error(
                sprintf("%s: %s (%s:%d)." . PHP_EOL, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine())
            );
            exit(1);
        });

        return $this;
    }

    public function runConsole(): void
    {
        try {
            $this->cli->setCatchExceptions(false);

            $this->cli->setCommandLoader(
                new ContainerCommandLoader(
                    Container::getContainer(),
                    require __DIR__ . '/../../config/commands.php'
                )
            );

            $this->cli->run(output: $this->cliOutput);
        } catch (Throwable $e) {
            $this->cli->renderThrowable($e, $this->cliOutput);
            exit(1);
        }
    }

    /**
     * Handle HTTP Request.
     *
     * @param Closure(ServerRequestInterface): ResponseInterface $fn
     */
    public function runHttp(
        Closure $fn,
        ServerRequestInterface|null $request = null,
        EmitterInterface|null $emitter = null
    ): void {
        $emitter = $emitter ?? new SapiEmitter();

        if (null === $request) {
            $factory = new Psr17Factory();
            $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
        }

        try {
            $response = $fn($request);
        } catch (Throwable $e) {
            Container::get(LoggerInterface::class)->error(
                $e->getMessage(),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            );
            $response = new Response(500);
        }

        $emitter->emit($response);
    }

    private function createDirectories(): void
    {
        $dirList = __DIR__ . '/../../config/directories.php';

        if (!file_exists($dirList)) {
            return;
        }

        if (!($path = Config::get('path'))) {
            throw new RuntimeException('No ENV:WS_DATA_PATH was set.');
        }

        if (!($tmpDir = Config::get('tmpDir'))) {
            throw new RuntimeException('No ENV:WS_TMP_DIR was set.');
        }

        $fn = function (string $key, string $path): string {
            if (!file_exists($path)) {
                if (!@mkdir($path, 0755, true) && !is_dir($path)) {
                    throw new RuntimeException(sprintf('Unable to create "%s" Directory.', $path));
                }
            }

            if (!is_dir($path)) {
                throw new RuntimeException(sprintf('%s is not a directory.', $key));
            }

            if (!is_writable($path)) {
                throw new RuntimeException(
                    sprintf(
                        '%s: Unable to write to the specified directory. \'%s\' check permissions and/or user ACL.',
                        $key,
                        $path
                    )
                );
            }

            if (!is_readable($path)) {
                throw new RuntimeException(
                    sprintf(
                        '%s: Unable to read data from specified directory. \'%s\' check permissions and/or user ACL.',
                        $key,
                        $path
                    )
                );
            }

            return DIRECTORY_SEPARATOR !== $path ? rtrim($path, DIRECTORY_SEPARATOR) : $path;
        };

        $list = [
            '%(path)' => $fn('path', $path),
            '%(tmpDir)' => $fn('tmpDir', $tmpDir),
        ];

        foreach (require $dirList as $dir) {
            $dir = str_replace(array_keys($list), array_values($list), $dir);

            if (!file_exists($dir)) {
                if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new RuntimeException(sprintf('Directory "%s" was not created', $dir));
                }
            }
        }
    }

    private function setupLoggers(Logger $logger, array $loggers): void
    {
        $inDocker = (bool)env('IN_DOCKER');

        foreach ($loggers as $name => $context) {
            if (!ag($context, 'type')) {
                throw new RuntimeException(sprintf('Logger: \'%s\' has no type set.', $name));
            }

            if (true !== ag($context, 'enabled')) {
                continue;
            }

            if (null !== ($cDocker = ag($context, 'docker', null))) {
                $cDocker = (bool)$cDocker;
                if (true === $cDocker && !$inDocker) {
                    continue;
                }

                if (false === $cDocker && $inDocker) {
                    continue;
                }
            }

            switch (ag($context, 'type')) {
                case 'stream':
                    $logger->pushHandler(
                        new StreamHandler(
                            ag($context, 'filename'),
                            ag($context, 'level', Logger::INFO),
                            (bool)ag($context, 'bubble', true),
                        )
                    );
                    break;
                case 'syslog':
                    $logger->pushHandler(
                        new SyslogHandler(
                            ag($context, 'name', Config::get('name')),
                            ag($context, 'facility', LOG_USER),
                            ag($context, 'level', Logger::INFO),
                            (bool)Config::get('bubble', true),
                        )
                    );
                    break;
                default:
                    throw new RuntimeException(
                        sprintf('Unknown Logger type \'%s\' set by \'%s\'.', $context['type'], $name)
                    );
            }
        }
    }
}
