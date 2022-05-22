<?php

declare(strict_types=1);

namespace App\Libs;

use App\Cli;
use App\Libs\Extends\ConsoleHandler;
use App\Libs\Extends\ConsoleOutput;
use App\Libs\Storage\StorageInterface;
use Closure;
use ErrorException;
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

        set_error_handler(
            function ($severity, $message, $file, $line) {
                if (!(error_reporting() & $severity)) {
                    return;
                }
                /** @noinspection PhpUnhandledExceptionInspection */
                throw new ErrorException($message, 0, $severity, $file, $line);
            }
        );

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
     * @param ServerRequestInterface|null $request
     * @param EmitterInterface|null $emitter
     * @param null|Closure(ServerRequestInterface): ResponseInterface $fn
     */
    public function runHttp(
        ServerRequestInterface|null $request = null,
        EmitterInterface|null $emitter = null,
        Closure|null $fn = null,
    ): void {
        $emitter = $emitter ?? new SapiEmitter();

        if (null === $request) {
            $factory = new Psr17Factory();
            $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
        }

        try {
            $response = null === $fn ? $this->defaultHttpServer($request) : $fn($request);
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

    private function defaultHttpServer(ServerRequestInterface $realRequest): ResponseInterface
    {
        $log = $responseHeaders = $server = [];
        $class = null;
        $logger = Container::get(LoggerInterface::class);

        $request = $realRequest;

        try {
            if (true === (bool)env('WS_REQUEST_DEBUG') || null !== ag($realRequest->getQueryParams(), 'rdebug')) {
                saveRequestPayload($realRequest);
            }

            // -- get apikey from header or query.
            $apikey = $realRequest->getHeaderLine('x-apikey');
            if (empty($apikey)) {
                $apikey = ag($realRequest->getQueryParams(), 'apikey', '');
                if (empty($apikey)) {
                    $log[] = 'No webhook token found in headers or query';
                    throw new HttpException('No Webhook token was found.', 400);
                }
            }

            $validUser = $validUUid = null;

            // -- Find Server
            foreach (Config::get('servers', []) as $name => $info) {
                if (null === ag($info, 'webhook.token')) {
                    continue;
                }

                if (true !== hash_equals(ag($info, 'webhook.token'), (string)$apikey)) {
                    continue;
                }

                try {
                    $class = makeServer($info, $name);
                } catch (RuntimeException $e) {
                    $log[] = sprintf('%s: Creating Instance of the server backend has failed.', $name);
                    throw new HttpException($e->getMessage(), 500);
                }

                $request = $class->processRequest(clone $realRequest);

                $userId = ag($info, 'user', null);

                if (true === (bool)ag($info, 'webhook.match.user') && null !== $userId) {
                    if (null === ($requestUser = $request->getAttribute('USER_ID', null))) {
                        $validUser = false;
                        $server = $class = null;
                        $log[] = 'Request user is not set';
                        continue;
                    }

                    if (false === hash_equals((string)$userId, (string)$requestUser)) {
                        $validUser = false;
                        $server = $class = null;
                        $log[] = sprintf(
                            'Request user [%s] does not match configured value [%s]',
                            $requestUser ?? 'NO USER_ID',
                            $userId
                        );
                        continue;
                    }
                    $validUser = true;
                }

                $uuid = ag($info, 'uuid', null);

                if (true === (bool)ag($info, 'webhook.match.uuid') && null !== $uuid) {
                    if (null === ($requestServerId = $request->getAttribute('SERVER_ID', null))) {
                        $validUUid = false;
                        $server = $class = null;
                        $log[] = 'Media backend id is not set';
                        continue;
                    }

                    if (false === hash_equals((string)$uuid, (string)$requestServerId)) {
                        $validUUid = false;
                        $server = $class = null;
                        $log[] = sprintf(
                            'Request media backend id [%s] does not match configured value [%s]',
                            $requestServerId ?? 'not_set',
                            $uuid
                        );
                        continue;
                    }

                    $validUUid = true;
                }

                $server = array_replace_recursive(['name' => $name], $info);
                break;
            }

            if (empty($server) || null === $class) {
                if (false === $validUser) {
                    $message = 'Webhook token is valid, User matching failed.';
                } elseif (false === $validUUid) {
                    $message = 'Webhook token and user are valid. Backend unique id matching failed.';
                } else {
                    $message = 'Invalid webhook token was given.';
                }
                throw new HttpException($message, 401);
            }

            if (true !== ag($server, 'webhook.import')) {
                $log[] = 'Import disabled for this server';
                throw new HttpException(
                    sprintf(
                        '%s: Import via webhook is disabled for this server via user config.',
                        $class->getName()
                    ),
                    500
                );
            }

            $entity = $class->parseWebhook($request);

            $responseHeaders = [
                'X-WH-Backend' => $class->getName(),
                'X-WH-Item' => $entity->getName(),
                'X-WH-Id' => '?',
                'X-WH-Type' => $request->getAttribute('WH_TYPE', 'not_set'),
                'X-WH-Event' => $request->getAttribute('WH_EVENT', 'not_set'),
            ];

            if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
                return new Response(
                    status:  204,
                    headers: $responseHeaders + [
                                 'X-Status' => sprintf(
                                     '%s does not have external and parents ids.',
                                     ucfirst($entity->type)
                                 )
                             ]
                );
            }

            $storage = Container::get(StorageInterface::class);

            if (null === ($backend = $storage->get($entity))) {
                $entity = $storage->insert($entity);

                if (true === $entity->isWatched()) {
                    queuePush($entity);
                }

                $responseHeaders['X-WH-Id'] = $entity->id;

                return jsonResponse(
                    status:  200,
                    body:    $entity->getAll(),
                    headers: $responseHeaders + ['X-Status' => sprintf('Added %s as new item.', $entity->type)]
                );
            }

            $responseHeaders['X-WH-Id'] = $backend->id;

            if (true === $entity->isTainted()) {
                $cloned = clone $backend;

                if ($cloned->apply($entity, metadataOnly: true)->isChanged()) {
                    $backend = $storage->update($backend->apply($entity));
                    return jsonResponse(
                        status:  200,
                        body:    $backend->getAll(),
                        headers: $responseHeaders + ['X-Status' => '[T] Updated metadata.']
                    );
                }

                return new Response(
                    status:  200,
                    headers: $responseHeaders + ['X-Status' => '[T] This event is irrelevant.']
                );
            }

            if ($backend->updated >= $entity->updated) {
                if ($backend->apply($entity, metadataOnly: true)->isChanged()) {
                    $backend = $storage->update($backend->apply($entity));
                    return jsonResponse(
                        status:  200,
                        body:    $backend->getAll(),
                        headers: $responseHeaders + ['X-Status' => sprintf('Updated %s metadata.', $entity->type)]
                    );
                }

                return new Response(
                    status:  200,
                    headers: $responseHeaders + [
                                 'X-Status' => sprintf(
                                     '%s time is older than the recorded time in database.',
                                     ucfirst($entity->type)
                                 ),
                             ]
                );
            }

            $cloned = clone $backend;

            if ($backend->apply($entity, metadataOnly: true)->isChanged()) {
                $backend = $storage->update($backend->apply($entity));

                $message = 'Updated %s metadata.';

                if ($cloned->watched !== $backend->watched) {
                    queuePush($backend);
                    $message = 'Queued %s For push event. [Played: %s]';
                }

                return jsonResponse(
                    status:  200,
                    body:    $backend->getAll(),
                    headers: $responseHeaders + [
                                 'X-Status' => sprintf(
                                     $message,
                                     $entity->type,
                                     $entity->isWatched() ? 'Yes' : 'No',
                                 ),
                             ]
                );
            }

            return new Response(
                status:  200,
                headers: $responseHeaders + ['X-Status' => 'No difference detected.']
            );
        } catch (HttpException $e) {
            if (200 === $e->getCode()) {
                return new Response(
                    status:  $e->getCode(),
                    headers: $responseHeaders + ['X-Status' => $e->getMessage()]
                );
            }

            $logger->error(message: $e->getMessage(), context: [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'attributes' => $request->getAttributes(),
                'log' => $log,
            ]);

            return jsonResponse(
                status:  $e->getCode(),
                body:    ['error' => true, 'message' => $e->getMessage()],
                headers: $responseHeaders + ['X-Status' => $e->getMessage()]
            );
        }
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

            if (true !== (bool)ag($context, 'enabled')) {
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
                case 'console':
                    $logger->pushHandler(new ConsoleHandler($this->cliOutput));
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
