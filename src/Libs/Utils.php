<?php

/** @noinspection PhpUnhandledExceptionInspection, PhpDocMissingThrowsInspection */

declare(strict_types=1);

use App\Backends\Common\ClientInterface as iClient;
use App\Libs\APIResponse;
use App\Libs\Attributes\Scanner\Attributes as AttributesScanner;
use App\Libs\Attributes\Scanner\Item as ScannerItem;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\DBLayer;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Events\DataEvent;
use App\Libs\Exceptions\AppExceptionInterface;
use App\Libs\Exceptions\DBLayerException;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\ReflectionContainer;
use App\Libs\Guid;
use App\Libs\Initializer;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\Stream;
use App\Libs\Uri;
use App\Libs\UserContext;
use App\Model\Events\Event as EventInfo;
use App\Model\Events\EventListener;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Process\Process;

if (!function_exists('checkIgnoreRule')) {
    /**
     * Check if the given ignore rule is valid.
     *
     * @param string $guid The ignore rule to check.
     * @param UserContext|null $userContext (Optional) The user context.
     *
     * @return bool True if the ignore rule is valid, false otherwise.
     * @throws RuntimeException Throws an exception if the ignore rule is invalid.
     */
    function checkIgnoreRule(string $guid, UserContext|null $userContext = null): bool
    {
        $urlParts = parse_url($guid);

        if (false === is_array($urlParts)) {
            throw new RuntimeException('Invalid ignore rule was given.');
        }

        if (null === ($db = ag($urlParts, 'user'))) {
            throw new RuntimeException('No db source was given.');
        }

        $sources = array_keys(Guid::getSupported());

        if (false === in_array('guid_' . $db, $sources)) {
            throw new RuntimeException(r("Invalid db source name '{db}' was given. Expected values are '{dbs}'.", [
                'db' => $db,
                'dbs' => implode(', ', array_map(fn($f) => after($f, 'guid_'), $sources)),
            ]));
        }

        if (null === ($id = ag($urlParts, 'pass'))) {
            throw new RuntimeException('No external id was given.');
        }

        Guid::validate($db, $id);

        if (null === ($type = ag($urlParts, 'scheme'))) {
            throw new RuntimeException('No type was given.');
        }

        if (false === in_array($type, iState::TYPES_LIST)) {
            throw new RuntimeException(r("Invalid type '{type}' was given. Expected values are '{types}'.", [
                'type' => $type,
                'types' => implode(', ', iState::TYPES_LIST)
            ]));
        }

        if (null === ($backend = ag($urlParts, 'host'))) {
            throw new RuntimeException('No backend was given.');
        }

        if (null !== $userContext) {
            $backends = array_keys($userContext->config->getAll());
        } else {
            $backends = array_keys(Config::get('servers', []));
        }

        if (false === in_array($backend, $backends)) {
            throw new RuntimeException(r("Invalid backend name '{backend}' was given. Expected values are '{list}'.", [
                'backend' => $backend,
                'list' => implode(', ', $backends),
            ]));
        }

        return true;
    }
}

if (!function_exists('runCommand')) {
    /**
     * Run a command.
     *
     * @param string $command The command to run.
     * @param array $args The command arguments.
     * @param bool $asArray (Optional) Whether to return the output as an array.
     * @param array $opts (Optional) Additional options.
     *
     * @return string|array The output of the command.
     */
    function runCommand(string $command, array $args = [], bool $asArray = false, array $opts = []): string|array
    {
        $path = realpath(__DIR__ . '/../../');

        $opts = DataUtil::fromArray($opts);

        set_time_limit(0);

        $process = new Process(
            command: ["{$path}/bin/console", $command, ...$args],
            cwd: $path,
            env: $_ENV,
            timeout: $opts->get('timeout', 3600),
        );

        $output = $asArray ? [] : '';

        $process->run(function ($type, $data) use (&$output, $asArray) {
            if ($asArray) {
                $output[] = $data;
                return;
            }
            $output .= $data;
        });

        return $output;
    }
}

if (!function_exists('tryCatch')) {
    /**
     * Try to execute a callback and catch any exceptions.
     *
     * @param Closure $callback The callback to execute.
     * @param Closure(Throwable):mixed|null $catch (Optional) Executes when an exception is caught.
     * @param Closure|null $finally (Optional) Executes after the callback and catch.
     *
     * @return mixed The result of the callback or the catch. or null if no catch is provided.
     */
    function tryCatch(Closure $callback, Closure|null $catch = null, Closure|null $finally = null): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            return null !== $catch ? $catch($e) : null;
        } finally {
            if (null !== $finally) {
                $finally();
            }
        }
    }
}

