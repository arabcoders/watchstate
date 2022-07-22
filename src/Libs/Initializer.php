<?php

declare(strict_types=1);

namespace App\Libs;

use App\Cli;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Extends\ConsoleHandler;
use App\Libs\Extends\ConsoleOutput;
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
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class Initializer
{
    private Cli $cli;
    private ConsoleOutput $cliOutput;
    private LoggerInterface|null $accessLog = null;

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

            $path = Config::get('path') . '/config/ignore.yaml';
            if (file_exists($path)) {
                if (($yaml = Yaml::parseFile($path)) && is_array($yaml)) {
                    $list = [];
                    foreach ($yaml as $key => $val) {
                        $list[(string)makeIgnoreId($key)] = $val;
                    }
                    Config::save('ignore', $list);
                }
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

            $cache = Container::get(CacheInterface::class);

            if (!$cache->has('routes')) {
                $routes = generateRoutes();
            } else {
                $routes = $cache->get('routes', []);
            }

            $this->cli->setCommandLoader(
                new ContainerCommandLoader(Container::getContainer(), $routes)
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
                    'trace' => $e->getTrace(),
                ]
            );
            $response = new Response(500);
        }

        $emitter->emit($response);
    }

    private function defaultHttpServer(ServerRequestInterface $realRequest): ResponseInterface
    {
        $log = $backend = [];
        $class = null;

        $request = $realRequest;

        // -- health endpoint.
        if (true === str_starts_with($request->getUri()->getPath(), '/healthcheck')) {
            return new Response(200);
        }

        // -- Save request payload.
        if (true === Config::get('webhook.debug') && true === (bool)ag($realRequest->getQueryParams(), 'rdump')) {
            saveRequestPayload($realRequest);
        }

        $apikey = ag($realRequest->getQueryParams(), 'apikey', $realRequest->getHeaderLine('x-apikey'));

        if (empty($apikey)) {
            $this->write($request, Logger::INFO, 'No webhook token was found in header or query.');
            return new Response(401);
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
                $class = makeBackend($info, $name);
            } catch (RuntimeException $e) {
                $this->write($request, Logger::ERROR, 'An exception was thrown in [%(backend)] instance creation.', [
                    'backend' => $name,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $e->getTrace(),
                    ]
                ]);
                continue;
            }

            $request = $class->processRequest(clone $realRequest);
            $attr = $request->getAttributes();

            if (null !== ($userId = ag($info, 'user', null)) && true === (bool)ag($info, 'webhook.match.user')) {
                if (null === ($requestUser = ag($attr, 'user.id'))) {
                    $validUser = false;
                    $backend = $class = null;
                    $log[] = 'Request user is not set';
                    continue;
                }

                if (false === hash_equals((string)$userId, (string)$requestUser)) {
                    $validUser = false;
                    $backend = $class = null;
                    $log[] = sprintf(
                        'Request user id [%s] does not match configured value [%s]',
                        $requestUser ?? 'NOT SET',
                        $userId
                    );
                    continue;
                }

                $validUser = true;
            }

            if (null !== ($uuid = ag($info, 'uuid', null)) && true === (bool)ag($info, 'webhook.match.uuid')) {
                if (null === ($requestBackendId = ag($attr, 'backend.id'))) {
                    $validUUid = false;
                    $backend = $class = null;
                    $log[] = 'backend unique id is not set';
                    continue;
                }

                if (false === hash_equals((string)$uuid, (string)$requestBackendId)) {
                    $validUUid = false;
                    $backend = $class = null;
                    $log[] = sprintf(
                        'Request backend unique id [%s] does not match configured value [%s]',
                        $requestBackendId ?? 'NOT SET',
                        $uuid
                    );
                    continue;
                }

                $validUUid = true;
            }

            $backend = array_replace_recursive(['name' => $name], $info);
            break;
        }

        if (empty($backend) || null === $class) {
            if (false === $validUser) {
                $message = 'token is valid, User matching failed.';
            } elseif (false === $validUUid) {
                $message = 'token and user are valid. Backend unique id matching failed.';
            } else {
                $message = 'Invalid token was given.';
            }

            $this->write($request, Logger::ERROR, $message, ['messages' => $log]);
            return new Response(401);
        }

        // -- sanity check in case user has both import.enabled and options.IMPORT_METADATA_ONLY enabled.
        // -- @RELEASE remove 'webhook.import'
        if (true === (bool)ag($backend, ['import.enabled', 'webhook.import'])) {
            if (true === ag_exists($backend, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                $backend = ag_delete($backend, 'options.' . Options::IMPORT_METADATA_ONLY);
            }
        }

        $metadataOnly = true === (bool)ag($backend, 'options.' . Options::IMPORT_METADATA_ONLY);

        // -- @RELEASE remove 'webhook.import'
        if (true !== $metadataOnly && true !== (bool)ag($backend, ['import.enabled', 'webhook.import'])) {
            $this->write($request, Logger::ERROR, 'Import are disabled for [%(backend)].', [
                'backend' => $class->getName()
            ]);

            return new Response(406);
        }

        $entity = $class->parseWebhook($request);

        // -- Dump Webhook context.
        if (true === Config::get('webhook.debug') && true === (bool)ag($request->getQueryParams(), 'wdump')) {
            saveWebhookPayload($entity, $request);
        }

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $this->write(
                $request, Logger::INFO,
                'Ignoring [%(backend)] %(item.type) [%(item.title)]. No valid/supported external ids.',
                [
                    'backend' => $entity->via,
                    'item' => [
                        'title' => $entity->getName(),
                        'type' => $entity->type,
                    ]
                ]
            );

            return new Response(304);
        }

        $db = Container::get(iDB::class);

        if (null === ($local = $db->get($entity))) {
            if (true === $metadataOnly) {
                $this->write(
                    $request, Logger::INFO,
                    'Ignoring [%(backend)] %(item.type) [%(item.title)]. Backend flagged for metadata only.',
                    [
                        'backend' => $entity->via,
                        'item' => [
                            'title' => $entity->getName(),
                            'type' => $entity->type,
                        ]
                    ]
                );

                return new Response(204);
            }

            $entity = $db->insert($entity);

            if (true === $entity->isWatched()) {
                queuePush($entity);
            }

            $this->write($request, Logger::NOTICE, '[%(backend)] Added [%(item.title)] as new item.', [
                'id' => $entity->id,
                'backend' => $entity->via,
                'item' => [
                    'title' => $entity->getName(),
                    'type' => $entity->type,
                ]
            ]);

            return new Response(200);
        }

        $cloned = clone $local;

        if (true === $metadataOnly || true === $entity->isTainted()) {
            $flag = true === $metadataOnly ? 'M' : 'T';
            $keys = true === $metadataOnly ? [iFace::COLUMN_META_DATA] : iFace::ENTITY_FORCE_UPDATE_FIELDS;

            if ((clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
                $local = $db->update(
                    $local->apply(
                        entity: $entity,
                        fields: array_merge($keys, [iFace::COLUMN_EXTRA])
                    )
                );

                $this->write(
                    $request, Logger::NOTICE,
                    '[%(flag)] [%(backend)] updated [%(item.title)] metadata.',
                    [
                        'flag' => $flag,
                        'id' => $local->id,
                        'backend' => $entity->via,
                        'item' => [
                            'title' => $entity->getName(),
                            'type' => $entity->type,
                        ]
                    ]
                );

                return new Response(200);
            }

            $this->write(
                $request, Logger::DEBUG,
                '[%(flag)] Ignoring [%(backend)] [%(item.title)] request. This webhook event is irrelevant.',
                [
                    'flag' => $flag,
                    'id' => $local->id,
                    'backend' => $entity->via,
                    'item' => [
                        'title' => $entity->getName(),
                        'type' => $entity->type,
                    ],
                ]
            );

            return new Response(204);
        }

        if ($local->updated >= $entity->updated) {
            $keys = iFace::ENTITY_FORCE_UPDATE_FIELDS;

            // -- Handle mark as unplayed logic.
            if (false === $entity->isWatched() && true === $local->shouldMarkAsUnplayed($entity)) {
                $local = $db->update(
                    $local->apply(entity: $entity, fields: [iFace::COLUMN_META_DATA])->markAsUnplayed($entity)
                );

                queuePush($local);

                $this->write(
                    $request, Logger::NOTICE,
                    '[%(backend)] marked [%(item.title)] as [Unplayed].',
                    [
                        'id' => $local->id,
                        'backend' => $entity->via,
                        'item' => [
                            'title' => $entity->getName(),
                        ],
                    ]
                );

                return new Response(200);
            }

            if ((clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
                $local = $db->update(
                    $local->apply(
                        entity: $entity,
                        fields: array_merge($keys, [iFace::COLUMN_EXTRA])
                    )
                );

                $this->write(
                    $request, Logger::INFO,
                    '[%(backend)] updated [%(item.title)] metadata.',
                    [
                        'id' => $local->id,
                        'backend' => $entity->via,
                        'item' => [
                            'title' => $entity->getName(),
                            'type' => $entity->type,
                        ],
                    ]
                );

                return new Response(200);
            }

            $this->write(
                $request, Logger::DEBUG,
                '[%(backend)] %(item.type) [%(item.title)] metadata is identical to locally stored metadata.',
                [
                    'id' => $local->id,
                    'backend' => $entity->via,
                    'item' => [
                        'title' => $entity->getName(),
                        'type' => $entity->type,
                    ],
                ]
            );

            return new Response(200);
        }

        if ((clone $cloned)->apply($entity)->isChanged()) {
            $local = $db->update($local->apply($entity));
            $stateChanged = $cloned->isWatched() !== $local->isWatched();

            $this->write(
                $request,
                $stateChanged ? Logger::NOTICE : Logger::INFO,
                $stateChanged ? '[%(backend)] marked [%(item.title)] as [%(item.state)].' : '[%(backend)] updated [%(item.title)] metadata.',
                [
                    'id' => $local->id,
                    'backend' => $entity->via,
                    'item' => [
                        'title' => $entity->getName(),
                        'type' => $entity->type,
                        'state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                    ],
                ]
            );

            if (true === $stateChanged) {
                queuePush($local);
            }

            return new Response(200);
        }

        $this->write(
            $request, Logger::DEBUG,
            '[%(backend)] %(item.type) [%(item.title)] metadata and play state is identical to local data.',
            [
                'id' => $local->id,
                'backend' => $entity->via,
                'item' => [
                    'title' => $entity->getName(),
                    'type' => $entity->type,
                ],
            ]
        );

        return new Response(200);
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

        if (null !== ($logfile = Config::get('webhook.logfile'))) {
            $level = Config::get('webhook.debug') ? Logger::DEBUG : Logger::INFO;
            $this->accessLog = $logger->withName(name: 'webhook')
                ->pushHandler(new StreamHandler($logfile, $level, true));

            if (true === $inDocker) {
                $this->accessLog->pushHandler(new StreamHandler('php://stderr', $level, true));
            }
        }

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

    private function write(
        ServerRequestInterface $request,
        int $level,
        string $message,
        array $context = [],
    ): void {
        if (null === $this->accessLog) {
            return;
        }

        $params = $request->getServerParams();

        $uri = new Uri((string)ag($params, 'REQUEST_URI', '/'));

        if (false === empty($uri->getQuery())) {
            $query = [];
            parse_str($uri->getQuery(), $query);
            if (true === ag_exists($query, 'apikey')) {
                $query['apikey'] = 'api_key_removed';
                $uri = $uri->withQuery(http_build_query($query));
            }
        }

        $context = array_replace_recursive(
            [
                'request' => [
                    'id' => ag($params, 'X_REQUEST_ID'),
                    'ip' => ag($params, ['X_FORWARDED_FOR', 'REMOTE_ADDR']),
                    'agent' => ag($params, 'HTTP_USER_AGENT'),
                    'uri' => (string)$uri,
                ],
            ],
            $context
        );

        if (($attributes = $request->getAttributes()) && count($attributes) >= 1) {
            $context['attributes'] = $attributes;
        }

        $this->accessLog->log($level, $message, $context);
    }
}
