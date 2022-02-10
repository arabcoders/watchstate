<?php

declare(strict_types=1);

namespace App\Libs;

use App\Cli;
use App\Libs\Extends\ConsoleOutput;
use App\Libs\Mappers\Export\ExportMapper;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Storage\StorageInterface;
use Closure;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use League\Container\ReflectionContainer;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class KernelConsole
{
    private Cli $cli;
    private ConsoleOutput $cliOutput;

    public function __construct()
    {
        Container::init();

        Config::init(require __DIR__ . '/../../config/config.php');

        foreach ((array)require __DIR__ . '/../../config/services.php' as $name => $definition) {
            Container::add($name, $definition);
        }

        $this->cliOutput = new ConsoleOutput();
        $this->cli = new Cli(Container::getContainer());
    }

    /**
     * This Code Only Run once.
     *
     * @return $this
     */
    public function boot(): self
    {
        $this->createDirectories();

        // -- load user config.
        (function () {
            $path = Config::get('path') . DS . 'config' . DS . 'config.yaml';
            if (file_exists($path)) {
                Config::append(function () use ($path) {
                    return array_replace_recursive(Config::getAll(), Yaml::parseFile($path));
                });
            }

            $path = Config::get('path') . DS . 'config' . DS . 'servers.yaml';
            if (file_exists($path)) {
                Config::save('servers', Yaml::parseFile($path));
            }
        })();

        if (Config::get('tz')) {
            date_default_timezone_set(Config::get('tz'));
        }

        $logger = Container::get(LoggerInterface::class);

        $this->setupLoggers($logger, Config::get('logger'));

        set_error_handler(function (int $number, mixed $error, mixed $file, int $line) {
            if (0 === error_reporting()) {
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

        $this->setupStorage($logger);
        $this->setupImportMapper($logger);
        $this->setupExportMapper($logger);

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
        EmitterInterface|null $emit = null
    ): void {
        $emitter = $emit ?? new SapiEmitter();
        $request = $request ?? ServerRequestFactory::fromGlobals();

        try {
            $response = $fn($request);
        } catch (Throwable $e) {
            Container::get(LoggerInterface::class)->error($e->getMessage());
            $response = new EmptyResponse(500);
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
            throw new RuntimeException('No app path was set in config path or WS_DATA_PATH ENV');
        }

        if (!file_exists($path)) {
            if (!@mkdir($path, 0755, true) && !is_dir($path)) {
                throw new RuntimeException(sprintf('Unable to create "%s" Directory.', $path));
            }
        }

        $fn = function (string $key, string $path): string {
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

        $path = $fn('path', $path);

        foreach (require $dirList as $dir) {
            $dir = str_replace('%(path)', $path, $dir);

            if (!file_exists($dir)) {
                if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new RuntimeException(sprintf('Directory "%s" was not created', $dir));
                }
            }
        }
    }

    private function setupImportMapper(Logger $logger): void
    {
        $mapper = Config::get('mapper.import.type', MemoryMapper::class);

        if (class_exists($mapper)) {
            $classFQN = $mapper;
        } else {
            $classFQN = '\\App\\Libs\\Mappers\\Import\\' . $mapper;
        }

        if (!class_exists($classFQN)) {
            $message = sprintf('User defined object mapper \'%s\' is not found.', $mapper);
            $logger->error($message, ['class' => $classFQN]);
            exit(1);
        }

        if (!is_subclass_of($classFQN, ImportInterface::class)) {
            $message = sprintf(
                'User defined object mapper \'%s\' is incompatible. It does not implements the required interface.',
                $mapper
            );
            $logger->error($message, ['class' => $classFQN]);
            exit(2);
        }

        Container::add(
            ImportInterface::class,
            [
                'class' => fn() => Container::get(ReflectionContainer::class)->get($classFQN)
                    ?->setup(Config::get('mapper.import.opts', []))
                    ?->setStorage(Container::get(StorageInterface::class)),
            ]
        );
    }

    private function setupExportMapper(Logger $logger): void
    {
        $mapper = Config::get('mapper.export.type', ExportMapper::class);

        if (class_exists($mapper)) {
            $classFQN = $mapper;
        } else {
            $classFQN = '\\App\\Libs\\Mappers\\Export\\' . $mapper;
        }

        if (!class_exists($classFQN)) {
            $message = sprintf('User defined object mapper \'%s\' is not found.', $mapper);
            $logger->error($message, ['class' => $classFQN]);
            exit(1);
        }

        if (!is_subclass_of($classFQN, ExportInterface::class)) {
            $message = sprintf(
                'User defined object mapper \'%s\' is incompatible. It does not implements the required interface.',
                $mapper
            );
            $logger->error($message, ['class' => $classFQN]);
            exit(2);
        }

        Container::add(
            ExportInterface::class,
            [
                'class' => fn() => Container::get(ReflectionContainer::class)->get($classFQN)
                    ?->setup(Config::get('mapper.export.opts', []))
                    ?->setStorage(Container::get(StorageInterface::class)),
            ]
        );
    }

    private function setupStorage(Logger $logger): void
    {
        $storage = Config::get('storage.type', 'PDOStorage');

        if (class_exists($storage)) {
            $classFQN = $storage;
        } else {
            $classFQN = '\\App\\Libs\\Storage\\' . $storage;
        }

        if (!class_exists($classFQN)) {
            $message = sprintf('User defined Storage backend \'%s\' is not found.', $storage);
            $logger->error($message, ['class' => $classFQN]);
            exit(3);
        }

        if (!is_subclass_of($classFQN, StorageInterface::class)) {
            $message = sprintf(
                'Storage backend \'%s\' is incompatible. It does not implements the required interface.',
                $storage
            );
            $logger->error($message, ['class' => $classFQN]);
            exit(4);
        }

        Container::add(
            StorageInterface::class,
            fn() => Container::get(ReflectionContainer::class)?->get($classFQN)?->setup(
                Config::get('storage.opts', [])
            ),
        );
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