if (!function_exists('APIRequest')) {
    /**
     * Make internal request to the API.
     *
     * @param Method|string $method The request method.
     * @param string $path The request path.
     * @param array $json The request body.
     * @param array{ server: array, query: array, headers: array} $opts Additional options.
     *
     * @return APIResponse The response object.
     */
    function APIRequest(Method|string $method, string $path, array $json = [], array $opts = []): APIResponse
    {
        $initializer = Container::get(Initializer::class);

        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);

        $uri = new Uri($path);

        $server = [
            'REQUEST_METHOD' => ($method instanceof Method) ? $method->value : strtoupper($method),
            'SCRIPT_FILENAME' => realpath(__DIR__ . '/../../public/index.php'),
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_URI' => Config::get('api.prefix') . $uri->getPath(),
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => 80,
            'HTTP_USER_AGENT' => Config::get('http.default.options.headers.User-Agent', 'APIRequest'),
            ...ag($opts, 'server', []),
        ];

        $headers = [
            'Host' => 'localhost',
            'Accept' => 'application/json',
            ...ag($opts, 'headers', []),
        ];

        $body = null;

        if (!empty($json)) {
            $body = json_encode($json);
            $headers['CONTENT_TYPE'] = 'application/json';
            $headers['CONTENT_LENGTH'] = strlen($body);
            $server['CONTENT_TYPE'] = $headers['CONTENT_TYPE'];
            $server['CONTENT_LENGTH'] = $headers['CONTENT_LENGTH'];
        }

        $query = ag($opts, 'query', []);

        if (!empty($uri->getQuery())) {
            parse_str($uri->getQuery(), $queryFromPath);
            $query = deepArrayMerge([$queryFromPath, $query]);
        }

        if (!empty($query)) {
            $server['QUERY_STRING'] = http_build_query($query);
        }

        $request = $creator->fromArrays(
            server: $server,
            headers: $headers,
            get: $query,
            post: $json,
            body: $body
        )->withAttribute(Options::INTERNAL_REQUEST, true);

        if (null !== ($callback = ag($opts, 'callback'))) {
            $callback($request);
        }

        $response = $initializer->http($request);

        $statusCode = Status::tryFrom($response->getStatusCode()) ?? Status::SERVICE_UNAVAILABLE;

        if ($response->getBody()->getSize() < 1) {
            return new APIResponse($statusCode, $response->getHeaders());
        }

        $response->getBody()->rewind();

        if (false !== str_contains($response->getHeaderLine('Content-Type'), 'application/json')) {
            try {
                $json = json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $json = [];
            }
            $response->getBody()->rewind();
            return new APIResponse($statusCode, $response->getHeaders(), $json, $response->getBody());
        }

        return new APIResponse($statusCode, $response->getHeaders(), [], $response->getBody());
    }
}

if (!function_exists('getServerColumnSpec')) {
    /**
     * Returns the spec for the given server column.
     *
     * @param string $column
     *
     * @return array The spec for the given column. Otherwise, an empty array.
     */
    function getServerColumnSpec(string $column): array
    {
        static $_serverSpec = null;

        if (null === $_serverSpec) {
            $_serverSpec = require __DIR__ . '/../../config/servers.spec.php';
        }

        foreach ($_serverSpec as $spec) {
            if (ag($spec, 'key') === $column) {
                return $spec;
            }
        }

        return [];
    }
}

if (!function_exists('getEnvSpec')) {
    /**
     * Returns the spec for the given environment variable.
     *
     * @param string $env
     *
     * @return array The spec for the given column. Otherwise, an empty array.
     */
    function getEnvSpec(string $env): array
    {
        static $_envSpec = null;

        if (null === $_envSpec) {
            $_envSpec = require __DIR__ . '/../../config/env.spec.php';
        }

        foreach ($_envSpec as $spec) {
            if (ag($spec, 'key') === $env) {
                return $spec;
            }
        }

        return [];
    }
}

