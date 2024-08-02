<?php
/** @noinspection PhpUnhandledExceptionInspection, PhpDocMissingThrowsInspection */

declare(strict_types=1);

use App\Backends\Common\Cache as BackendCache;
use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Libs\APIResponse;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\Date;
use App\Libs\Guid;
use App\Libs\Initializer;
use App\Libs\Options;
use App\Libs\Router;
use App\Libs\Stream;
use App\Libs\Uri;
use Monolog\Utils;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Message\StreamInterface as iStream;
use Psr\Http\Message\UriInterface as iUri;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Process\Process;
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

        return (new Date($date))->setTimezone($tz);
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
     * @param int|string $path The key path to delete.
     * @param string $separator The separator used in the key path (default is '.').
     *
     * @return array The modified array.
     */
    function ag_delete(array $array, string|int $path, string $separator = '.'): array
    {
        if (array_key_exists($path, $array)) {
            unset($array[$path]);

            return $array;
        }

        if (is_int($path)) {
            if (isset($array[$path])) {
                unset($array[$path]);
            }
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
            ]), 'w'
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

        $stream = $file ?? new Stream(r('{path}/debug/request.{id}.json', [
            'path' => Config::get('tmpDir'),
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
     * Create a API response.
     *
     * @param Status $status Optional. The HTTP status code. Default is {@see Status::HTTP_OK}.
     * @param array|null $body The JSON data to include in the response body.
     * @param array $headers Optional. Additional headers to include in the response.
     * @param string|null $reason Optional. The reason phrase to include in the response. Default is null.
     *
     * @return iResponse A PSR-7 compatible response object.
     */
    function api_response(
        Status $status = Status::HTTP_OK,
        array|null $body = null,
        array $headers = [],
        string|null $reason = null
    ): iResponse {
        $response = (new Response(
            status: $status->value,
            headers: $headers,
            body: null !== $body ? json_encode(
                $body,
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
            ) : null,
            reason: $reason,
        ));

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
     * @param Status $httpCode Optional. The HTTP status code. Default is {@see Status::HTTP_BAD_REQUEST}.
     * @param array $body Optional. Additional fields to include in the response body.
     * @param array $opts Optional. Additional options.
     *
     * @return iResponse A PSR-7 compatible response object.
     */
    function api_error(
        string $message,
        Status $httpCode = Status::HTTP_BAD_REQUEST,
        array $body = [],
        array $headers = [],
        string|null $reason = null,
        array $opts = []
    ): iResponse {
        $response = api_response(
            status: $httpCode,
            body: array_replace_recursive($body, [
                'error' => [
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
     * Pushes the entity to the queue.
     *
     * This method adds the entity to the queue for further processing.
     *
     * @param iState $entity The entity to push to the queue.
     * @param bool $remove (optional) Whether to remove the entity from the queue if it already exists (default is false).
     */
    function queuePush(iState $entity, bool $remove = false): void
    {
        if (!$remove && !$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            return;
        }

        try {
            $cache = Container::get(iCache::class);

            $list = $cache->get('queue', []);

            if (true === $remove && array_key_exists($entity->id, $list)) {
                unset($list[$entity->id]);
            } else {
                $list[$entity->id] = ['id' => $entity->id];
            }

            $cache->set('queue', $list, new DateInterval('P7D'));
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            Container::get(iLogger::class)->error(
                message: 'Exception [{error.kind}] was thrown unhandled during saving [{backend} - {title}} into queue. Error [{error.message} @ {error.file}:{error.line}].',
                context: [
                    'backend' => $entity->via,
                    'title' => $entity->getName(),
                    'error' => [
                        'kind' => $e::class,
                        'line' => $e->getLine(),
                        'message' => $e->getMessage(),
                        'file' => after($e->getFile(), ROOT_PATH),
                    ],
                    'trace' => $e->getTrace(),
                ],
            );
        }
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
     *
     * @return iClient backend client instance.
     * @throws InvalidArgumentException if configuration is wrong.
     */
    function makeBackend(array $backend, string|null $name = null): iClient
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

        return Container::getNew($class)->withContext(
            new Context(
                clientName: $backendType,
                backendName: $name ?? ag($backend, 'name', '??'),
                backendUrl: new Uri(ag($backend, 'url')),
                cache: Container::get(BackendCache::class),
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
            $gitDir = ROOT_PATH . '/.git/';

            if (is_dir($gitDir)) {
                $cmd = 'git --git-dir=%1$s describe --exact-match --tags 2> /dev/null || git --git-dir=%1$s rev-parse --short HEAD';
                exec(sprintf($cmd, escapeshellarg($gitDir)), $output, $status);

                if (0 === $status) {
                    return $output[0] ?? 'dev-master';
                }
            }

            return 'dev-master';
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
        return 1 === preg_match('/^\w+$/', $name);
    }
}

if (false === function_exists('formatDuration')) {
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

if (false === function_exists('array_keys_diff')) {
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

if (false === function_exists('getMemoryUsage')) {
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

if (false === function_exists('getPeakMemoryUsage')) {
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

if (false === function_exists('makeIgnoreId')) {
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

        $id = (new Uri($url))->withPath('')->withFragment('')->withPort(null);
        return $id->withQuery($filterQuery($id->getQuery()));
    }
}

if (false === function_exists('isIgnoredId')) {
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
        string $backend,
        string $type,
        string $db,
        string|int $id,
        string|int|null $backendId = null
    ): bool {
        if (false === in_array($type, iState::TYPES_LIST)) {
            throw new InvalidArgumentException(sprintf('Invalid context type \'%s\' was given.', $type));
        }

        $list = Config::get('ignore', []);

        $key = makeIgnoreId(sprintf('%s://%s:%s@%s?id=%s', $type, $db, $id, $backend, $backendId));

        if (null !== ($list[(string)$key->withQuery('')] ?? null)) {
            return true;
        }

        if (null === $backendId) {
            return false;
        }

        return null !== ($list[(string)$key] ?? null);
    }
}

if (false === function_exists('r')) {
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

if (false === function_exists('r_array')) {
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

        $status = preg_match_all($pattern, $text, $matches);

        if (false === $status || $status < 1) {
            return ['message' => $text, 'context' => $context];
        }

        $replacements = [];

        foreach ($matches[1] as $key) {
            $placeholder = $tagLeft . $key . $tagRight;

            if (false === str_contains($text, $placeholder)) {
                continue;
            }

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

if (false === function_exists('generateRoutes')) {
    /**
     * Generate routes based on the available commands.
     *
     * @param string $type The type of routes to return. defaults to is cli.
     *
     * @return array The generated routes.
     */
    function generateRoutes(string $type = 'cli'): array
    {
        $dirs = [__DIR__ . '/../Commands'];
        foreach (array_keys(Config::get('supported', [])) as $backend) {
            $dir = r(__DIR__ . '/../Backends/{backend}/Commands', ['backend' => ucfirst($backend)]);

            if (!file_exists($dir)) {
                continue;
            }

            $dirs[] = $dir;
        }

        $routes_cli = (new Router($dirs))->generate();

        $cache = Container::get(iCache::class);

        try {
            $cache->set('routes_cli', $routes_cli, new DateInterval('PT1H'));
        } catch (\Psr\SimpleCache\InvalidArgumentException) {
        }

        $routes_http = (new Router([__DIR__ . '/../API']))->generate();

        try {
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

if (false === function_exists('inContainer')) {
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

if (false === function_exists('isValidURL')) {
    /**
     * Validate URL per RFC3987 (IRI)
     *
     * @param string $url The URL to validate.
     *
     * @return bool True if the URL is valid, false otherwise.
     * @SuppressWarnings
     */
    function isValidURL(string $url): bool
    {
        // RFC 3987 For absolute IRIs (internationalized):
        return (bool)@preg_match(
            '/^[a-z](?:[-a-z0-9\+\.])*:(?:\/\/(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:])*@)?(?:\[(?:(?:(?:[0-9a-f]{1,4}:){6}(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|::(?:[0-9a-f]{1,4}:){5}(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:){4}(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:[0-9a-f]{1,4}:[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:){3}(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:(?:[0-9a-f]{1,4}:){0,2}[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:){2}(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:(?:[0-9a-f]{1,4}:){0,3}[0-9a-f]{1,4})?::[0-9a-f]{1,4}:(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:(?:[0-9a-f]{1,4}:){0,4}[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3})|(?:(?:[0-9a-f]{1,4}:){0,5}[0-9a-f]{1,4})?::[0-9a-f]{1,4}|(?:(?:[0-9a-f]{1,4}:){0,6}[0-9a-f]{1,4})?::)|v[0-9a-f]+[-a-z0-9\._~!\$&\'\(\)\*\+,;=:]+)\]|(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(?:\.(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3}|(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=@])*)(?::[0-9]*)?(?:\/(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@]))*)*|\/(?:(?:(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@]))+)(?:\/(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@]))*)*)?|(?:(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@]))+)(?:\/(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@]))*)*|(?!(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@])))(?:\?(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@])|[\x{E000}-\x{F8FF}\x{F0000}-\x{FFFFD}|\x{100000}-\x{10FFFD}\/\?])*)?(?:\#(?:(?:%[0-9a-f][0-9a-f]|[-a-z0-9\._~\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}!\$&\'\(\)\*\+,;=:@])|[\/\?])*)?$/iu',
            $url
        );
    }
}


if (false === function_exists('getSystemMemoryInfo')) {
    /**
     * Get system memory information.
     *
     * @return array{ MemTotal: float, MemFree: float, MemAvailable: float, SwapTotal: float, SwapFree: float }
     */
    function getSystemMemoryInfo(): array
    {
        $keys = [
            'MemTotal' => 'mem_total',
            'MemFree' => 'mem_free',
            'MemAvailable' => 'mem_available',
            'SwapTotal' => 'swap_total',
            'SwapFree' => 'swap_free',
        ];

        $result = [];

        if (!is_readable('/proc/meminfo')) {
            return $result;
        }

        if (false === ($lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))) {
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
    function parseConfigValue(mixed $value, Closure|null $callback = null): mixed
    {
        if (is_string($value) && preg_match('#%{(.+?)}#s', $value)) {
            $val = preg_replace_callback('#%{(.+?)}#s', fn($match) => Config::get($match[1], $match[1]), $value);
            return null !== $callback && null !== $val ? $callback($val) : $val;
        }

        return $value;
    }
}


if (!function_exists('tryCache')) {
    /**
     * Try to get a value from the cache, if it does not exist, call the callback and cache the result.
     *
     * @param iCache $cache The cache instance.
     * @param string $key The cache key.
     * @param Closure $callback The callback to call if the key does not exist.
     * @param DateInterval $ttl The time to live for the cache.
     * @param iLogger|null $logger The logger instance (optional).
     *
     * @return mixed The value from the cache or the callback.
     */
    function tryCache(
        iCache $cache,
        string $key,
        Closure $callback,
        DateInterval $ttl,
        iLogger|null $logger = null
    ): mixed {
        if (true === $cache->has($key)) {
            $logger?->debug("Cache hit for key '{key}'.", ['key' => $key]);
            return $cache->get($key);
        }

        $data = $callback();

        try {
            $cache->set($key, $data, $ttl);
        } catch (\Psr\SimpleCache\InvalidArgumentException) {
            $logger?->error("Failed to cache data for key '{key}'.", ['key' => $key]);
        }

        return $data;
    }
}


if (!function_exists('checkIgnoreRule')) {
    /**
     * Check if the given ignore rule is valid.
     *
     * @param string $guid The ignore rule to check.
     *
     * @return bool True if the ignore rule is valid, false otherwise.
     * @throws RuntimeException Throws an exception if the ignore rule is invalid.
     */
    function checkIgnoreRule(string $guid): bool
    {
        $urlParts = parse_url($guid);

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

        $backends = array_keys(Config::get('servers', []));

        if (false === in_array($backend, $backends)) {
            throw new RuntimeException(r("Invalid backend name '{backend}' was given. Expected values are '{list}'.", [
                'backend' => $backend,
                'list' => implode(', ', $backends),
            ]));
        }

        return true;
    }
}

if (!function_exists('addCors')) {
    function addCors(iResponse $response, array $headers = [], array $methods = []): iResponse
    {
        $headers += [
            'Access-Control-Max-Age' => 600,
            'Access-Control-Allow-Headers' => 'X-Application-Version, X-Request-Id, *',
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
     * @param string $method The request method.
     * @param string $path The request path.
     * @param array $json The request body.
     * @param array{ server: array, query: array, headers: array} $opts Additional options.
     *
     * @return APIResponse The response object.
     */
    function APIRequest(string $method, string $path, array $json = [], array $opts = []): APIResponse
    {
        $initializer = Container::get(Initializer::class);

        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);

        $uri = new Uri($path);

        $server = [
            'REQUEST_METHOD' => $method,
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

        $response = $initializer->http(
            $creator->fromArrays(
                server: $server,
                headers: $headers,
                get: $query,
                post: $json,
                body: $body
            )->withAttribute('INTERNAL_REQUEST', true)
        );

        $statusCode = Status::tryFrom($response->getStatusCode()) ?? Status::HTTP_SERVICE_UNAVAILABLE;

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
            if (empty($line)) {
                continue;
            }

            if (true === str_starts_with($line, '#') || false === str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);

            // -- check if value is quoted.
            if ((true === str_starts_with($value, '"') && true === str_ends_with($value, '"')) ||
                (true === str_starts_with($value, "'") && true === str_ends_with($value, "'"))) {
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
     * @param bool $ignoreContainer (Optional) Whether to ignore the container check.
     *
     * @return array{ status: bool, message: string }
     */
    function isTaskWorkerRunning(bool $ignoreContainer = false): array
    {
        if (false === $ignoreContainer && !inContainer()) {
            return [
                'status' => true,
                'message' => 'We can only track the task worker status when running in a container.'
            ];
        }

        $pidFile = '/tmp/ws-job-runner.pid';

        if (!file_exists($pidFile)) {
            return ['status' => false, 'message' => 'No PID file was found - Likely means task worker failed to run.'];
        }

        try {
            $pid = trim((string)(new Stream($pidFile)));
        } catch (Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }

        if (file_exists(r('/proc/{pid}/status', ['pid' => $pid]))) {
            return ['status' => true, 'message' => 'Task worker is running.'];
        }

        return [
            'status' => false,
            'message' => r("Found PID '{pid}' in file, but it seems the process is not active.", [
                'pid' => $pid
            ])
        ];
    }
}
