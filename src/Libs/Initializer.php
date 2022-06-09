<?php

declare(strict_types=1);

namespace App\Libs;

use App\Cli;
use App\Libs\Entity\StateInterface as iFace;
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
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
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
            $saveRequestPayload = false;

            // -- Global request dump.
            if (true === (bool)env('WS_REQUEST_DEBUG')) {
                saveRequestPayload($realRequest);
                $saveRequestPayload = true;
            }

            $apikey = ag($realRequest->getQueryParams(), 'apikey', $realRequest->getHeaderLine('x-apikey'));
            if (empty($apikey)) {
                $log[] = 'No webhook token found in headers or query';
                throw new HttpException('No Webhook token was found.', 400);
            }

            // -- Request specific dump.
            if (false === $saveRequestPayload && null !== ag($realRequest->getQueryParams(), 'rdebug')) {
                saveRequestPayload($realRequest);
            }

            $validUser = $validUUid = null;

            // -- Find Relevant backend.
            foreach (Config::get('servers', []) as $name => $info) {
                if (null === ag($info, 'webhook.token')) {
                    continue;
                }

                if (true !== hash_equals((string)ag($info, 'webhook.token'), (string)$apikey)) {
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

                if (null !== $userId && true === (bool)ag($info, 'webhook.match.user')) {
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
                            'Request user id [%s] does not match configured value [%s]',
                            $requestUser ?? 'NOT SET',
                            $userId
                        );
                        continue;
                    }

                    $validUser = true;
                }

                $uuid = ag($info, 'uuid', null);

                if (null !== $uuid && true === (bool)ag($info, 'webhook.match.uuid')) {
                    if (null === ($requestBackendId = $request->getAttribute('SERVER_ID', null))) {
                        $validUUid = false;
                        $server = $class = null;
                        $log[] = 'backend unique id is not set';
                        continue;
                    }

                    if (false === hash_equals((string)$uuid, (string)$requestBackendId)) {
                        $validUUid = false;
                        $server = $class = null;
                        $log[] = sprintf(
                            'Request backend unique id [%s] does not match configured value [%s]',
                            $requestBackendId ?? 'NOT SET',
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
                    $message = 'token is valid, User matching failed.';
                } elseif (false === $validUUid) {
                    $message = 'token and user are valid. Backend unique id matching failed.';
                } else {
                    $message = 'Invalid token was given.';
                }
                throw new HttpException($message, 401);
            }

            // -- sanity check in case user has both import.enabled and options.IMPORT_METADATA_ONLY enabled.
            if (true === (bool)ag($server, ['import.enabled', 'webhook.import'])) {
                if (true === ag_exists($server, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                    $server = ag_delete($server, 'options.' . Options::IMPORT_METADATA_ONLY);
                }
            }

            $metadataOnly = true === (bool)ag($server, 'options.' . Options::IMPORT_METADATA_ONLY);

            // -- @RELEASE remove 'webhook.import'
            if (true !== $metadataOnly && true !== (bool)ag($server, ['import.enabled', 'webhook.import'])) {
                $log[] = 'Import disabled for this backend.';
                throw new HttpException(
                    sprintf(
                        '%s: Import is disabled for this backend.',
                        $class->getName()
                    ),
                    500
                );
            }

            $entity = $class->parseWebhook($request);

            $savePayload = true === Config::get('webhook.debug') || null !== ag($request->getQueryParams(), 'debug');

            if (true === $savePayload && false === $entity->isTainted()) {
                saveWebhookPayload($entity, $request);
            }

            $responseHeaders = [
                'X-WH-Id' => '?',
                'X-WH-Backend' => $class->getName(),
                'X-WH-Item' => $entity->getName(),
                'X-WH-Type' => $request->getAttribute('WH_TYPE', 'not_set'),
                'X-WH-Event' => $request->getAttribute('WH_EVENT', 'not_set'),
                'X-WH-Version' => getAppVersion(),
            ];

            if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
                $message = sprintf('%s does not have valid/supported external ids.', ucfirst($entity->type));
                return new Response(
                    status:  204,
                    headers: $responseHeaders + ['X-Status' => $message]
                );
            }

            $storage = Container::get(StorageInterface::class);

            if (null === ($local = $storage->get($entity))) {
                if (true === $metadataOnly) {
                    $message = 'Unable to add new item. This backend is flagged for metadata only.';
                    return new Response(
                        status:  204,
                        headers: $responseHeaders + ['X-Status' => $message]
                    );
                }

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

            $responseHeaders['X-WH-Id'] = $local->id;

            $cloned = clone $local;

            if (true === $metadataOnly || true === $entity->isTainted()) {
                $flag = true === $metadataOnly ? '[M]' : '[T]';
                $keys = true === $metadataOnly ? [iFace::COLUMN_META_DATA] : iFace::ENTITY_FORCE_UPDATE_FIELDS;

                if ((clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
                    if (true === $metadataOnly) {
                        $entity->guids = Guid::makeVirtualGuid(
                            $entity->via,
                            ag($entity->getMetadata($entity->via), iFace::COLUMN_ID)
                        );
                        $keys = array_merge($keys, [iFace::COLUMN_GUIDS, iFace::COLUMN_EXTRA]);
                    }

                    $local = $storage->update(
                        $local->apply(
                            entity: $entity,
                            fields: array_merge($keys, [iFace::COLUMN_EXTRA])
                        )
                    );

                    return jsonResponse(
                        status:  200,
                        body:    $local->getAll(),
                        headers: $responseHeaders + ['X-Status' => $flag . ' Updated metadata.']
                    );
                }

                return new Response(
                    status:  200,
                    headers: $responseHeaders + ['X-Status' => $flag . ' This event is irrelevant.']
                );
            }

            if ($local->updated >= $entity->updated) {
                $keys = iFace::ENTITY_FORCE_UPDATE_FIELDS;

                // -- Handle mark as unplayed logic.
                if (false === $entity->isWatched() && true === $local->shouldMarkAsUnplayed($entity)) {
                    $local = $storage->update(
                        $local->apply(entity: $entity, fields: [iFace::COLUMN_META_DATA])->markAsUnplayed($entity)
                    );

                    queuePush($local);

                    return jsonResponse(
                        status:  200,
                        body:    $local->getAll(),
                        headers: $responseHeaders + [
                                     'X-Status' => sprintf('%s Marked as unplayed.', ucfirst($entity->type))
                                 ]
                    );
                }

                if ((clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
                    $local = $storage->update(
                        $local->apply(
                            entity: $entity,
                            fields: array_merge($keys, [iFace::COLUMN_EXTRA])
                        )
                    );
                    return jsonResponse(
                        status:  200,
                        body:    $local->getAll(),
                        headers: $responseHeaders + ['X-Status' => sprintf('[D] Updated %s.', $entity->type)]
                    );
                }

                return new Response(
                    status:  200,
                    headers: $responseHeaders + ['X-Status' => '[D] No difference detected.']
                );
            }

            if ((clone $cloned)->apply($entity)->isChanged()) {
                $local = $storage->update($local->apply($entity));

                $message = '%1$s Updated.';

                if ($cloned->isWatched() !== $local->isWatched()) {
                    $message = '%1$s marked as [%2$s]';
                    queuePush($local);
                }

                return jsonResponse(
                    status:  200,
                    body:    $local->getAll(),
                    headers: $responseHeaders + [
                                 'X-Status' => sprintf(
                                     $message,
                                     ucfirst($entity->type),
                                     $entity->isWatched() ? 'Played' : 'Unplayed',
                                 ),
                             ]
                );
            }

            return new Response(
                status:  200,
                headers: $responseHeaders + ['X-Status' => 'No changes detected.']
            );
        } catch (HttpException $e) {
            if (200 === $e->getCode()) {
                return new Response(
                    status:  $e->getCode(),
                    headers: $responseHeaders + ['X-Status' => $e->getMessage()]
                );
            }

            $logger->error(message: $e->getMessage(), context: [
                'context' => [
                    'attributes' => $request->getAttributes(),
                    'log' => $log,
                ],
                'trace' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
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