if (!function_exists('parseEnvFile')) {
    /**
     * Parse the environment file, and returns key/value pairs.
     *
     * @param string $file The file to load.
     *
     * @return array<string, string> The environment variables.
     * @throws InvalidArgumentException Throws an exception if the file does not exist.
     */
    function parseEnvFile(string $file): array
    {
        $env = [];

        if (false === file_exists($file)) {
            throw new InvalidArgumentException(r("The file '{file}' does not exist.", ['file' => $file]));
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (true === str_starts_with($line, '#') || false === str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);

            // -- check if value is quoted.
            if ((true === str_starts_with($value, '"') && true === str_ends_with($value, '"')) ||
                (true === str_starts_with($value, "'") && true === str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $value = trim($value);
            if ('' === $value) {
                continue;
            }
            $env[$name] = $value;
        }

        return $env;
    }
}

if (!function_exists('loadEnvFile')) {
    /**
     * Load the environment file.
     *
     * @param string $file The file to load.
     * @param bool $usePutEnv (Optional) Whether to use putenv.
     * @param bool $override (Optional) Whether to override existing values.
     *
     * @return void
     */
    function loadEnvFile(string $file, bool $usePutEnv = false, bool $override = true): void
    {
        try {
            $env = parseEnvFile($file);

            if (count($env) < 1) {
                return;
            }
        } catch (InvalidArgumentException) {
            return;
        }

        foreach ($env as $name => $value) {
            if (false === $override && true === array_key_exists($name, $_ENV)) {
                continue;
            }

            if (true === $usePutEnv) {
                putenv("{$name}={$value}");
            }

            $_ENV[$name] = $value;

            if (!str_starts_with($name, 'HTTP_')) {
                $_SERVER[$name] = $value;
            }
        }
    }
}

if (!function_exists('isTaskWorkerRunning')) {
    /**
     * Check if the task worker is running. This function is only available when running in a container.
     *
     * @param string $pidFile (Optional) The PID file to check.
     * @param bool $ignoreContainer (Optional) Whether to ignore the container check.
     *
     * @return array{ status: bool, message: string }
     */
    function isTaskWorkerRunning(string $pidFile = '/tmp/ws-job-runner.pid', bool $ignoreContainer = false): array
    {
        if (false === $ignoreContainer && !inContainer()) {
            return [
                'status' => true,
                'restartable' => false,
                'message' => 'We can only track the task worker status when running in a container.'
            ];
        }

        if (true === (bool)env('DISABLE_CRON', false)) {
            return [
                'status' => false,
                'restartable' => false,
                'message' => "Task runner is disabled via 'DISABLE_CRON' environment variable."
            ];
        }

        if (!file_exists($pidFile)) {
            return [
                'status' => false,
                'restartable' => true,
                'message' => 'No PID file was found - Likely means task worker failed to run.'
            ];
        }

        try {
            $pid = trim((string)new Stream($pidFile));
        } catch (Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }

        switch (PHP_OS) {
            case 'Linux':
                {
                    $status = file_exists(r('/proc/{pid}/status', ['pid' => $pid]));
                }
                break;
            case 'WINNT':
                {
                    // -- Windows does not have a /proc directory so we need different way to get the status.
                    @exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
                    // -- windows doesn't return 0 if the process is not found. we need to parse the output.
                    $status = false;
                    foreach ($output as $line) {
                        if (false === str_contains($line, $pid)) {
                            continue;
                        }
                        $status = true;
                        break;
                    }
                }
                break;
            default:
                $status = false;
                break;
        }

        if (true === $status) {
            return ['status' => true, 'restartable' => true, 'message' => 'Task worker is running.'];
        }

        return [
            'status' => false,
            'restartable' => true,
            'message' => r("Found PID '{pid}' in file, but it seems the process is not active.", [
                'pid' => $pid
            ])
        ];
    }
}

if (!function_exists('restartTaskWorker')) {
    /**
     * Restart the task worker.
     *
     * @param bool $ignoreContainer (Optional) Whether to ignore the container check.
     * @param bool $force (Optional) Whether to force kill the task worker.
     *
     * @return array{ status: bool, message: string }
     */
    function restartTaskWorker(bool $ignoreContainer = false, bool $force = false): array
    {
        if (false === $ignoreContainer && !inContainer()) {
            return [
                'status' => true,
                'restartable' => false,
                'message' => 'We can only restart the task worker when running in a container.'
            ];
        }

        $pidFile = '/tmp/ws-job-runner.pid';

        if (true === file_exists($pidFile)) {
            try {
                $pid = trim((string)new Stream($pidFile));
            } catch (Throwable $e) {
                return ['status' => false, 'restartable' => true, 'message' => $e->getMessage()];
            }

            if (file_exists(r('/proc/{pid}/status', ['pid' => $pid]))) {
                @posix_kill((int)$pid, $force ? 9 : 1);
            }

            clearstatcache(true, $pidFile);

            if (true === file_exists($pidFile)) {
                @unlink($pidFile);
            }
        }

        $process = Process::fromShellCommandline('/opt/bin/job-runner 2>&1 &');
        $process->run();

        return [
            'status' => $process->isSuccessful(),
            'restartable' => true,
            'message' => $process->isSuccessful() ? 'Task worker restarted.' : $process->getErrorOutput(),
        ];
    }
}

if (!function_exists('findSideCarFiles')) {
    function findSideCarFiles(SplFileInfo $path): array
    {
        $list = [];

        $possibleExtensions = ['jpg', 'jpeg', 'png'];
        foreach ($possibleExtensions as $ext) {
            if (file_exists($path->getPath() . "/poster.{$ext}")) {
                $list[] = $path->getPath() . "/poster.{$ext}";
            }

            if (file_exists($path->getPath() . "/fanart.{$ext}")) {
                $list[] = $path->getPath() . "/fanart.{$ext}";
            }
        }

        $pat = $path->getPath() . '/' . before($path->getFilename(), '.');
        $pat = preg_replace('/([*?\[])/', '[$1]', $pat);

        $glob = glob($pat . '*');

        if (false === $glob) {
            return $list;
        }

        foreach ($glob as $item) {
            $item = new SplFileInfo($item);

            if (!$item->isFile() || $item->getFilename() === $path->getFilename()) {
                continue;
            }

            $list[] = $item->getRealPath();
        }

        return $list;
    }
}

if (!function_exists('array_change_key_case_recursive')) {
    function array_change_key_case_recursive(array $input, int $case = CASE_LOWER): array
    {
        if (!in_array($case, [CASE_UPPER, CASE_LOWER])) {
            throw new RuntimeException("Case parameter '{$case}' is invalid.");
        }

        $input = array_change_key_case($input, $case);

        foreach ($input as $key => $array) {
            if (is_array($array)) {
                $input[$key] = array_change_key_case_recursive($array, $case);
            }
        }

        return $input;
    }
}

if (!function_exists('getMimeType')) {
    function getMimeType(string $file): string
    {
        static $fileInfo = null;

        if (null === $fileInfo) {
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        }

        return $fileInfo->file($file);
    }
}

if (!function_exists('getExtension')) {
    function getExtension(string $filename): string
    {
        return new SplFileInfo($filename)->getExtension();
    }
}

if (!function_exists('ffprobe_file')) {
    /**
     * Get FFProbe Info.
     *
     * @param string $path
     * @param iCache|null $cache
     * @return array
     * @noinspection PhpDocMissingThrowsInspection
     */
    function ffprobe_file(string $path, iCache|null $cache = null): array
    {
        $cacheKey = md5($path . filesize($path));

        if (null !== $cache && $cache->has($cacheKey)) {
            $data = $cache->get($cacheKey);
            return (is_array($data) ? $data : json_decode($data, true));
        }

        $mimeType = getMimeType($path);

        $isTs = str_ends_with($path, '.ts') && 'application/octet-stream' === $mimeType;
        if (!str_starts_with($mimeType, 'video/') && !str_starts_with($mimeType, 'audio/') && !$isTs) {
            throw new RuntimeException(sprintf("Unable to run ffprobe on '%s'", $path));
        }

        $process = new Process([
            'ffprobe',
            '-v',
            'quiet',
            '-print_format',
            'json',
            '-show_format',
            '-show_streams',
            'file:' . basename($path)
        ], cwd: dirname($path));

        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf("Failed to run ffprobe on '%s'. %s", $path, $process->getErrorOutput()));
        }

        $json = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $data = array_change_key_case_recursive($json, CASE_LOWER);

        $cache?->set($cacheKey, $data, new DateInterval('PT24H'));

        return $data;
    }
}

