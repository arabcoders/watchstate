<?php

declare(strict_types=1);

namespace App\Libs;

use App\Cli;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\ConsoleHandler;
use App\Libs\Extends\ConsoleOutput;
use Closure;
use DateInterval;
use ErrorException;
use Laminas\HttpHandlerRunner\Emitter\EmitterInterface as iEmitter;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
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
            // -- This env file should only be used during development or direct installation.
            if (file_exists(__DIR__ . '/../../.env')) {
                (new Dotenv())->usePutenv(true)->overload(__DIR__ . '/../../.env');
            }

            // -- This is the official place where users are supposed to store .env file.
            $dataPath = env('WS_DATA_PATH', fn() => inContainer() ? '/config' : __DIR__ . '/../../var');
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
                r("{class}: {error} ({file}:{line})." . PHP_EOL, [
                    'class' => get_class($e),
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ])
            );
            exit(1);
        });

        return $this;
    }

    public function console(): void
    {
        try {
            $this->cli->setCatchExceptions(false);

            $cache = Container::get(CacheInterface::class);

            $routes = false === $cache->has('routes') ? generateRoutes() : $cache->get('routes', []);

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
     * @param iRequest|null $request
     * @param iEmitter|null $emitter
     * @param null|Closure(iRequest): ResponseInterface $fn
     */
    public function http(iRequest|null $request = null, iEmitter|null $emitter = null, Closure|null $fn = null): void
    {
        $emitter = $emitter ?? new SapiEmitter();

        if (null === $request) {
            $factory = new Psr17Factory();
            $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
        }

        try {
            $response = null === $fn ? $this->defaultHttpServer($request) : $fn($request);
            $response = $response->withAddedHeader('X-Application-Version', getAppVersion());
        } catch (Throwable $e) {
            $httpException = (true === ($e instanceof HttpException));

            if (false === $httpException || $e->getCode() !== 200) {
                Container::get(LoggerInterface::class)->error($e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'trace' => $e->getTrace(),
                ]);
            }

            $response = new Response(
                $httpException && $e->getCode() >= 200 && $e->getCode() <= 499 ? $e->getCode() : 500,
                [
                    'X-Error-Message' => $httpException ? $e->getMessage() : ''
                ]
            );
        }

        $emitter->emit($response);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function defaultHttpServer(iRequest $realRequest): ResponseInterface
    {
        $log = $backend = [];
        $class = null;

        $request = $realRequest;

        // -- health endpoint.
        if (true === str_starts_with($request->getUri()->getPath(), '/healthcheck')) {
            return new Response(200);
        }

        // -- Save request payload.
        if (true === Config::get('webhook.dumpRequest')) {
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
                    $log[] = r('Request user id [{req_user}] does not match configured value [{config_user}]', [
                        'req_user' => $requestUser ?? 'NOT SET',
                        'config_user' => $userId,
                    ]);
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
                    $log[] = r('Request backend unique id [{req_uid}] does not match backend uuid [{config_uid}].', [
                        'req_uid' => $requestBackendId ?? 'NOT SET',
                        'config_uid' => $uuid,
                    ]);
                    continue;
                }

                $validUUid = true;
            }

            $backend = array_replace_recursive(['name' => $name], $info);
            break;
        }

        if (empty($backend) || null === $class) {
            if (false === $validUser) {
                $loglevel = Logger::DEBUG;
                $message = 'token is valid, User matching failed.';
            } elseif (false === $validUUid) {
                $message = 'token and user are valid. Backend unique id matching failed.';
            } else {
                $message = 'Invalid token was given.';
            }

            $this->write($request, $loglevel ?? Logger::ERROR, $message, ['messages' => $log]);
            return new Response(401);
        }

        // -- sanity check in case user has both import.enabled and options.IMPORT_METADATA_ONLY enabled.
        if (true === (bool)ag($backend, 'import.enabled')) {
            if (true === ag_exists($backend, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                $backend = ag_delete($backend, 'options.' . Options::IMPORT_METADATA_ONLY);
            }
        }

        $metadataOnly = true === (bool)ag($backend, 'options.' . Options::IMPORT_METADATA_ONLY);

        if (true !== $metadataOnly && true !== (bool)ag($backend, 'import.enabled')) {
            $this->write($request, Logger::ERROR, 'Import are disabled for [%(backend)].', [
                'backend' => $class->getName()
            ]);

            return new Response(406);
        }

        $entity = $class->parseWebhook($request);

        // -- Dump Webhook context.
        if (true === (bool)ag($backend, 'options.' . Options::DUMP_PAYLOAD)) {
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
                    ],
                ]
            );

            return new Response(304);
        }

        if ((0 === (int)$entity->episode || null === $entity->season) && $entity->isEpisode()) {
            $this->write(
                $request,
                Logger::NOTICE,
                'Ignoring [%(backend)] %(item.type) [%(item.title)]. No episode/season number present.',
                [
                    'backend' => $entity->via,
                    'item' => [
                        'title' => $entity->getName(),
                        'type' => $entity->type,
                        'season' => (string)($entity->season ?? 'None'),
                        'episode' => (string)($entity->episode ?? 'None'),
                    ]
                ]
            );

            return new Response(304);
        }

        $cache = Container::get(CacheInterface::class);

        $items = $cache->get('requests', []);

        $itemId = r('{type}://{id}:{tainted}@{backend}', [
            'type' => $entity->type,
            'backend' => $entity->via,
            'tainted' => $entity->isTainted() ? 'tainted' : 'untainted',
            'id' => ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '??'),
        ]);

        $items[$itemId] = [
            'options' => [
                Options::IMPORT_METADATA_ONLY => $metadataOnly,
            ],
            'entity' => $entity,
        ];

        $cache->set('requests', $items, new DateInterval('P3D'));

        $this->write($request, Logger::INFO, 'Queued [%(backend)] %(item.type) [%(item.title)].', [
            'backend' => $entity->via,
            'item' => [
                'title' => $entity->getName(),
                'type' => $entity->type,
                'played' => $entity->isWatched() ? 'Yes' : 'No',
                'queue_id' => $itemId,
            ]
        ]);

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
            if (false === file_exists($path)) {
                if (false === @mkdir($path, 0755, true) && false === is_dir($path)) {
                    throw new RuntimeException(r('Unable to create [{path}] directory.', ['path' => $path]));
                }
            }

            if (false === is_dir($path)) {
                throw new RuntimeException(r('[{path}] is not a directory.', ['path' => $key]));
            }

            if (false === is_writable($path)) {
                throw new RuntimeException(
                    r('Unable to write to [{path}] directory. Check user permissions and/or user mapping.', [
                        'path' => $path,
                    ])
                );
            }

            if (false === is_readable($path)) {
                throw new RuntimeException(
                    r('Unable to read data from [{path}] directory. Check user permissions and/or user mapping.', [
                        'path' => $path,
                    ])
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

            if (false === file_exists($dir)) {
                if (false === @mkdir($dir, 0755, true) && false === is_dir($dir)) {
                    throw new RuntimeException(r('Unable to create [{path}] directory.', ['path' => $dir]));
                }
            }
        }
    }

    private function setupLoggers(Logger $logger, array $loggers): void
    {
        $inContainer = inContainer();

        if (null !== ($logfile = Config::get('webhook.logfile'))) {
            $level = Config::get('webhook.debug') ? Logger::DEBUG : Logger::INFO;
            $this->accessLog = $logger->withName(name: 'webhook')
                ->pushHandler(new StreamHandler($logfile, $level, true));

            if (true === $inContainer) {
                $this->accessLog->pushHandler(new StreamHandler('php://stderr', $level, true));
            }
        }

        foreach ($loggers as $name => $context) {
            if (!ag($context, 'type')) {
                throw new RuntimeException(r('Logger: [{name}] has no type set.', ['name' => $name]));
            }

            if (true !== (bool)ag($context, 'enabled')) {
                continue;
            }

            if (null !== ($cDocker = ag($context, 'docker', null))) {
                $cDocker = (bool)$cDocker;
                if (true === $cDocker && !$inContainer) {
                    continue;
                }

                if (false === $cDocker && $inContainer) {
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
                        r('Unknown Logger type [{type} set by [{name}].', [
                            'type' => $context['type'],
                            'name' => $name
                        ])
                    );
            }
        }
    }

    private function write(
        iRequest $request,
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

        $context = array_replace_recursive([
            'request' => [
                'id' => ag($params, 'X_REQUEST_ID'),
                'ip' => getClientIp($request),
                'agent' => ag($params, 'HTTP_USER_AGENT'),
                'uri' => (string)$uri,
            ],
        ], $context);

        if (($attributes = $request->getAttributes()) && count($attributes) >= 1) {
            $context['attributes'] = $attributes;
        }

        $this->accessLog->log($level, $message, $context);
    }
}
