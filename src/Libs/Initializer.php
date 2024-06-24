<?php

declare(strict_types=1);

namespace App\Libs;

use App\API\Backend\Webhooks;
use App\Cli;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Exceptions\HttpException;
use App\Libs\Extends\ConsoleHandler;
use App\Libs\Extends\ConsoleOutput;
use App\Libs\Extends\RouterStrategy;
use Closure;
use ErrorException;
use League\Route\Http\Exception as RouterHttpException;
use League\Route\RouteGroup;
use League\Route\Router as APIRouter;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Level;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
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
                loadEnvFile(file: __DIR__ . '/../../.env', usePutEnv: true, override: true);
            }

            // -- This is the official place where users are supposed to store .env file.
            $dataPath = env('WS_DATA_PATH', fn() => inContainer() ? '/config' : __DIR__ . '/../../var');
            if (file_exists($dataPath . '/config/.env')) {
                loadEnvFile(file: $dataPath . '/config/.env', usePutEnv: true, override: true);
            }
        })();

        Container::init();

        Config::init(require __DIR__ . '/../../config/config.php');

        foreach ((array)require __DIR__ . '/../../config/services.php' as $name => $definition) {
            Container::add($name, $definition);
        }

        // -- Add the Initializer class to the container.
        Container::add(self::class, ['shared' => true, 'class' => $this]);

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

        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $e) use ($logger) {
            $logger->error(message: "{class}: {error} ({file}:{line})." . PHP_EOL, context: [
                'class' => $e::class,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
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
     * Run the application in HTTP context.
     *
     * @param iRequest|null $request If null, the request will be created from globals.
     * @param null|Closure(iRequest): iResponse $fn If null, the default HTTP server will be used.
     *
     * @return iResponse Returns the HTTP response.
     */
    public function http(iRequest|null $request = null, Closure|null $fn = null): iResponse
    {
        if (null === $request) {
            $factory = new Psr17Factory();
            $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
        }

        try {
            $response = null === $fn ? $this->defaultHttpServer($request) : $fn($request);

            if (false === $response->hasHeader('X-Application-Version')) {
                $response = $response->withAddedHeader('X-Application-Version', getAppVersion());
            }

            if ($response->hasHeader('X-No-AccessLog') || 'OPTIONS' === $request->getMethod()) {
                return $response->withoutHeader('X-No-AccessLog');
            }

            $this->write(
                $request,
                $response->getStatusCode() >= 400 ? Level::Error : Level::Info,
                $this->formatLog($request, $response)
            );
        } catch (HttpException|RouterHttpException $e) {
            $realStatusCode = ($e instanceof RouterHttpException) ? $e->getStatusCode() : $e->getCode();
            $statusCode = $realStatusCode >= 200 && $realStatusCode <= 499 ? $realStatusCode : 503;

            $response = api_error(
                message: "{$realStatusCode}: {$e->getMessage()}",
                httpCode: HTTP_STATUS::tryFrom($realStatusCode) ?? HTTP_STATUS::HTTP_SERVICE_UNAVAILABLE,
            );

            if (HTTP_STATUS::HTTP_SERVICE_UNAVAILABLE->value === $statusCode) {
                Container::get(LoggerInterface::class)->error($e->getMessage(), [
                    'kind' => $e::class,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTrace(),
                ]);
            }

            $this->write(
                $request,
                $statusCode >= 400 ? Level::Error : Level::Info,
                $this->formatLog($request, $response)
            );
        } catch (Throwable $e) {
            $response = api_error(
                message: 'Unable to serve request.',
                httpCode: HTTP_STATUS::HTTP_SERVICE_UNAVAILABLE,
                body: true !== (bool)Config::get('debug.enabled', false) ? [] : [
                    'exception' => [
                        'message' => $e->getMessage(),
                        'kind' => $e::class,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTrace(),
                    ],
                ]
            );

            Container::get(LoggerInterface::class)->error($e->getMessage(), [
                'kind' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTrace(),
            ]);

            $this->write($request, Level::Error, $this->formatLog($request, $response));
        }

        return $response;
    }

    /**
     * Proxy API requests to the API server, and handle old style webhooks.
     * into the new API server.
     *
     * @param iRequest $request The incoming HTTP request.
     *
     * @return iResponse The HTTP response.
     * @throws \Psr\SimpleCache\InvalidArgumentException If cache key is illegal.
     * @throws RouterHttpException If the request is invalid.
     */
    private function defaultHttpServer(iRequest $request): iResponse
    {
        $backend = [];

        $requestPath = $request->getUri()->getPath();

        // -- health endpoint.
        if (true === str_starts_with($requestPath, '/healthcheck')) {
            return api_response(HTTP_STATUS::HTTP_OK);
        }

        // -- Forward requests to API server.
        if (true === str_starts_with($requestPath, Config::get('api.prefix', '????'))) {
            return $this->defaultAPIServer(clone $request);
        }

        $apikey = ag($request->getQueryParams(), 'apikey', $request->getHeaderLine('x-apikey'));

        if (empty($apikey)) {
            if (false === (bool)Config::get('webui.enabled', false)) {
                $response = api_response(HTTP_STATUS::HTTP_UNAUTHORIZED);
                $this->write(
                    $request,
                    Level::Info,
                    $this->formatLog($request, $response, 'No webhook token was found.')
                );
                return $response;
            }

            return (new ServeStatic())->serve($request)->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);

        // -- Find Relevant backend.
        foreach ($configFile->getAll() as $name => $info) {
            if (null === ag($info, 'webhook.token')) {
                continue;
            }

            if (true !== hash_equals((string)ag($info, 'webhook.token'), (string)$apikey)) {
                continue;
            }

            $info['name'] = $name;
            $backend = $info;
            break;
        }

        if (empty($backend)) {
            $response = api_response(HTTP_STATUS::HTTP_UNAUTHORIZED);
            $this->write($request, Level::Info, $this->formatLog($request, $response, 'Invalid token was given.'));
            return $response;
        }

        $uri = r('/v1/api/backends/{backend}/webhook', ['backend' => ag($backend, 'name')]);
        return Container::get(Webhooks::class)(
            $request->withUri($request->getUri()->withPath($uri)->withQuery(''))
                ->withHeader('Authorization', 'Bearer ' . Config::get('api.key'))->withoutHeader('X-Apikey'),
            [
                'name' => $backend['name']
            ]
        );
    }

    /**
     * Default API server Responder.
     *
     * @param iRequest $realRequest The incoming HTTP request.
     *
     * @return iResponse The HTTP response.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If an error occurs.
     */
    private function defaultAPIServer(iRequest $realRequest): iResponse
    {
        $router = new APIRouter();
        foreach (Config::get('api.pattern_match', []) as $_key => $_value) {
            $router->addPatternMatcher($_key, $_value);
        }
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

        return $router->dispatch($realRequest);
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
                    throw new RuntimeException(r("Unable to create '{path}' directory.", ['path' => $path]));
                }
            }

            if (false === is_dir($path)) {
                throw new RuntimeException(r("The path '{path}' is not a directory.", ['path' => $key]));
            }

            if (false === is_writable($path)) {
                throw new RuntimeException(
                    r("Unable to write to '{path}' directory. Check user permissions and/or user mapping.", [
                        'path' => $path,
                    ])
                );
            }

            if (false === is_readable($path)) {
                throw new RuntimeException(
                    r("Unable to read data from '{path}' directory. Check user permissions and/or user mapping.", [
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
                    throw new RuntimeException(r("Unable to create '{path}' directory.", ['path' => $dir]));
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

        $wrap = Container::get(LogSuppressor::class);

        if (null !== ($logfile = Config::get('api.logfile'))) {
            $this->accessLog = $logger->withName(name: 'http')
                ->pushHandler($wrap->withHandler(new StreamHandler($logfile, Level::Info, true)));

            if (true === $inContainer) {
                $this->accessLog->pushHandler($wrap->withHandler(new StreamHandler('php://stderr', Level::Info, true)));
            }
        }

        foreach ($loggers as $name => $context) {
            if (null === ag($context, 'type', null)) {
                throw new RuntimeException(r("Logger '{name}' has no type set.", ['name' => $name]));
            }

            if (true !== (bool)ag($context, 'enabled', false)) {
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
                        $wrap->withHandler(
                            new StreamHandler(
                                ag($context, 'filename'),
                                ag($context, 'level', Level::Info),
                                (bool)ag($context, 'bubble', true),
                            )
                        )
                    );
                    break;
                case 'console':
                    $logger->pushHandler($wrap->withHandler(new ConsoleHandler($this->cliOutput)));
                    break;
                case 'syslog':
                    $logger->pushHandler(
                        $wrap->withHandler(
                            new SyslogHandler(
                                ag($context, 'name', Config::get('name')),
                                ag($context, 'facility', LOG_USER),
                                ag($context, 'level', Level::Info),
                                (bool)Config::get('bubble', true),
                            )
                        )
                    );
                    break;
                default:
                    throw new RuntimeException(r("Logger '{name}' used unknown Logger type '{type}'.", [
                        'type' => $context['type'],
                        'name' => $name
                    ]));
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
                'path' => $uri->getPath(),
                'id' => ag($params, 'X_REQUEST_ID'),
                'ip' => getClientIp($request),
                'agent' => ag($params, 'HTTP_USER_AGENT'),
                'uri' => (string)$uri,
            ],
        ], $context);

        $request = $request->withoutAttribute('INTERNAL_REQUEST');

        if (($attributes = $request->getAttributes()) && count($attributes) >= 1) {
            $context['attributes'] = $attributes;
        }

        if (true === (Config::get('logs.context') || $forceContext)) {
            $this->accessLog->log($level, $message, $context);
        } else {
            $this->accessLog->log($level, r($message, $context));
        }
    }

    private function formatLog(iRequest $request, iResponse $response, string|null $message = null): string
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
            'size' => $response->getBody()->getSize() ?? 0,
            'agent' => ag($request->getServerParams(), 'HTTP_USER_AGENT', '-'),
            'refer' => (string)$refer,
            'message' => $message ?? '-',
        ]);
    }
}