if (!function_exists('generateUUID')) {
    function generateUUID(string|int|null $prefix = null): string
    {
        $prefixUUID = '';

        if (null !== $prefix) {
            $prefixUUID = $prefix ? $prefix . '-' : '';
        }

        return $prefixUUID . Ramsey\Uuid\Uuid::uuid6()->toString();
    }
}

if (!function_exists('cacheableItem')) {
    /**
     * Get Item From Cache or call Callable and cache result.
     *
     * @param string $key
     * @param Closure $function
     * @param DateInterval|int|null $ttl
     * @param bool $ignoreCache
     * @param array $opts
     *
     * @return mixed
     */
    function cacheableItem(
        string $key,
        Closure $function,
        DateInterval|int|null $ttl = null,
        bool $ignoreCache = false,
        array $opts = [],
    ): mixed {
        $cache = $opts[iCache::class] ?? Container::get(iCache::class);

        if (!$ignoreCache && $cache->has($key)) {
            return $cache->get($key);
        }

        $reflectContainer = $opts[ReflectionContainer::class] ?? Container::get(ReflectionContainer::class);
        $item = $reflectContainer->call($function);

        if (null === $ttl) {
            $ttl = new DateInterval('PT300S');
        }

        $cache->set($key, $item, $ttl);

        return $item;
    }
}

