<?php

declare(strict_types=1);

namespace App\Libs;

use App\Cli;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Exceptions\HttpException;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Extends\ConsoleHandler;
use App\Libs\Extends\ConsoleOutput;
use App\Libs\Extends\RouterStrategy;
use Closure;
use DateInterval;
use ErrorException;
use League\Route\Http\Exception as RouterHttpException;
use League\Route\RouteGroup;
use League\Route\Router as APIRouter;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Level;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Class Initializer
 *
 * The Initializer class is responsible for bootstrapping the entire application, for both HTTP and CLI context.
 *
 * @package Your\Namespace
 */
final class Initializer
{
    private Cli $cli;
    private ConsoleOutput $cliOutput;
    private LoggerInterface|null $accessLog = null;

    /**
     * Initializes the object.
     *
     * This method is used to load user custom environment variables, initialize the container,
     * initialize the configuration, and add services to the container.
     *
     * @return void
     */
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

    /**
     * Bootstrap the application.
     *
     * This method is used to create directories, load configuration files, set the default timezone,
     * setup error and exception handlers, and return the object.
     *
     * @return self
     * @throws ErrorException If an error occurs.
     */
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
                throw new ErrorException($message, 0, $severity, $file, $line);
            }
        );

        set_exception_handler(function (Throwable $e) {
            Container::get(LoggerInterface::class)->error(
                message: "{class}: {error} ({file}:{line})." . PHP_EOL,
                context: [
                    'class' => $e::class,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            );
            exit(1);
        });

        return $this;
    }

    /**
     * Run the application in CLI Context.
     */
    public function console(): void
    {
        try {
            $this->cli->setCatchExceptions(false);

            $cache = Container::get(CacheInterface::class);

            $routes = [];
            $loader = false === $cache->has('routes_cli') ? generateRoutes() : $cache->get('routes_cli', []);

            foreach ($loader as $route) {
                $routes[ag($route, 'path')] = ag($route, 'callable');
            }

            $this->cli->setCommandLoader(new ContainerCommandLoader(Container::getContainer(), $routes));

            $this->cli->run(output: $this->cliOutput);
        } catch (Throwable $e) {
            $this->cli->renderThrowable($e, $this->cliOutput);
            exit(1);
        }
    }

    /**
     * Run the application in HTTP Context.
     *
     * @param iRequest|null $request If null, the request will be created from globals.
     * @param callable(ResponseInterface):void|null $emitter If null, the emitter will be created from globals.
     * @param null|Closure(iRequest): ResponseInterface $fn If null, the default HTTP server will be used.
     */
    public function http(iRequest|null $request = null, callable|null $emitter = null, Closure|null $fn = null): void
    {
        $emitter = $emitter ?? new Emitter();

        if (null === $request) {
            $factory = new Psr17Factory();
            $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
        }

        try {
            $response = null === $fn ? $this->defaultHttpServer($request) : $fn($request);
            if (false === $response->hasHeader('X-Application-Version')) {
                $response = $response->withAddedHeader('X-Application-Version', getAppVersion());
            }

            if ($response->hasHeader('X-Log-Response')) {
                $this->write($request, Level::Info, $this->formatLog($request, $response));
                $response = $response->withoutHeader('X-Log-Response');
            }
        } catch (Throwable $e) {
            $httpException = (true === ($e instanceof HttpException));

            if (false === $httpException || $e->getCode() !== 200) {
                Container::get(LoggerInterface::class)->error(
                    message: $e->getMessage(),
                    context: [
                        'kind' => $e::class,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTrace(),
                    ]
                );
            }

            $statusCode = $httpException && $e->getCode() >= 200 && $e->getCode() <= 499 ? $e->getCode() : 500;
            $response = new Response(status: $statusCode, headers: [
                'X-Error-Message' => $httpException ? $e->getMessage() : ''
            ]);
        }

        $emitter($response);
    }

    /**
     * Handle HTTP requests and process webhooks.
     *
     * @param iRequest $realRequest The incoming HTTP request.
     *
     * @return ResponseInterface The HTTP response.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If an error occurs.
     */
    private function defaultHttpServer(iRequest $realRequest): ResponseInterface
    {
        $log = $backend = [];
        $class = null;

        $request = $realRequest;
        $requestPath = $request->getUri()->getPath();

        // -- health endpoint.
        if (true === str_starts_with($requestPath, '/healthcheck')) {
            return api_response(HTTP_STATUS::HTTP_OK);
        }

        // -- favicon.
        if (true === str_starts_with($requestPath, '/favicon.ico')) {
            return api_response(HTTP_STATUS::HTTP_NOT_FOUND)
                ->withoutHeader('Content-Type')
                ->withHeader('Cache-Control', 'public, max-age=604800, immutable')
                ->withHeader('Content-Type', 'image/x-icon');
        }

        // -- Forward requests to API server.
        if (true === str_starts_with($requestPath, Config::get('api.prefix', '????'))) {
            return $this->defaultAPIServer($realRequest);
        }

        // -- Save request payload.
        if (true === Config::get('webhook.dumpRequest')) {
            saveRequestPayload($realRequest);
        }

        $apikey = ag($realRequest->getQueryParams(), 'apikey', $realRequest->getHeaderLine('x-apikey'));

        if (empty($apikey)) {
            $response = api_response(HTTP_STATUS::HTTP_UNAUTHORIZED);
            $this->write(
                $request,
                Level::Info,
                $this->formatLog($request, $response, 'No webhook token was found in header or query.')
            );
            return $response;
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
            } catch (InvalidArgumentException $e) {
                $this->write(
                    request: $request,
                    level: Level::Error,
                    message: 'Exception [{error.kind}] was thrown unhandled in [{backend}] instance creation. Error [{error.message} @ {error.file}:{error.line}].',
                    context: [
                        'backend' => $name,
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                            'trace' => $e->getTrace(),
                        ]
                    ]
                );
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
                $loglevel = Level::Debug;
                $message = 'token is valid, User matching failed.';
            } elseif (false === $validUUid) {
                $message = 'token and user are valid. Backend unique id matching failed.';
            } else {
                $message = 'Invalid token was given.';
            }

            $response = api_response(HTTP_STATUS::HTTP_UNAUTHORIZED);

            $this->write(
                $request,
                $loglevel ?? Level::Error,
                $this->formatLog($request, $response, $message),
                ['messages' => $log],
            );

            return $response;
        }

        // -- sanity check in case user has both import.enabled and options.IMPORT_METADATA_ONLY enabled.
        if (true === (bool)ag($backend, 'import.enabled')) {
            if (true === ag_exists($backend, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                $backend = ag_delete($backend, 'options.' . Options::IMPORT_METADATA_ONLY);
            }
        }

        $metadataOnly = true === (bool)ag($backend, 'options.' . Options::IMPORT_METADATA_ONLY);

        if (true !== $metadataOnly && true !== (bool)ag($backend, 'import.enabled')) {
            $response = api_response(HTTP_STATUS::HTTP_NOT_ACCEPTABLE);
            $this->write(
                $request,
                Level::Error,
                $this->formatLog($request, $response, 'Import are disabled for [{backend}].'),
                [
                    'backend' => $class->getName(),
                ],
                forceContext: true
            );

            return $response;
        }

        $entity = $class->parseWebhook($request);

        // -- Dump Webhook context.
        if (true === (bool)ag($backend, 'options.' . Options::DUMP_PAYLOAD)) {
            saveWebhookPayload($entity, $request);
        }

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $this->write(
                $request,
                Level::Info,
                'Ignoring [{backend}] {item.type} [{item.title}]. No valid/supported external ids.',
                [
                    'backend' => $entity->via,
                    'item' => [
                        'title' => $entity->getName(),
                        'type' => $entity->type,
                    ],
                ]
            );

            return api_response(HTTP_STATUS::HTTP_NOT_MODIFIED);
        }

        if ((0 === (int)$entity->episode || null === $entity->season) && $entity->isEpisode()) {
            $this->write(
                $request,
                Level::Notice,
                'Ignoring [{backend}] {item.type} [{item.title}]. No episode/season number present.',
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

            return api_response(HTTP_STATUS::HTTP_NOT_MODIFIED);
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

        if (false === $metadataOnly && true === $entity->hasPlayProgress()) {
            $progress = $cache->get('progress', []);
            $progress[$itemId] = $entity;
            $cache->set('progress', $progress, new DateInterval('P1D'));
        }

        $this->write($request, Level::Info, 'Queued [{backend}] {item.type} [{item.title}].', [
            'backend' => $entity->via,
            'has_progress' => $entity->hasPlayProgress() ? 'Yes' : 'No',
            'item' => [
                'title' => $entity->getName(),
                'type' => $entity->type,
                'played' => $entity->isWatched() ? 'Yes' : 'No',
                'queue_id' => $itemId,
            ]
        ]);

        return api_response(HTTP_STATUS::HTTP_OK);
    }

    /**
     * Default API server Responder.
     *
     * @param iRequest $realRequest The incoming HTTP request.
     *
     * @return ResponseInterface The HTTP response.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If an error occurs.
     */
    private function defaultAPIServer(iRequest $realRequest): ResponseInterface
    {
        $router = new APIRouter();
        $strategy = new RouterStrategy();
        $strategy->setContainer(Container::getContainer());
        $router->setStrategy($strategy);

        $mw = require __DIR__ . '/../../config/Middlewares.php';
        $middlewares = (array)$mw(Container::getContainer());

        foreach ($middlewares as $middleware) {
            $router->middleware($middleware(Container::getContainer()));
        }

        $fn = static function (APIRouter|RouteGroup $r, array $route): void {
            foreach ($route['method'] as $method) {
                $f = $r->map($method, $route['path'], $route['callable']);

                if (!empty($route['lazymiddlewares'])) {
                    $f->lazyMiddlewares($route['lazymiddlewares']);
                }

                if (!empty($route['middlewares'])) {
                    $f->middlewares($route['middlewares']);
                }

                if (!empty($route['host'])) {
                    $f->setHost($route['host']);
                }

                if (!empty($route['port'])) {
                    $f->setPort($route['port']);
                }

                if (!empty($route['scheme'])) {
                    $f->setScheme($route['scheme']);
                }
            }
        };

        // -- Register HTTP API routes.
        (function () use ($fn, $router) {
            $cache = Container::get(CacheInterface::class);
            foreach ($cache->has('routes_http') ? $cache->get('routes_http') : generateRoutes('http') as $route) {
                if (!empty($route['middlewares'])) {
                    $route['lazymiddlewares'] = $route['middlewares'];
                    unset($route['middlewares']);
                }
                $fn($router, $route);
            }
        })();

        try {
            return $router->dispatch($realRequest)->withHeader('X-Log-Response', '1');
        } /** @noinspection PhpRedundantCatchClauseInspection */
        catch (RouterHttpException $e) {
            throw new HttpException($e->getMessage(), $e->getStatusCode());
        }
    }

    /**
     * Create directories based on configuration file.
     *
     * @throws RuntimeException If the necessary environment variables are not set or if there is an issue creating or accessing directories.
     */
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
            'path' => $fn('path', $path),
            'tmp_dir' => $fn('tmpDir', $tmpDir),
        ];

        foreach (require $dirList as $dir) {
            $dir = r($dir, $list);

            if (false === file_exists($dir)) {
                if (false === @mkdir($dir, 0755, true) && false === is_dir($dir)) {
                    throw new RuntimeException(r('Unable to create [{path}] directory.', ['path' => $dir]));
                }
            }
        }
    }

    /**
     * Set up loggers for the application.
     *
     * @param Logger $logger The primary application logger.
     * @param array $loggers An array of additional loggers and their configurations.
     *
     * @throws RuntimeException If a logger is missing the 'type' property.
     */
    private function setupLoggers(Logger $logger, array $loggers): void
    {
        $inContainer = inContainer();

        if (null !== ($logfile = Config::get('webhook.logfile'))) {
            $level = Config::get('webhook.debug') ? Level::Debug : Level::Info;
            $this->accessLog = $logger->withName(name: 'http')
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
                            ag($context, 'level', Level::Info),
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
                            ag($context, 'level', Level::Info),
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

    /**
     * Write a log entry to the access log.
     *
     * @param iRequest $request The incoming request object.
     * @param int|string|Level $level The log level or priority.
     * @param string $message The log message.
     * @param array $context Additional data/context for the log entry.
     */
    private function write(
        iRequest $request,
        int|string|Level $level,
        string $message,
        array $context = [],
        bool $forceContext = false
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
                'method' => $request->getMethod(),
                'id' => ag($params, 'X_REQUEST_ID'),
                'ip' => getClientIp($request),
                'agent' => ag($params, 'HTTP_USER_AGENT'),
                'uri' => (string)$uri,
            ],
        ], $context);

        if (($attributes = $request->getAttributes()) && count($attributes) >= 1) {
            $context['attributes'] = $attributes;
        }

        if (true === (Config::get('logs.context') || $forceContext)) {
            $this->accessLog->log($level, $message, $context);
        } else {
            $this->accessLog->log($level, r($message, $context));
        }
    }

    private function formatLog(iRequest $request, ResponseInterface $response, string|null $message = null)
    {
        $refer = '-';

        if (true === ag_exists($request->getServerParams(), 'HTTP_REFERER')) {
            $refer = (new Uri(ag($request->getServerParams(), 'HTTP_REFERER')))
                ->withQuery('')->withFragment('')->withUserInfo('');
        }

        return r('{ip} - "{method} {uri} {protocol}" {status} {size} "{refer}" "{agent}" "{message}"', [
            'ip' => getClientIp($request),
            'user' => ag($request->getServerParams(), 'REMOTE_USER', '-'),
            'method' => $request->getMethod(),
            'uri' => $request->getUri()->getPath(),
            'protocol' => 'HTTP/' . $request->getProtocolVersion(),
            'status' => $response->getStatusCode(),
            'size' => $response->getBody()->getSize(),
            'agent' => ag($request->getServerParams(), 'HTTP_USER_AGENT', '-'),
            'refer' => (string)$refer,
            'message' => $message ?? '-',
        ]);
    }
}
