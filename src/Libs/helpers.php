<?php

declare(strict_types=1);

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface;
use App\Libs\Extends\Date;
use App\Libs\Servers\ServerInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        if (false === ($value = $_ENV[$key] ?? getenv($key))) {
            return getValue($default);
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)' => null,
            default => $value,
        };
    }
}

if (!function_exists('getValue')) {
    function getValue(mixed $var): mixed
    {
        return ($var instanceof Closure) ? $var() : $var;
    }
}

if (!function_exists('makeDate')) {
    /**
     * Make Date Time Object.
     *
     * @param string|int $date Defaults to now
     * @param string|DateTimeZone|null $tz For given $date, not for display.
     *
     * @return Date
     */
    function makeDate(string|int $date = 'now', DateTimeZone|string|null $tz = null): Date
    {
        if (ctype_digit((string)$date)) {
            $date = '@' . $date;
        }

        if (null === $tz) {
            $tz = date_default_timezone_get();
        }

        if (!($tz instanceof DateTimeZone)) {
            $tz = new DateTimeZone($tz);
        }

        return (new Date($date))->setTimezone($tz);
    }
}

if (!function_exists('ag')) {
    function ag(array|object $array, string|array|null $path, mixed $default = null, string $separator = '.'): mixed
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

        if (array_key_exists($path, $array)) {
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
     * @param array $array
     * @param string|int $path
     * @param string $separator
     *
     * @return bool
     */
    function ag_exists(array $array, string|int $path, string $separator = '.'): bool
    {
        if (is_int($path)) {
            return isset($array[$path]);
        }

        foreach (explode($separator, $path) as $lookup) {
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
     * @param array $array
     * @param int|string $path
     * @param string $separator
     * @return array
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
    function fixPath(string $path): string
    {
        return rtrim(implode(DIRECTORY_SEPARATOR, explode(DIRECTORY_SEPARATOR, $path)), DIRECTORY_SEPARATOR);
    }
}

if (!function_exists('fsize')) {
    function fsize(string|int $bytes = 0, bool $showUnit = true, int $decimals = 2, int $mod = 1000): string
    {
        $sz = 'BKMGTP';

        $factor = floor((strlen((string)$bytes) - 1) / 3);

        return sprintf("%.{$decimals}f", (int)($bytes) / ($mod ** $factor)) . ($showUnit ? $sz[(int)$factor] : '');
    }
}

if (!function_exists('saveWebhookPayload')) {
    function saveWebhookPayload(StateInterface $entity, ServerRequestInterface $request): void
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

        @file_put_contents(
            Config::get('tmpDir') . '/webhooks/' . sprintf(
                'webhook.%s.%s.%s.json',
                $entity->via,
                ag($entity->getExtra($entity->via), 'event', 'unknown'),
                ag($request->getServerParams(), 'X_REQUEST_ID', time())
            ),
            json_encode(value: $content, flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE)
        );
    }
}

if (!function_exists('saveRequestPayload')) {
    function saveRequestPayload(ServerRequestInterface $request): void
    {
        $content = [
            'query' => $request->getQueryParams(),
            'parsed' => $request->getParsedBody(),
            'server' => $request->getServerParams(),
            'body' => (string)$request->getBody(),
            'attributes' => $request->getAttributes(),
        ];

        @file_put_contents(
            Config::get('tmpDir') . '/debug/' . sprintf(
                'request.%s.json',
                ag($request->getServerParams(), 'X_REQUEST_ID', (string)time())
            ),
            json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}

if (!function_exists('jsonResponse')) {
    function jsonResponse(int $status, array $body, $headers = []): ResponseInterface
    {
        $headers['Content-Type'] = 'application/json';

        return new Response(
            status:  $status,
            headers: $headers,
            body:    json_encode(
                         $body,
                         JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
                     )
        );
    }
}

if (!function_exists('httpClientChunks')) {
    /**
     * Handle Response Stream as Chunks
     *
     * @param ResponseStreamInterface $responseStream
     * @return Generator
     *
     * @throws TransportExceptionInterface
     */
    function httpClientChunks(ResponseStreamInterface $responseStream): Generator
    {
        foreach ($responseStream as $chunk) {
            yield $chunk->getContent();
        }
    }
}

if (!function_exists('queuePush')) {
    function queuePush(StateInterface $entity): void
    {
        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            return;
        }

        try {
            $cache = Container::get(CacheInterface::class);

            $list = $cache->get('queue', []);

            $list[$entity->id] = ['id' => $entity->id];

            $cache->set('queue', $list, new DateInterval('P7D'));
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            Container::get(LoggerInterface::class)->error($e->getMessage(), $e->getTrace());
        }
    }
}

if (!function_exists('afterLast')) {
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
    function before(string $subject, string $search): string
    {
        return $search === '' ? $subject : explode($search, $subject)[0];
    }
}

if (!function_exists('after')) {
    function after(string $subject, string $search): string
    {
        return empty($search) ? $subject : array_reverse(explode($search, $subject, 2))[0];
    }
}

if (!function_exists('makeServer')) {
    /**
     * @param array{name:string|null, type:string, url:string, token:string|int|null, user:string|int|null, persist:array, options:array} $server
     * @param string|null $name server name.
     * @return ServerInterface
     *
     * @throws RuntimeException if configuration is wrong.
     */
    function makeServer(array $server, string|null $name = null): ServerInterface
    {
        if (null === ($serverType = ag($server, 'type'))) {
            throw new RuntimeException('No server type was selected.');
        }

        if (null === ag($server, 'url')) {
            throw new RuntimeException('No url was set for server.');
        }

        if (null === ($class = Config::get("supported.{$serverType}", null))) {
            throw new RuntimeException(
                sprintf(
                    'Unexpected server type was given. Was expecting [%s] but got \'%s\' instead.',
                    $serverType,
                    implode('|', Config::get("supported", []))
                )
            );
        }

        return Container::getNew($class)->setUp(
            name:    $name ?? ag($server, 'name', fn() => md5(ag($server, 'url'))),
            url:     new Uri(ag($server, 'url')),
            token:   ag($server, 'token', null),
            userId:  ag($server, 'user', null),
            uuid:    ag($server, 'uuid', null),
            persist: ag($server, 'persist', []),
            options: ag($server, 'options', []),
        );
    }
}

if (!function_exists('arrayToString')) {
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
            } else {
                $val = $val ?? 'None';
            }

            $list[] = sprintf("(%s: %s)", $key, $val);
        }

        return implode($separator, $list);
    }
}