if (!function_exists('registerEvents')) {
    /**
     * Register events.
     */
    function registerEvents(bool $ignoreCache = false): void
    {
        static $alreadyRegistered = false;

        if (false !== $alreadyRegistered) {
            return;
        }

        $logger = Container::get(iLogger::class);
        $dispatcher = Container::get(EventDispatcherInterface::class);
        assert($dispatcher instanceof EventDispatcher);

        /** @var array<ScannerItem> $list */
        $list = cacheableItem(
            'event_listeners',
            fn() => AttributesScanner::scan(Config::get('events.listeners.locations', []))->for(EventListener::class),
            Config::get('events.listeners.cache', fn() => new DateInterval('PT1H')),
            $ignoreCache
        );

        foreach ($list as $item) {
            $dispatcher->addListener(ag($item->getData(), 'event'), $item->call(...));
        }

        if (null !== ($eventsFile = Config::get('events.listeners.file'))) {
            try {
                foreach (require $eventsFile as $event) {
                    $dispatcher->addListener(ag($event, 'on'), ag($event, 'callable'));
                }
            } catch (Throwable $e) {
                $logger->error($e->getMessage(), []);
            }
        }

        $alreadyRegistered = true;
    }
}

if (!function_exists('queueEvent')) {
    /**
     * Queue Event.
     *
     * @param string $event Event name.
     * @param array $data Event data.
     * @param array $opts Options.
     *
     * @return EventInfo
     */
    function queueEvent(string $event, array $data = [], array $opts = []): EventInfo
    {
        $repo = ag($opts, EventsRepository::class, fn() => Container::get(EventsRepository::class));
        assert($repo instanceof EventsRepository);

        $item = null;
        if (null !== ($reference = ag($opts, EventsTable::COLUMN_REFERENCE))) {
            $criteria = [];
            $isUnique = (bool)ag($opts, 'unique', false);

            if (false === $isUnique) {
                $criteria[EventsTable::COLUMN_STATUS] = EventStatus::PENDING->value;
            }

            if (null !== ($refItem = $repo->findByReference($reference, $criteria)) && true === $isUnique) {
                $repo->remove($refItem);
            } else {
                $item = $refItem;
            }

            unset($refItem);
        }

        $item = $item ?? $repo->getObject([]);
        $item->event = $event;
        $item->status = EventStatus::PENDING;
        $item->event_data = $data;
        if (ag_exists($opts, EventsTable::COLUMN_CREATED_AT)) {
            $item->created_at = $opts[EventsTable::COLUMN_CREATED_AT];
        } else {
            $item->created_at = makeDate();
        }

        $item->options = [
            'class' => ag($opts, 'class', DataEvent::class),
        ];

        if (ag_exists($opts, EventsTable::COLUMN_OPTIONS) && is_array($opts[EventsTable::COLUMN_OPTIONS])) {
            $item->options = array_replace_recursive($opts[EventsTable::COLUMN_OPTIONS], $item->options);
        }

        if (ag_exists($opts, Options::CONTEXT_USER) && !empty($opts[Options::CONTEXT_USER])) {
            $item->options[Options::CONTEXT_USER] = $opts[Options::CONTEXT_USER];
        }
        if (ag_exists($opts, Options::DELAY_BY) && !empty($opts[Options::DELAY_BY])) {
            $item->options[Options::DELAY_BY] = $opts[Options::DELAY_BY];
        }

        if ($reference) {
            $item->reference = $reference;
        }

        try {
            $id = $repo->save($item);
            $item->id = $id;
        } catch (PDOException $e) {
            // sometimes our sqlite db get locked due to multiple writes.
            // and the db retry logic will time out, to save the event we fall back to cache store.
            if (false === ag_exists($opts, 'cached') && false !== stripos($e->getMessage(), 'database is locked')) {
                $cache = Container::get(iCache::class);
                $events = $cache->get('events', []);
                $opts[EventsTable::COLUMN_CREATED_AT] = makeDate();
                $opts['cached'] = true;
                $events[] = ['event' => $event, 'data' => $data, 'opts' => $opts];
                $cache->set('events', $events, new DateInterval('PT1H'));
            } else {
                throw $e;
            }
        }

        return $item;
    }
}

if (!function_exists('getPagination')) {
    function getPagination(iRequest $request, int $page = 1, int $perpage = 0, array $options = []): array
    {
        $page = (int)($request->getQueryParams()['page'] ?? $page);

        if (0 === $perpage) {
            $perpage = 25;
        }

        if (false === array_key_exists('force_perpage', $options)) {
            $perpage = (int)($request->getQueryParams()['perpage'] ?? $perpage);
        }

        $start = (($page <= 2) ? ((1 === $page) ? 0 : $perpage) : $perpage * ($page - 1));
        $start = (!$page) ? 0 : $start;

        return [$page, $perpage, $start];
    }
}

