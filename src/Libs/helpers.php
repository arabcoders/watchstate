<?php

declare(strict_types=1);

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface;
use App\Libs\Extends\Date;
use App\Libs\HttpException;
use App\Libs\Servers\ServerInterface;
use App\Libs\Storage\StorageInterface;
use Nyholm\Psr7\Response;
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
    function ag(array $array, string|null $path, mixed $default = null, string $separator = '.'): mixed
    {
        if (null === $path) {
            return $array;
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
    function saveWebhookPayload(ServerRequestInterface $request, string $name, array $parsed = [])
    {
        $content = [
            'query' => $request->getQueryParams(),
            'parsed' => $request->getParsedBody(),
            'server' => $request->getServerParams(),
            'body' => $request->getBody()->getContents(),
            'cParsed' => $parsed,
        ];

        @file_put_contents(
            Config::get('path') . '/logs/' . sprintf('webhook.%s.%d.json', $name, time()),
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

if (!function_exists('serveHttpRequest')) {
    /**
     * @throws ReflectionException
     */
    function serveHttpRequest(ServerRequestInterface $request): ResponseInterface
    {
        $logger = Container::get(LoggerInterface::class);

        try {
            if (true !== Config::get('webhook.enabled', false)) {
                throw new HttpException('Webhook is disabled via config.', 500);
            }

            if (null === Config::get('webhook.apikey', null)) {
                throw new HttpException('No webhook.apikey is set in config.', 500);
            }

            // -- get apikey from header or query.
            $apikey = $request->getHeaderLine('x-apikey');
            if (empty($apikey)) {
                $apikey = ag($request->getQueryParams(), 'apikey', '');
                if (empty($apikey)) {
                    throw new HttpException('No API key was given.', 400);
                }
            }

            if (!hash_equals(Config::get('webhook.apikey'), $apikey)) {
                throw new HttpException('Invalid API key was given.', 401);
            }

            if (null === ($type = ag($request->getQueryParams(), 'type', null))) {
                throw new HttpException('No type was given via type= query.', 400);
            }

            $types = Config::get('supported', []);

            if (null === ($backend = ag($types, $type))) {
                throw new HttpException('Invalid server type was given.', 400);
            }

            $class = new ReflectionClass($backend);

            if (!$class->implementsInterface(ServerInterface::class)) {
                throw new HttpException('Invalid Parser Class.', 500);
            }

            /** @var ServerInterface $backend */
            $entity = $backend::parseWebhook($request);

            if (null === $entity || !$entity->hasGuids()) {
                return new Response(status: 200, headers: ['X-Status' => 'No GUIDs.']);
            }

            $storage = Container::get(StorageInterface::class);

            if (null === ($backend = $storage->get($entity))) {
                $entity = $storage->insert($entity);
                queuePush($entity);
                return jsonResponse(status: 200, body: $entity->getAll());
            }

            if (true === $entity->isTainted()) {
                if ($backend->apply($entity, guidOnly: true)->isChanged()) {
                    if (!empty($entity->meta)) {
                        $backend->meta = $entity->meta;
                    }
                    $backend = $storage->update($backend);
                    return jsonResponse(status: 200, body: $backend->getAll());
                }

                return new Response(
                    status:  200,
                    headers: ['X-Status' => 'Nothing updated, entity state is tainted.']
                );
            }

            if ($backend->updated > $entity->updated) {
                if ($backend->apply($entity, guidOnly: true)->isChanged()) {
                    if (!empty($entity->meta)) {
                        $backend->meta = $entity->meta;
                    }
                    $backend = $storage->update($backend);
                    return jsonResponse(status: 200, body: $backend->getAll());
                }

                return new Response(
                    status:  200,
                    headers: ['X-Status' => 'Entity date is older than what available in storage.']
                );
            }

            if ($backend->apply($entity)->isChanged()) {
                $backend = $storage->update($backend);

                queuePush($backend);
                return jsonResponse(status: 200, body: $backend->getAll());
            }

            return new Response(status: 200, headers: ['X-Status' => 'Entity is unchanged.']);
        } catch (HttpException $e) {
            $logger->error($e->getMessage());

            if (200 === $e->getCode()) {
                return new Response(status: $e->getCode(), headers: ['X-Status' => $e->getMessage()]);
            }

            return jsonResponse(status: $e->getCode(), body: ['error' => true, 'message' => $e->getMessage()]);
        }
    }
}

if (!function_exists('queuePush')) {
    function queuePush(StateInterface $entity): void
    {
        if (!$entity->hasGuids()) {
            return;
        }

        try {
            $cache = Container::get(CacheInterface::class);

            $list = $cache->get('queue', []);

            $list[$entity->id] = $entity->getAll();

            $cache->set('queue', $list);
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
