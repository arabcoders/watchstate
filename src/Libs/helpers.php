<?php

/** @noinspection PhpUnhandledExceptionInspection, PhpDocMissingThrowsInspection */

declare(strict_types=1);

use App\Backends\Common\Cache as BackendCache;
use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Attributes\Route\Route;
use App\Libs\Attributes\Scanner\Attributes as AttributesScanner;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\Date;
use App\Libs\Options;
use App\Libs\Response;
use App\Libs\Stream;
use App\Libs\Uri;
use App\Libs\UserContext;
use App\Listeners\ProcessPushEvent;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use Monolog\Utils;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Message\StreamInterface as iStream;
use Psr\Http\Message\UriInterface as iUri;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

if (!function_exists('env')) {
    /**
     * Get the value of an environment variable.
     *
     * @param string $key The key of the environment variable.
     * @param mixed $default The default value to return if the environment variable is not found.
     *
     * @return mixed The value of the environment variable, or the default value if not found.
     */
    function env(string $key, mixed $default = null): mixed
    {
        if (false === ($value = $_ENV[$key] ?? getenv($key))) {
            return getValue($default);
        }

        return match (is_string($value) ? strtolower($value) : $value) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)' => null,
            default => $value,
        };
    }
}

if (!function_exists('getValue')) {
    /**
     * Get the value of a variable.
     *
     * @param mixed $var The variable to get the value from.
     *
     * @return mixed The value of the variable.
     */
    function getValue(mixed $var): mixed
    {
        return ($var instanceof Closure) ? $var() : $var;
    }
}

if (!function_exists('makeDate')) {
    /**
     * Make date time object.
     *
     * @param string|int|DateTimeInterface $date Defaults to now
     * @param string|DateTimeZone|null $tz For given $date, not for display.
     *
     * @return Date
     */
    function makeDate(string|int|DateTimeInterface $date = 'now', DateTimeZone|string|null $tz = null): Date
    {
        if ((is_string($date) || is_int($date)) && ctype_digit((string)$date)) {
            $date = '@' . $date;
        }

        if (null === $tz) {
            $tz = date_default_timezone_get();
        }

        if (!($tz instanceof DateTimeZone)) {
            $tz = new DateTimeZone($tz);
        }

        if (true === ($date instanceof DateTimeInterface)) {
            $date = $date->format(DateTimeInterface::ATOM);
        }

        return new Date($date)->setTimezone($tz);
    }
}

if (!function_exists('ag')) {
    /**
     * Get value from array or object using dot notation.
     *
     * @param array|object $array The array or object to search in.
     * @param string|array|int|null $path The key path to get the value from.
     * @param mixed $default The default value to return if the key path doesn't exist.
     * @param string $separator The separator used in the key path (default is '.').
     *
     * @return mixed The value at the specified key path, or the default value if not found.
     */
    function ag(array|object $array, string|array|int|null $path, mixed $default = null, string $separator = '.'): mixed
    {
        if (empty($path)) {
            return $array;
        }

        if (!is_array($array)) {
            $array = get_object_vars($array);
        }

        if (is_array($path)) {
            foreach ($path as $key) {
                $val = ag($array, $key, '_not_set');
                if ('_not_set' === $val) {
                    continue;
                }
                return $val;
            }
            return getValue($default);
        }

        if (null !== ($array[$path] ?? null)) {
            return $array[$path];
        }

        if (!str_contains($path, $separator)) {
            return $array[$path] ?? getValue($default);
        }

        foreach (explode($separator, $path) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return getValue($default);
            }
        }

        return $array;
    }
}

if (!function_exists('ag_sets')) {
    /**
     * Set multiple key paths in an array using "dot" notation.
     *
     * @param array $array The array to set the values in.
     * @param array $path The key paths to set the values at.
     * @param string $separator The separator used in the key paths (default is '.').
     *
     * @return array The modified array.
     */
    function ag_sets(array $array, array $path, string $separator = '.'): array
    {
        foreach ($path as $key => $value) {
            $array = ag_set($array, $key, $value, $separator);
        }
        return $array;
    }
}

if (!function_exists('ag_set')) {
    /**
     * Set an array item to a given value using "dot" notation.
     *
     * If no key is given to the method, the entire array will be replaced.
     *
     * @param array $array
     * @param string $path
     * @param mixed $value
     * @param string $separator
     *
     * @return array return modified array.
     */
    function ag_set(array $array, string $path, mixed $value, string $separator = '.'): array
    {
        $keys = explode($separator, $path);

        $at = &$array;

        while (count($keys) > 0) {
            if (1 === count($keys)) {
                if (is_array($at)) {
                    $at[array_shift($keys)] = $value;
                } else {
                    throw new RuntimeException("Can not set value at this path ($path) because its not array.");
                }
            } else {
                $path = array_shift($keys);
                if (!isset($at[$path])) {
                    $at[$path] = [];
                }
                $at = &$at[$path];
            }
        }

        return $array;
    }
}