if (!function_exists('getBackend')) {
    /**
     * Retrieves the backend client for the specified name.
     *
     * @param string $name The name of the backend.
     * @param array $config (Optional) Override the default configuration for the backend.
     * @param ConfigFile|null $configFile (Optional) The configuration file to use.
     * @param array $options (Optional) Additional options.
     *
     * @return iClient The backend client instance.
     * @throws RuntimeException If no backend with the specified name is found.
     */
    function getBackend(
        string $name,
        array $config = [],
        ConfigFile|null $configFile = null,
        array $options = []
    ): iClient {
        $configFile = $configFile ?? ConfigFile::open(Config::get('backends_file'), 'yaml');

        if (null === $configFile->get("{$name}.type", null)) {
            throw new RuntimeException(r("No backend named '{backend}' was found.", ['backend' => $name]));
        }

        $default = $configFile->get($name);
        $default['name'] = $name;
        $data = array_replace_recursive($default, $config);

        return makeBackend(backend: $data, name: $name, options: $options);
    }
}

if (!function_exists('lw')) {
    /**
     * log wrapper.
     *
     * The use case for this wrapper is to enhance the log context with db exception information.
     * All logs should be wrapped with this function. it will probably be enhanced to include further context.
     * in the future.
     *
     * @param string $message The log message.
     * @param array $context The log context.
     * @param Throwable|null $e The exception.
     *
     * @return array{ message: string, context: array} The wrapped log message and context.
     */
    function lw(string $message, array $context, Throwable|null $e = null): array
    {
        if (null === $e) {
            return [
                'message' => $message,
                'context' => $context,
            ];
        }

        if (true === ($e instanceof DBLayerException)) {
            $context[DBLayer::class] = [
                'query' => $e->getQueryString(),
                'bind' => $e->getQueryBind(),
                'error' => $e->errorInfo ?? [],
            ];
        }

        if (true === ($e instanceof AppExceptionInterface) && $e->hasContext()) {
            $context[AppExceptionInterface::class] = $e->getContext();
        }

        return [
            'message' => $message,
            'context' => $context,
        ];
    }
}

if (!function_exists('timeIt')) {
    /**
     * Time the execution of a function.
     *
     * @param Closure $function The function to time.
     * @param string $name The name of the function.
     * @param int $round (Optional) The number of decimal places to round to.
     *
     * @return string
     */
    function timeIt(Closure $function, string $name, int $round = 6): string
    {
        $start = microtime(true);
        $function();
        $end = microtime(true);

        return r("Execution time is '{time}' for '{name}'", [
            'name' => $name,
            'time' => round($end - $start, $round),
        ]);
    }
}