if (!function_exists('commandContext')) {
    function commandContext(): string
    {
        if (env('IN_DOCKER')) {
            return sprintf('docker exec -ti %s console ', env('CONTAINER_NAME', 'watchstate'));
        }

        return ($_SERVER['argv'][0] ?? 'php console') . ' ';
    }
}

if (!function_exists('getAppVersion')) {
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

if (!function_exists('t')) {
    function t($phrase, string|int ...$args): string
    {
        static $lang;

        if (null === $lang) {
            $lang = require __DIR__ . '/../../config/lang.php';
        }

        if (isset($lang[$phrase])) {
            throw new InvalidArgumentException(
                sprintf('Invalid language definition \'%s\' key was given.', $phrase)
            );
        }

        $text = $lang[$phrase];

        if (!empty($args)) {
            $text = sprintf($text, ...$args);
        }

        return $text;
    }
}


if (!function_exists('isValidName')) {
    /**
     * Allow only [Aa-Zz][0-9][_] in server names.
     *
     * @param string $name
     *
     * @return bool
     */
    function isValidName(string $name): bool
    {
        return 1 === preg_match('/^\w+$/', $name);
    }
}

if (false === function_exists('filterResponse')) {
    function filterResponse(object|array $item, array $cast = []): array
    {
        if (false === is_array($item)) {
            $item = (array)$item;
        }

        if (empty($cast)) {
            return $item;
        }

        $modified = [];

        foreach ($item as $key => $value) {
            if (true === is_array($value) || true === is_object($value)) {
                $modified[$key] = filterResponse($value, $cast);
                continue;
            }

            if (null === ($cast[$key] ?? null)) {
                $modified[$key] = $value;
                continue;
            }

            $modified[$key] = match ($cast[$key] ?? null) {
                'datetime' => makeDate($value),
                'size' => strlen((string)$value) >= 4 ? fsize($value) : $value,
                'duration_sec' => formatDuration($value),
                'duration_mil' => formatDuration($value / 10000),
                'bool' => (bool)$value,
                default => is_callable($cast[$key] ?? null) ? $cast[$key]($value) : $value,
            };
        }

        return $modified;
    }
}

if (false === function_exists('formatDuration')) {
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
     * @return array
     */
    function array_keys_diff(array $base, array $list, bool $has = true): array
    {
        return array_filter($base, fn($key) => $has === in_array($key, $list), ARRAY_FILTER_USE_KEY);
    }
}

if (false === function_exists('getMemoryUsage')) {
    function getMemoryUsage(): string
    {
        return fsize(memory_get_usage() - BASE_MEMORY);
    }
}

if (false === function_exists('getPeakMemoryUsage')) {
    function getPeakMemoryUsage(): string
    {
        return fsize(memory_get_peak_usage() - BASE_PEAK_MEMORY);
    }
}

if (false === function_exists('formatName')) {
    function formatName(string $name): string
    {
        return trim(
            preg_replace(
                '/\s+/',
                ' ',
                str_replace(
                    [
                        '?',
                        ':',
                        '(',
                        '[',
                        ']',
                        ')',
                        ',',
                        '|',
                        '%',
                        '.',
                        '–',
                        '-',
                        "'",
                        '"',
                        '+',
                        '/',
                        ';',
                        '&',
                        '_',
                        '!',
                        '*',
                    ],
                    ' ',
                    strtolower($name)
                )
            )
        );
    }
}

if (false === function_exists('mb_similar_text')) {
    /**
     * Implementation of `mb_similar_text()`.
     *
     * (c) Antal Áron <antalaron@antalaron.hu>
     *
     * @see http://php.net/manual/en/function.similar-text.php
     * @see http://locutus.io/php/strings/similar_text/
     *
     * @param string $str1
     * @param string $str2
     * @param float|null $percent
     *
     * @return int
     */
    function mb_similar_text(string $str1, string $str2, float|null &$percent = null): int
    {
        if (0 === mb_strlen($str1) + mb_strlen($str2)) {
            $percent = 0.0;

            return 0;
        }

        $pos1 = $pos2 = $max = 0;
        $l1 = mb_strlen($str1);
        $l2 = mb_strlen($str2);

        for ($p = 0; $p < $l1; ++$p) {
            for ($q = 0; $q < $l2; ++$q) {
                /** @noinspection LoopWhichDoesNotLoopInspection */
                /** @noinspection MissingOrEmptyGroupStatementInspection */
                for (
                    $l = 0; ($p + $l < $l1) && ($q + $l < $l2) && mb_substr($str1, $p + $l, 1) === mb_substr(
                    $str2,
                    $q + $l,
                    1
                ); ++$l
                ) {
                    // nothing to do
                }
                if ($l > $max) {
                    $max = $l;
                    $pos1 = $p;
                    $pos2 = $q;
                }
            }
        }

        $similarity = $max;
        if ($similarity) {
            if ($pos1 && $pos2) {
                $similarity += mb_similar_text(mb_substr($str1, 0, $pos1), mb_substr($str2, 0, $pos2));
            }
            if (($pos1 + $max < $l1) && ($pos2 + $max < $l2)) {
                $similarity += mb_similar_text(
                    mb_substr($str1, $pos1 + $max, $l1 - $pos1 - $max),
                    mb_substr($str2, $pos2 + $max, $l2 - $pos2 - $max)
                );
            }
        }

        $percent = ($similarity * 200.0) / ($l1 + $l2);

        return $similarity;
    }
}