if (!function_exists('ag_exists')) {
    /**
     * Determine if the given key exists in the provided array.
     *
     * @param array $array The array to search in.
     * @param string|int $path The key path to check for.
     * @param string $separator The separator used in the key path (default is '.').
     *
     * @return bool True if the key path exists, false otherwise.
     */
    function ag_exists(array $array, string|int $path, string $separator = '.'): bool
    {
        if (isset($array[$path])) {
            return true;
        }

        foreach (explode($separator, (string)$path) as $lookup) {
            if (isset($array[$lookup])) {
                $array = $array[$lookup];
            } else {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('ag_delete')) {
    /**
     * Delete given key path.
     *
     * @param array $array The array to search in.
     * @param int|string|array $path The key path to delete.
     * @param string $separator The separator used in the key path (default is '.').
     *
     * @return array The modified array.
     */
    function ag_delete(array $array, string|int|array $path, string $separator = '.'): array
    {
        if (is_array($path)) {
            foreach ($path as $key) {
                $array = ag_delete($array, $key, $separator);
            }
            return $array;
        }

        if (array_key_exists($path, $array)) {
            unset($array[$path]);

            return $array;
        }

        if (is_int($path)) {
            // -- if the path is int, and it's exists, it should have been caught by
            // -- the first if condition. So, we can safely return the array as is.
            return $array;
        }

        $items = &$array;

        $segments = explode($separator, $path);

        $lastSegment = array_pop($segments);

        foreach ($segments as $segment) {
            if (!isset($items[$segment]) || !is_array($items[$segment])) {
                continue;
            }

            $items = &$items[$segment];
        }

        if (null !== $lastSegment && array_key_exists($lastSegment, $items)) {
            unset($items[$lastSegment]);
        }

        return $array;
    }
}

if (!function_exists('fixPath')) {
    /**
     * Fix the given file path by removing any trailing directory separators.
     *
     * @param string $path The file path to fix.
     *
     * @return string The fixed file path.
     */
    function fixPath(string $path): string
    {
        return rtrim(implode(DIRECTORY_SEPARATOR, explode(DIRECTORY_SEPARATOR, $path)), DIRECTORY_SEPARATOR);
    }
}

if (!function_exists('fsize')) {
    /**
     * Calculate the file size in human-readable format.
     *
     * @param int|string $bytes The size of the file in bytes (default is 0).
     * @param bool $showUnit Whether to include the unit in the result (default is true).
     * @param int $decimals The number of decimal places to round the result (default is 2).
     * @param int $mod The base value used for conversion (default is 1000).
     *
     * @return string The file size in a human-readable format.
     */
    function fsize(string|int $bytes = 0, bool $showUnit = true, int $decimals = 2, int $mod = 1000): string
    {
        $sz = 'BKMGTP';
        $factor = floor((strlen((string)$bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", (int)($bytes) / ($mod ** $factor)) . ($showUnit ? $sz[(int)$factor] : '');
    }
}

if (!function_exists('saveWebhookPayload')) {
    /**
     * Save webhook payload to stream.
     *
     * @param iState $entity Entity object.
     * @param iRequest $request Request object.
     * @param iStream|null $file When given a stream, it will be used to write payload.
     */
    function saveWebhookPayload(iState $entity, iRequest $request, iStream|null $file = null): void
    {
        $content = [
            'request' => [
                'server' => $request->getServerParams(),
                'body' => (string)$request->getBody(),
                'query' => $request->getQueryParams(),
            ],
            'parsed' => $request->getParsedBody(),
            'attributes' => $request->getAttributes(),
            'entity' => $entity->getAll(),
        ];

        $stream = $file ?? new Stream(
            r('{path}/webhooks/' . Config::get('webhook.file_format', 'webhook.{backend}.{event}.{id}.json'), [
                'path' => Config::get('tmpDir'),
                'time' => (string)time(),
                'backend' => $entity->via,
                'event' => ag($entity->getExtra($entity->via), 'event', 'unknown'),
                'id' => ag($request->getServerParams(), 'X_REQUEST_ID', time()),
                'date' => makeDate('now')->format('Ymd'),
                'context' => $content,
            ]),
            'w'
        );

        $stream->write(
            json_encode(
                value: $content,
                flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE
            )
        );

        if (null === $file) {
            $stream->close();
        }
    }
}

if (!function_exists('saveRequestPayload')) {
    /**
     * Save request payload to stream.
     *
     * @param iRequest $request Request object.
     * @param iStream|null $file When given a stream, it will be used to write payload.
     */
    function saveRequestPayload(iRequest $request, iStream|null $file = null): void
    {
        $content = [
            'query' => $request->getQueryParams(),
            'parsed' => $request->getParsedBody(),
            'server' => $request->getServerParams(),
            'body' => (string)$request->getBody(),
            'attributes' => $request->getAttributes(),
        ];

        $stream = $file ?? new Stream(r('{path}/debug/request.{time}.{id}.json', [
            'path' => Config::get('tmpDir'),
            'time' => time(),
            'id' => ag($request->getServerParams(), 'X_REQUEST_ID', (string)time()),
        ]), 'w');

        $stream->write(
            json_encode(
                value: $content,
                flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE
            )
        );

        if (null === $file) {
            $stream->close();
        }
    }
}

if (!function_exists('api_response')) {
    /**
     * Create a raw API response.
     *
     * @param Status|int $status Optional. The HTTP status code. Default is {@see Status::OK}.
     * @param array|iStream|null $body The body to include in the response body.
     * @param array $headers Optional. Additional headers to include in the response.
     * @param string|null $reason Optional. The reason phrase to include in the response. Default is null.
     *
     * @return iResponse A PSR-7 compatible response object.
     */
    function api_response(
        Status|int $status = Status::OK,
        array|null|iStream $body = null,
        array $headers = [],
        string|null $reason = null
    ): iResponse {
        if (is_int($status)) {
            $status = Status::from($status);
        }

        $response = new Response(
            status: $status->value,
            headers: $headers,
            body: is_array($body) ? json_encode($body, flags: Config::get('api.response.encode', 0)) : $body,
            reason: $reason,
        );

        if (null !== $body && !$response->hasHeader('Content-Length') && ($size = $response->getBody()->getSize())) {
            $response = $response->withHeader('Content-Length', (string)$size);
        }

        if (is_array($body) && false === $response->hasHeader('Content-Type')) {
            $response = $response->withHeader('Content-Type', 'application/json');
        }

        foreach (Config::get('api.response.headers', []) as $key => $val) {
            if ($response->hasHeader($key)) {
                continue;
            }
            $response = $response->withHeader($key, getValue($val));
        }

        return $response;
    }
}

if (!function_exists('api_error')) {
    /**
     * Create a API error response.
     *
     * @param string $message The error message.
     * @param Status $httpCode Optional. The HTTP status code. Default is {@see Status::BAD_REQUEST}.
     * @param array $body Optional. Additional fields to include in the response body.
     * @param array $opts Optional. Additional options.
     *
     * @return iResponse A PSR-7 compatible response object.
     */
    function api_error(
        string $message,
        Status $httpCode = Status::BAD_REQUEST,
        array $body = [],
        array $headers = [],
        string|null $reason = null,
        array $opts = []
    ): iResponse {
        $response = api_response(
            status: $httpCode,
            body: array_replace_recursive($body, [
                ag($opts, 'top_key', 'error') => [
                    'code' => $httpCode->value,
                    'message' => $message
                ]
            ]),
            headers: $headers,
            reason: $reason
        );

        foreach ($headers as $key => $val) {
            $response = $response->withHeader($key, $val);
        }

        if (array_key_exists('callback', $opts) && ($opts['callback'] instanceof Closure)) {
            $response = $opts['callback']($response);
        }

        return $response;
    }
}

if (!function_exists('api_message')) {
    /**
     * Create API message response.
     *
     * @param string $message The error message.
     * @param Status|int $httpCode Optional. The HTTP status code. Default is {@see Status::OK}.
     * @param array $body Optional. Additional fields to include in the response body.
     * @param array $headers Optional. Additional headers to include in the response.
     * @param string|null $reason Optional. The reason phrase to include in the response. Default is null.
     * @param array $opts Optional. Additional options.
     *
     * @return iResponse A PSR-7 compatible response object.
     */
    function api_message(
        string $message,
        Status|int $httpCode = Status::OK,
        array $body = [],
        array $headers = [],
        string|null $reason = null,
        array $opts = []
    ): iResponse {
        $opts['top_key'] = 'info';
        return api_error($message, $httpCode, $body, $headers, $reason, $opts);
    }
}

if (!function_exists('httpClientChunks')) {
    /**
     * Handle response stream as chunks.
     *
     * @param ResponseStreamInterface $stream Response stream.
     *
     * @return Generator Generator that yields chunks.
     *
     * @throws TransportExceptionInterface if stream is not readable.
     */
    function httpClientChunks(ResponseStreamInterface $stream): Generator
    {
        foreach ($stream as $chunk) {
            yield $chunk->getContent();
        }
    }
}

if (!function_exists('queuePush')) {
    /**
     * Add push event to the events queue.
     *
     * @param iState $entity The entity to push to the queue.
     * @param bool $remove Whether to remove the event from the queue if it's in pending state. Default is false.
     * @param UserContext|null $userContext The user context to use for the push event. Default is null.
     */
    function queuePush(iState $entity, bool $remove = false, UserContext|null $userContext = null): void
    {
        $logger = Container::get(iLogger::class);

        if (!$entity->id) {
            $logger->error("Unable to push event '{via}: {entity}'. It has no local id yet.", [
                'via' => $entity->via,
                'entity' => $entity->getName()
            ]);
            return;
        }

        $id = r('push://{id}', ['id' => $entity->id]);
        if (null !== $userContext) {
            $id = r('push://{user}@{id}', ['user' => $userContext->name, 'id' => $entity->id]);
        }

        if (true === $remove) {
            Container::get(EventsRepository::class)->removeByReference($id);
            return;
        }

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $logger->error("Unable to push '{id}' event '{via}: {entity}'. It has no GUIDs.", [
                'id' => $entity->id,
                'via' => $entity->via,
                'entity' => $entity->getName()
            ]);
            return;
        }

        $opts = [
            EventsTable::COLUMN_REFERENCE => $id,
        ];

        if (null !== $userContext) {
            $opts[Options::CONTEXT_USER] = $userContext->name;
        }

        queueEvent(ProcessPushEvent::NAME, [iState::COLUMN_ID => $entity->id], $opts);
    }
}

if (!function_exists('afterLast')) {
    /**
     * Get the substring after the last occurrence of a search string.
     *
     * @param string $subject The string to search in.
     * @param string $search The string to search for.
     *
     * @return string The substring after the last occurrence of the search string.
     *                If the search string is empty or not found in the subject string, the subject string is returned.
     */
    function afterLast(string $subject, string $search): string
    {
        if (empty($search)) {
            return $subject;
        }

        $position = mb_strrpos($subject, $search, 0);

        if (false === $position) {
            return $subject;
        }

        return mb_substr($subject, $position + mb_strlen($search));
    }
}

if (!function_exists('before')) {
    /**
     * Get the substring before the first occurrence of a search string.
     *
     * @param string $subject The subject string to search in.
     * @param string $search The search string.
     *
     * @return string The substring before the first occurrence of the search string.
     *                If the search string is empty or not found in the subject string, the subject string is returned.
     */
    function before(string $subject, string $search): string
    {
        return $search === '' ? $subject : explode($search, $subject)[0];
    }
}

if (!function_exists('after')) {
    /**
     * Get the string after first occurrence of a search string.
     *
     * @param string $subject The original string.
     * @param string $search The search string.
     *
     * @return string The string after the first occurrence of the search string.
     *                If the search string is empty or not found in the subject string, an empty string is returned.
     */
    function after(string $subject, string $search): string
    {
        return empty($search) ? $subject : array_reverse(explode($search, $subject, 2))[0];
    }
}

if (!function_exists('makeBackend')) {
    /**
     * Create new backend client instance.
     *
     * @param array{name:string|null, type:string, url:string, token:string|int|null, user:string|int|null, options:array} $backend
     * @param string|null $name server name.
     * @param array $options
     *
     * @return iClient backend client instance.
     * @throws InvalidArgumentException if configuration is wrong.
     */
    function makeBackend(array $backend, string|null $name = null, array $options = []): iClient
    {
        if (null === ($backendType = ag($backend, 'type'))) {
            throw new InvalidArgumentException('No backend type was set.');
        }

        if (null === ag($backend, 'url')) {
            throw new InvalidArgumentException('No backend url was set.');
        }

        if (null === ($class = Config::get("supported.{$backendType}", null))) {
            throw new InvalidArgumentException(
                r('Unexpected client type [{type}] was given. Expecting [{list}]', [
                    'type' => $backendType,
                    'list' => array_keys(Config::get('supported', [])),
                ])
            );
        }

        if (null !== ($userContext = ag($options, UserContext::class, null))) {
            assert($userContext instanceof UserContext);
            $cache = Container::get(BackendCache::class)->with(adapter: $userContext->cache);
        } else {
            $cache = $options[BackendCache::class] ?? Container::get(BackendCache::class);
        }

        return Container::getNew($class)->withContext(
            new Context(
                clientName: $backendType,
                backendName: $name ?? ag($backend, 'name', '??'),
                backendUrl: new Uri(ag($backend, 'url')),
                cache: $cache,
                userContext: $userContext ?? Container::get(UserContext::class),
                logger: ag($options, iLogger::class, fn() => Container::get(iLogger::class)),
                backendId: ag($backend, 'uuid', null),
                backendToken: ag($backend, 'token', null),
                backendUser: ag($backend, 'user', null),
                trace: (bool)ag($backend, 'options.' . Options::DEBUG_TRACE, false),
                options: ag($backend, 'options', []),
            )
        );
    }
}

if (!function_exists('arrayToString')) {
    /**
     * Convert an array to a string representation.
     *
     * @param array $arr The array to convert.
     * @param string $separator The separator used to concatenate the elements (default is ', ').
     *
     * @return string The string representation of the array.
     */
    function arrayToString(array $arr, string $separator = ', '): string
    {
        $list = [];

        foreach ($arr as $key => $val) {
            if (is_object($val)) {
                if (($val instanceof JsonSerializable)) {
                    $val = json_encode($val, flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } elseif (($val instanceof Stringable) || method_exists($val, '__toString')) {
                    $val = (string)$val;
                } else {
                    $val = get_object_vars($val);
                }
            }

            if (is_array($val)) {
                $val = '[ ' . arrayToString($val) . ' ]';
            } elseif (is_bool($val)) {
                $val = true === $val ? 'true' : 'false';
            } else {
                $val = $val ?? 'None';
            }

            $list[] = sprintf("(%s: %s)", $key, $val);
        }

        return implode($separator, $list);
    }
}

if (!function_exists('arrayToJson')) {
    /**
     * Convert an array to a JSON string in safe way.
     *
     * @param array $data array to convert.
     * @param bool $direct whether to return the result directly or not.
     *
     * @return string|array The string representation of the array.
     *
     */
    function arrayToJson(array $data, bool $direct = false): string|array
    {
        $list = [];
        $safeClasses = [JsonSerializable::class, Stringable::class, DatetimeInterface::class];

        foreach ($data as $key => $val) {
            if (true === is_object($val) && !in_array($val::class, $safeClasses, true)) {
                if (method_exists($val, '__toString')) {
                    $val = (string)$val;
                } else {
                    $val = get_object_vars($val);
                }
            }

            if (is_array($val)) {
                $val = arrayToJson($val, direct: true);
            } elseif (is_bool($val)) {
                $val = true === $val ? 'true' : 'false';
            } else {
                $val = $val ?? 'None';
            }

            $list[$key] = $val;
        }

        if ($direct) {
            return $list;
        }

        return json_encode($list, flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
    }
}

if (!function_exists('commandContext')) {
    /**
     * Returns the command context based on the environment.
     *
     * @return string The command context string.
     */
    function commandContext(): string
    {
        if (inContainer()) {
            return r('{command} exec -ti {name} console ', [
                'command' => @file_exists('/run/.containerenv') ? 'podman' : 'docker',
                'name' => env('CONTAINER_NAME', 'watchstate'),
            ]);
        }

        return ($_SERVER['argv'][0] ?? 'php bin/console') . ' ';
    }
}

if (!function_exists('getAppVersion')) {
    /**
     * Get the current version of the application.
     *
     * @return string The application version.
     */
    function getAppVersion(): string
    {
        $version = Config::get('version', 'dev-master');

        if ('$(version_via_ci)' === $version) {
            $gitDir = ROOT_PATH . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR;

            if (false === is_dir($gitDir)) {
                return 'dev-master';
            }

            try {
                $cmdBranch = 'git -C {cwd} rev-parse --abbrev-ref HEAD';
                $procBranch = Process::fromShellCommandline(r($cmdBranch, ['cwd' => escapeshellarg($gitDir)]));
                $procBranch->run();
                $branch = $procBranch->isSuccessful() ? trim($procBranch->getOutput()) : 'unknown';

                $cmdCommit = 'git -C {cwd} rev-parse --short HEAD';
                $procCommit = Process::fromShellCommandline(r($cmdCommit, ['cwd' => escapeshellarg($gitDir)]));
                $procCommit->run();
                $commit = $procCommit->isSuccessful() ? trim($procCommit->getOutput()) : 'unknown';

                $cmdDate = 'git -C {cwd} show -s --format=%cd --date=format:%Y%m%d HEAD';
                $procDate = Process::fromShellCommandline(r($cmdDate, ['cwd' => escapeshellarg($gitDir)]));
                $procDate->run();
                $commitDate = $procDate->isSuccessful() ? trim($procDate->getOutput()) : date('Ymd');

                return r('{branch}-{date}-{commit}', [
                    'branch' => $branch,
                    'date' => $commitDate,
                    'commit' => $commit,
                ]);
            } catch (Throwable) {
                return 'dev-master';
            }
        }

        return $version;
    }
}

if (!function_exists('isValidName')) {
    /**
     * Check if the given name is valid.
     *
     * The name must contain only alphanumeric characters and underscores.
     *
     * @param string $name The name to validate.
     *
     * @return bool True if the name is valid, false otherwise.
     */
    function isValidName(string $name): bool
    {
        return 1 === preg_match('/^[a-z_0-9]+$/', $name);
    }
}

if (!function_exists('formatDuration')) {
    /**
     * Format duration in milliseconds to HH:MM:SS format.
     *
     * @param int|float $milliseconds The duration in milliseconds.
     *
     * @return string The formatted duration in HH:MM:SS format.
     */
    function formatDuration(int|float $milliseconds): string
    {
        $seconds = floor($milliseconds / 1000);
        $minutes = floor($seconds / 60);
        $hours = floor($minutes / 60);
        $seconds %= 60;
        $minutes %= 60;

        return sprintf('%02u:%02u:%02u', $hours, $minutes, $seconds);
    }
}

if (!function_exists('array_keys_diff')) {
    /**
     * Return keys that match or does not match keys in list.
     *
     * @param array $base array containing all keys.
     * @param array $list list of keys that you want to filter based on.
     * @param bool $has Whether to get keys that exist in $list or exclude them.
     *
     * @return array The filtered array.
     */
    function array_keys_diff(array $base, array $list, bool $has = true): array
    {
        return array_filter($base, fn($key) => $has === in_array($key, $list), ARRAY_FILTER_USE_KEY);
    }
}

if (!function_exists('getMemoryUsage')) {
    /**
     * Get the current memory usage.
     *
     * @return string The memory usage in human-readable format.
     */
    function getMemoryUsage(): string
    {
        return fsize(memory_get_usage() - BASE_MEMORY);
    }
}

if (!function_exists('getPeakMemoryUsage')) {
    /**
     * Get the peak memory usage of the script.
     *
     * @return string The peak memory usage in human-readable format.
     */
    function getPeakMemoryUsage(): string
    {
        return fsize(memory_get_peak_usage() - BASE_PEAK_MEMORY);
    }
}

if (!function_exists('makeIgnoreId')) {
    /**
     * Make ignore id from given URL.
     *
     * @param string $url The URL to manipulate.
     *
     * @return iUri The modified URI.
     */
    function makeIgnoreId(string $url): iUri
    {
        static $filterQuery = null;

        if (null === $filterQuery) {
            $filterQuery = function (string $query): string {
                $list = $final = [];
                $allowed = ['id'];

                parse_str($query, $list);

                foreach ($list as $key => $val) {
                    if (empty($val) || false === in_array($key, $allowed)) {
                        continue;
                    }

                    $final[$key] = $val;
                }

                return http_build_query($final);
            };
        }

        $id = new Uri($url)->withPath('')->withFragment('')->withPort(null);
        return $id->withQuery($filterQuery($id->getQuery()));
    }
}

if (!function_exists('isIgnoredId')) {
    /**
     * Check if an ID is ignored.
     *
     * @param string $backend The backend.
     * @param string $type The type.
     * @param string $db The database.
     * @param int|string $id The ID.
     * @param int|string|null $backendId The backend ID (optional).
     *
     * @return bool Returns true if the ID is ignored, false otherwise.
     * @throws InvalidArgumentException Throws an exception if an invalid context type is given.
     */
    function isIgnoredId(
        UserContext $userContext,
        string $backend,
        string $type,
        string $db,
        string|int $id,
        string|int|null $backendId = null,
        array $opts = [],
    ): bool {
        static $ignoreList = [];

        if (false === in_array($type, iState::TYPES_LIST)) {
            throw new InvalidArgumentException(sprintf('Invalid context type \'%s\' was given.', $type));
        }

        if (!isset($ignoreList[$userContext->name]) || isset($opts['reset'])) {
            $ignoreList[$userContext->name] = $opts['list'] ?? [];
            $ignoreFile = $userContext->getPath() . '/ignore.yaml';
            if (true === file_exists($ignoreFile)) {
                try {
                    foreach (Yaml::parseFile($ignoreFile) as $key => $val) {
                        $ignoreList[$userContext->name][(string)makeIgnoreId($key)] = $val;
                    }
                } catch (Throwable) {
                }
            }
        }

        $list = $opts['list'] ?? $ignoreList[$userContext->name];

        $key = makeIgnoreId(sprintf('%s://%s:%s@%s?id=%s', $type, $db, $id, $backend, $backendId));

        if (isset($list[(string)$key->withQuery('')])) {
            return true;
        }

        if (null === $backendId) {
            return false;
        }

        return isset($list[(string)$key]);
    }
}

if (!function_exists('r')) {
    /**
     * Substitute words enclosed in special tags for values from context.
     *
     * @param string $text text that contains tokens.
     * @param array $context A key/value pairs list.
     * @param array $opts
     *
     * @return string
     */
    function r(string $text, array $context = [], array $opts = []): string
    {
        return r_array($text, $context, $opts)['message'];
    }
}

if (!function_exists('r_array')) {
    /**
     * Substitute words enclosed in special tags for values from context.
     *
     * @param string $text text that contains tokens.
     * @param array $context A key/value pairs list.
     * @param array $opts
     *
     * @return array{message:string, context:array}
     */
    function r_array(string $text, array $context = [], array $opts = []): array
    {
        $tagLeft = $opts['tag_left'] ?? '{';
        $tagRight = $opts['tag_right'] ?? '}';
        $logBehavior = $opts['log_behavior'] ?? false;

        if (false === str_contains($text, $tagLeft) || false === str_contains($text, $tagRight)) {
            return ['message' => $text, 'context' => $context];
        }

        $pattern = '#' . preg_quote($tagLeft, '#') . '([\w_.]+)' . preg_quote($tagRight, '#') . '#is';

        preg_match_all($pattern, $text, $matches);

        $replacements = [];

        foreach ($matches[1] as $key) {
            $placeholder = $tagLeft . $key . $tagRight;

            if (false === ag_exists($context, $key)) {
                continue;
            }

            $val = ag($context, $key);

            $context = ag_delete($context, $key);

            if (is_null($val) || is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replacements[$placeholder] = $val;
            } elseif ($val instanceof DateTimeInterface) {
                $replacements[$placeholder] = (string)$val;
            } elseif ($val instanceof UnitEnum) {
                $replacements[$placeholder] = $val instanceof BackedEnum ? $val->value : $val->name;
            } elseif (is_object($val)) {
                $replacements[$placeholder] = $logBehavior ? '[object ' . Utils::getClass($val) . ']' : implode(
                    ',',
                    get_object_vars($val)
                );
            } elseif (is_array($val)) {
                $replacements[$placeholder] = $logBehavior ? 'array' . Utils::jsonEncode($val, null, true) : implode(
                    ',',
                    $val
                );
            } else {
                $replacements[$placeholder] = '[' . gettype($val) . ']';
            }
        }

        return [
            'message' => strtr($text, $replacements),
            'context' => $context
        ];
    }
}

if (!function_exists('generateRoutes')) {
    /**
     * Generate routes based on the available commands.
     *
     * @param string $type The type of routes to return. defaults to is cli.
     *
     * @return array The generated routes.
     */
    function generateRoutes(string $type = 'cli', array $opts = []): array
    {
        $cache = $opts[iCache::class] ?? Container::get(iCache::class);

        $routes_cli = $routes_http = [];

        try {
            $dirs = [__DIR__ . '/../Commands'];
            foreach (array_keys(Config::get('supported', [])) as $backend) {
                $path = r(__DIR__ . '/../Backends/{backend}/Commands', ['backend' => ucfirst($backend)]);

                if (!file_exists($path)) {
                    continue;
                }

                $dirs[] = $path;
            }
            foreach (AttributesScanner::scan($dirs, allowNonInvokable: true)->for(Cli::class) as $item) {
                $routes_cli[] = [
                    'callable' => $item->getCallable(),
                    'path' => ag($item->data, 'pattern'),
                    'method' => ag($item->data, 'methods'),
                    'middleware' => ag($item->data, 'middleware'),
                    'host' => ag($item->data, 'host'),
                    'name' => ag($item->data, 'name'),
                    'port' => ag($item->data, 'port'),
                    'scheme' => ag($item->data, 'scheme'),
                ];
            }

            $cache->set('routes_cli', $routes_cli, new DateInterval('PT1H'));
        } catch (\Psr\SimpleCache\InvalidArgumentException) {
        }

        try {
            $dirs = [__DIR__ . '/../API'];
            foreach (AttributesScanner::scan($dirs, allowNonInvokable: false)->for(Route::class) as $item) {
                $routes_http[] = [
                    'callable' => $item->getCallable(),
                    'path' => ag($item->data, 'pattern'),
                    'method' => ag($item->data, 'methods'),
                    'middleware' => ag($item->data, 'middleware'),
                    'host' => ag($item->data, 'host'),
                    'name' => ag($item->data, 'name'),
                    'port' => ag($item->data, 'port'),
                    'scheme' => ag($item->data, 'scheme'),
                ];
            }

            $cache->set('routes_http', $routes_http, new DateInterval('P1D'));
        } catch (\Psr\SimpleCache\InvalidArgumentException) {
        }

        return 'http' === $type ? $routes_http : $routes_cli;
    }
}

if (!function_exists('getClientIp')) {
    /**
     * Get the client IP address.
     *
     * @param iRequest|null $request (optional) The request object.
     *
     * @return string The client IP address.
     */
    function getClientIp(?iRequest $request = null): string
    {
        $params = $request?->getServerParams() ?? $_SERVER;

        $realIp = (string)ag($params, 'REMOTE_ADDR', '0.0.0.0');

        if (false === (bool)Config::get('trust.proxy', false)) {
            return $realIp;
        }

        $forwardIp = ag(
            $params,
            'HTTP_' . strtoupper(trim(str_replace('-', '_', Config::get('trust.header', 'X-Forwarded-For'))))
        );

        if ($forwardIp === $realIp || empty($forwardIp)) {
            return $realIp;
        }

        if (null === ($firstIp = explode(',', $forwardIp)[0] ?? null) || empty($firstIp)) {
            return $realIp;
        }

        $firstIp = trim($firstIp);

        if (false === filter_var($firstIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }

        return trim($firstIp);
    }
}

if (!function_exists('inContainer')) {
    /**
     * Check if the code is running within a container.
     *
     * @return bool True if the code is running within a container, false otherwise.
     */
    function inContainer(): bool
    {
        if (true === (bool)env('IN_CONTAINER')) {
            return true;
        }

        if (true === @file_exists('/.dockerenv') || true === @file_exists('/run/.containerenv')) {
            return true;
        }

        return false;
    }
}

if (!function_exists('isValidURL')) {
    /**
     * Validate URL per RFC3987 (IRI)
     *
     * @param string $url The URL to validate.
     *
     * @return bool True if the URL is valid, false otherwise.
     * @SuppressWarnings
     * @noinspection all
     */
    function isValidURL(string $url): bool
    {
        // RFC 3987 For absolute IRIs (internationalized):
        /** @noinspection all */
        return (bool)@preg_match(
            '/^[a-z](?:[-a-z0-9\+\.])*:(?:\/\/(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:])*@)?(?:\[(?:(?:(?:[0-9a-f]{1,4}:){6}(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|::(?:[0-9a-f]{1,4}:){5}(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:){4}(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:[0-9a-f]{1,4}:[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:){3}(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:(?:[0-9a-f]{1,4}:){0,2}[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:){2}(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:(?:[0-9a-f]{1,4}:){0,3}[0-9a-f]{1,4})?::[0-9a-f]{1,4}:(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:(?:[0-9a-f]{1,4}:){0,4}[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:(?:[0-9a-f]{1,4}:){0,5}[0-9a-f]{1,4})?::[0-9a-f]{1,4}|(?:(?:[0-9a-f]{1,4}:){0,6}[0-9a-f]{1,4})?::)|v[0-9a-f]+[-a-z0-9\._~!\$&\'\(\)\*\+,;=:]+)\]|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3}|(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=@])*)(?::[0-9]*)?(?:\/(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@]))*)*|\/(?:(?:(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@]))+)(?:\/(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@]))*)*)?|(?:(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@]))+)(?:\/(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@]))*)*|(?!(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@])))(?:\?(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@])|[\x{E000}-\x{F8FF}\x{F0000}-\x{FFFFD}|\x{100000}-\x{10FFFD}\/\?])*)?(?:\#(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@])|[\/\?])*)?$/iu',
            $url
        );
    }
}

if (!function_exists('getSystemMemoryInfo')) {
    /**
     * Get system memory information.
     *
     * @return array{ MemTotal: float, MemFree: float, MemAvailable: float, SwapTotal: float, SwapFree: float }
     */
    function getSystemMemoryInfo(string $memFile = '/proc/meminfo'): array
    {
        $keys = [
            'MemTotal' => 'mem_total',
            'MemFree' => 'mem_free',
            'MemAvailable' => 'mem_available',
            'SwapTotal' => 'swap_total',
            'SwapFree' => 'swap_free',
        ];

        $result = [];

        if (!is_readable($memFile)) {
            return $result;
        }

        if (false === ($lines = @file($memFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))) {
            return $result;
        }

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            $line = explode(':', $line);
            $key = trim($line[0]);

            if (false === array_key_exists($key, $keys)) {
                continue;
            }

            $val = trim(str_ireplace(' kB', '', $line[1]));

            $value = 1000 * (float)$val;

            $result[$keys[$key]] = $value;
        }

        return $result;
    }
}

if (!function_exists('parseConfigValue')) {
    /**
     * Parse a configuration value.
     *
     * @param mixed $value The value to parse.
     * @param Closure|null $callback (optional) A callback function to apply to the parsed value.
     *
     * @return mixed The parsed value.
     */
    function parseConfigValue(mixed $value, Closure|null $callback = null): mixed
    {
        if (is_string($value) && preg_match('#%{(.+?)}#s', $value)) {
            $val = preg_replace_callback('#%{(.+?)}#s', fn($match) => Config::get($match[1], $match[1]), $value);
            return null !== $callback && null !== $val ? $callback($val) : $val;
        }

        return $value;
    }
}

if (!function_exists('addCors')) {
    function addCors(iResponse $response, array $headers = [], array $methods = []): iResponse
    {
        $headers += [
            'Access-Control-Max-Age' => 600,
            'Access-Control-Allow-Headers' => 'X-Application-Version, X-Request-Id, Authorization, *',
            'Access-Control-Allow-Origin' => '*',
        ];

        if (count($methods) > 0) {
            $headers['Access-Control-Allow-Methods'] = implode(', ', $methods);
        }

        foreach ($headers as $key => $val) {
            if (true === $response->hasHeader($key)) {
                continue;
            }

            $response = $response->withHeader($key, $val);
        }

        return $response;
    }
}

if (!function_exists('deepArrayMerge')) {
    /**
     * Recursively merge arrays.
     *
     * @param array $arrays The arrays to merge.
     * @param bool $preserve_integer_keys (Optional) Whether to preserve integer keys.
     *
     * @return array The merged array.
     */
    function deepArrayMerge(array $arrays, bool $preserve_integer_keys = false): array
    {
        $result = [];
        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                // Renumber integer keys as array_merge_recursive() does unless
                // $preserve_integer_keys is set to TRUE. Note that PHP automatically
                // converts array keys that are integer strings (e.g., '1') to integers.
                if (is_int($key) && !$preserve_integer_keys) {
                    $result[] = $value;
                } // Recurse when both values are arrays.
                elseif (isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
                    $result[$key] = deepArrayMerge([$result[$key], $value], $preserve_integer_keys);
                } // Otherwise, use the latter value, overriding any previous value.
                else {
                    $result[$key] = $value;
                }
            }
        }
        return $result;
    }
}