if (!function_exists('deletePath')) {
    /**
     * Delete the contents of given path.
     *
     * @param string $path The path to delete.
     * @param iLogger|null $logger The logger instance.
     * @param bool $dryRun (Optional) Whether to perform a dry run.
     *
     * @return bool Whether the operation was successful.
     */
    function deletePath(string $path, iLogger|null $logger = null, bool $dryRun = false): bool
    {
        if (false === is_dir($path)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            iterator: new RecursiveDirectoryIterator(
                directory: $path,
                flags: RecursiveDirectoryIterator::SKIP_DOTS
            ),
            mode: RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if (null !== $logger) {
                $context = [
                    'path' => $item->getPathname(),
                    'type' => $item->isDir() ? 'directory' : 'file'
                ];
                $logger->info("Removing {type} '{path}'.", $context);
            }

            if (true === $dryRun) {
                continue;
            }

            if (true === $item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        return true;
    }
}

if (!function_exists('normalizeName')) {
    /**
     * Normalize the name to be in [a-z_0-9] format.
     *
     * @param string $name The name to normalize.
     * @param iLogger |null $logger (Optional) The logger instance.
     *
     * @return string The normalized name.
     */
    function normalizeName(string $name, iLogger|null $logger = null, array $opts = []): string
    {
        if (true === ctype_digit($name)) {
            $newName = 'user_' . $name;
        } else {
            $newName = strtolower($name);
            $newName = preg_replace('/[^a-z0-9_]/', '_', $newName);
            $newName = trim($newName);
        }

        if ($newName !== $name && null !== $logger) {
            $logger->notice(ag($opts, 'log_message', "Normalized '{name}' to '{new_name}'."), [
                'name' => $name,
                'new_name' => $newName,
                ...ag($opts, 'context', [])
            ]);
        }

        return $newName;
    }
}

if (!function_exists('compress_files')) {
    /**
     * Compress files.
     *
     * @param string $to The destination to save the compressed file.
     * @param array $files The files to compress.
     * @param array $opts (Optional) Additional options.
     *
     * @return bool Whether the files were compressed.
     */
    function compress_files(string $to, array $files, array $opts = []): bool
    {
        $zip = new ZipArchive();

        if (null !== ($affix = ag($opts, 'affix'))) {
            $to = r("{to}.{affix}", ['to' => $to, 'affix' => $affix]);
        }

        if (true !== $zip->open($to, ZipArchive::CREATE)) {
            throw new InvalidArgumentException(r("Unable to open archive '{archive}'.", ['archive' => $to]));
        }

        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();

        return true;
    }
}

if (!function_exists('uncompressed_file')) {
    /**
     * Uncompress a file.
     *
     * @param string $file The file to uncompress.
     * @param string|null $destination The destination to uncompress the file to. If not provided, it will be uncompress
     * to the same directory as the file.
     *
     * @return bool Whether the file was uncompressed.
     * @noinspection PhpUnused
     */
    function uncompress_file(string $file, string|null $destination = null): bool
    {
        $zip = new ZipArchive();

        if (true !== $zip->open($file)) {
            return false;
        }

        $destination = $destination ?? dirname($file);

        $zip->extractTo($destination);
        $zip->close();

        return true;
    }
}

if (!function_exists('readFileFromArchive')) {
    /**
     * Read file from archive.
     * @param string $archive The archive file.
     * @param string $file The file to read.
     *
     * @return array The stream and the ZipArchive instance.
     */
    function readFileFromArchive(string $archive, string $file): array
    {
        $zip = new ZipArchive();

        if (true !== $zip->open($archive)) {
            throw new InvalidArgumentException(r("Unable to open archive '{archive}'.", ['archive' => $archive]));
        }

        if (true === str_contains($file, "*")) {
            $found = false;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $zip_file = $zip->getNameIndex($i);
                if (true === fnmatch($file, $zip_file)) {
                    $file = $zip_file;
                    $found = true;
                    break;
                }
            }

            if (false === $found) {
                throw new InvalidArgumentException(r("Unable to find file '{match}' in archive '{archive}'.", [
                    'archive' => $archive,
                    'match' => $file,
                ]));
            }
        }

        if (false === ($stream = $zip->getStream($file))) {
            $zip->close();
            throw new InvalidArgumentException(r("Unable to read file '{file}' from archive '{archive}'.", [
                'file' => $file,
                'archive' => $archive,
            ]));
        }

        // -- we return the zip file to not lose the reference to it, thus we risk losing the stream.
        return [Stream::make($stream, 'r'), $zip];
    }
}

if (!function_exists('perUserDb')) {
    /**
     * Per User Database.
     *
     * @param string $user The username.
     *
     * @return iDB new mapper instance.
     */
    function perUserDb(string $user): iDB
    {
        $path = fixPath(r("{path}/users/{user}", ['path' => Config::get('path'), 'user' => $user]));

        if (false === file_exists($path)) {
            if (false === @mkdir($path, 0755, true) && false === is_dir($path)) {
                throw new RuntimeException(r("Unable to create '{path}' directory.", ['path' => $path]));
            }
        }

        $dbFile = fixPath(r("{path}/user.db", ['path' => $path]));
        $inTestMode = true === (defined('IN_TEST_MODE') && true === IN_TEST_MODE);
        $dsn = r('sqlite:{src}', ['src' => $inTestMode ? ':memory:' : $dbFile]);

        if (false === $inTestMode) {
            $changePerm = !file_exists($dbFile);
        }

        $pdo = new PDO(dsn: $dsn, options: Config::get('database.options', []));

        if (!$inTestMode && $changePerm && inContainer() && 644 !== (int)(decoct(fileperms($dbFile) & 0644))) {
            @chmod($dbFile, 0644);
        }

        foreach (Config::get('database.exec', []) as $cmd) {
            $pdo->exec($cmd);
        }

        $db = Container::get(iDB::class)->with(db: Container::get(DBLayer::class)->withPDO($pdo));

        if (false === $db->isMigrated()) {
            $db->migrations(iDB::MIGRATE_UP);
            $db->ensureIndex();
            $db->migrateData(Config::get('database.version'), Container::get(iLogger::class));
        }

        return $db;
    }
}

if (!function_exists('perUserConfig')) {
    /**
     * Return user backends config.
     *
     * @param string $user The username.
     *
     * @return ConfigFile new mapper instance.
     */
    function perUserConfig(string $user): ConfigFile
    {
        $path = fixPath(r("{path}/users/{user}", ['path' => Config::get('path'), 'user' => $user]));
        if (false === file_exists($path)) {
            if (false === @mkdir($path, 0755, true) && false === is_dir($path)) {
                throw new RuntimeException(r("Unable to create '{path}' directory.", ['path' => $path]));
            }
        }

        return ConfigFile::open(fixPath(r("{path}/servers.yaml", ['path' => $path])), 'yaml', autoCreate: true);
    }
}

if (!function_exists('perUserCacheAdapter')) {
    function perUserCacheAdapter(string $user): CacheInterface
    {
        if (true === (bool)env('WS_CACHE_NULL', false)) {
            return new Psr16Cache(new NullAdapter());
        }

        if (true === (defined('IN_TEST_MODE') && true === IN_TEST_MODE)) {
            return new Psr16Cache(new ArrayAdapter());
        }

        $ns = getAppVersion();
        $ns .= isValidName($user) ? ".{$user}" : '.' . md5($user);

        try {
            $backend = new RedisAdapter(redis: Container::get(Redis::class), namespace: $ns);
        } catch (Throwable) {
            // -- in case of error, fallback to file system cache.
            $path = fixPath(r("{path}/users/{user}/cache", ['path' => Config::get('path'), 'user' => $user]));
            if (false === file_exists($path)) {
                if (false === @mkdir($path, 0755, true) && false === is_dir($path)) {
                    throw new RuntimeException(
                        r("Unable to create per user cache '{path}' directory.", ['path' => $path])
                    );
                }
            }
            $backend = new FilesystemAdapter(namespace: $ns, directory: $path);
        }

        return new Psr16Cache($backend);
    }
}

if (!function_exists('getUsersContext')) {
    /**
     * Retrieves users configuration and related classes.
     *
     * @param iImport $mapper Import mapper instance.
     * @param iLogger $logger logger instance.
     * @param array $opts (Optional) Additional options.
     *
     * @return array<array-key, UserContext> The user data.
     * @throws RuntimeException If the users directory is not readable.
     */
    function getUsersContext(iImport $mapper, iLogger $logger, array $opts = []): array
    {
        $dbOpts = ag($opts, iDB::class, []);

        $configs = [
            'main' => new UserContext(
                name: 'main',
                config: ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true),
                mapper: $mapper,
                cache: Container::get(iCache::class),
                db: Container::get(iDB::class)->setOptions($dbOpts),
            )
        ];

        if (true === (bool)ag($opts, 'main_user_only', false)) {
            return $configs;
        }

        if (true === (bool)ag($opts, 'no_main_user', false)) {
            $configs = [];
        }

        $usersDir = Config::get('path') . '/users';

        if (false === is_dir($usersDir)) {
            return $configs;
        }

        if (false === is_readable($usersDir)) {
            throw new RuntimeException(r("Unable to read '{path}' directory.", [
                'path' => $usersDir
            ]));
        }

        foreach (new DirectoryIterator(Config::get('path') . '/users') as $path) {
            if ($path->isDot() || false === $path->isDir()) {
                continue;
            }

            $config = perUserConfig($path->getBasename());

            $userName = $path->getBasename();
            $perUserCache = perUserCacheAdapter($userName);
            $db = perUserDb($userName);
            if (count($dbOpts) > 0) {
                $db->setOptions($dbOpts);
            }

            $mapper = $mapper->withDB($db)
                ->withCache($perUserCache)
                ->withLogger($logger)
                ->withOptions(array_replace_recursive($mapper->getOptions(), [Options::ALT_NAME => $userName]));
            assert($mapper instanceof iImport);

            $configs[$userName] = new UserContext(
                name: $userName,
                config: $config,
                mapper: $mapper,
                cache: $perUserCache,
                db: $db,
            );
        }

        return $configs;
    }
}

if (!function_exists('getUserContext')) {
    /**
     * Get the user context.
     *
     * @param string $user The username.
     * @param iImport $mapper The mapper instance.
     * @param iLogger $logger The logger instance.
     *
     * @return UserContext The user context.
     * @throws RuntimeException If the user is not found.
     */
    function getUserContext(string $user, iImport $mapper, iLogger $logger): UserContext
    {
        $users = getUsersContext($mapper, $logger);
        if (false === in_array($user, array_keys($users), true)) {
            $logger->error("User '{user}' not found.", [
                'user' => $user,
                'users' => array_keys($users)
            ]);
            throw new RuntimeException(r("User '{user}' not found.", ['user' => $user]), 1001);
        }

        return ag($users, $user);
    }
}


if (!function_exists('exception_log')) {
    /**
     * Add standard way to access exception in log context.
     *
     * @param Throwable $e The exception.
     *
     * @return array{error: array,excpetion: array} The exception formatted in standard way.
     */
    function exception_log(Throwable $e): array
    {
        return [
            'error' => [
                'kind' => $e::class,
                'line' => $e->getLine(),
                'message' => $e->getMessage(),
                'file' => after($e->getFile(), ROOT_PATH),
            ],
            'exception' => [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => $e::class,
                'message' => $e->getMessage(),
                'trace' => $e->getTrace(),
            ],
        ];
    }
}
