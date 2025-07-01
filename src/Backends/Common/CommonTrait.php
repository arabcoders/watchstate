<?php

declare(strict_types=1);

namespace App\Backends\Common;

use App\Libs\Container;
use App\Libs\Options;
use DateInterval;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

trait CommonTrait
{
    /**
     * Wrap closure into try catch response.
     *
     * @param Context $context Context to associate the call with.
     * @param callable():Response $fn Closure
     * @param string|null $action the action name to make error message more clear.
     *
     * @return Response Response object.
     * @todo Expand the catch to include common http errors. json decode failing.
     * @todo raise the log level to error instead of warning as it's currently doing, warning imply it's ok to ignore.
     */
    protected function tryResponse(Context $context, callable $fn, string|null $action = null): Response
    {
        try {
            $response = $fn();

            if (false === ($response instanceof Response)) {
                return new Response(status: true, response: $response);
            }

            return $response;
        } catch (Throwable $e) {
            return new Response(
                status: false,
                error: new Error(
                    ...lw(
                    message: "{client}: '{backend}' {action} thrown unhandled exception '{error.kind}'. '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'action' => $action ?? '',
                        'backend' => $context->backendName,
                        'client' => $context->clientName,
                        'message' => $e->getMessage(),
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
                    ],
                    e: $e
                ),
                    level: Levels::WARNING,
                    previous: $e
                )
            );
        }
    }

    /**
     * Try to cache the result of a function.
     *
     * @param Context $context Context to associate the call with.
     * @param string $key Cache key. The key will be prefixed with the backend name.
     * @param callable():mixed $fn Function to cache.
     * @param DateInterval $ttl Time to live.
     * @param iLogger|null $logger Logger to use.
     *
     * @return mixed result of the closure.
     */
    protected function tryCache(
        Context $context,
        string $key,
        callable $fn,
        DateInterval $ttl,
        iLogger|null $logger = null
    ): mixed {
        try {
            $cache = $context->cache->getInterface();
            $cacheKey = $context->backendName . '_' . $key;
            if (true === ag_exists($context->options, Options::PLEX_USER_PIN)) {
                $cacheKey = $cacheKey . '_with_pin';
            }

            if (true === $cache->has($cacheKey)) {
                $logger?->debug("{client} Cache hit for key '{backend}: {key}'.", [
                    'key' => $key,
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                ]);
                return $cache->get($cacheKey);
            }
        } catch (\Psr\SimpleCache\InvalidArgumentException) {
            $logger?->error("{client} Failed to retrieve cached data for '{backend}: {key}'.", [
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'key' => $key,
            ]);
        }

        $data = $fn();

        try {
            $cache->set($cacheKey, $data, $ttl);
        } catch (\Psr\SimpleCache\InvalidArgumentException) {
            $logger?->error("{client} Failed to cache data for key '{backend}: {key}'.", [
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'key' => $key,
            ]);
        }

        return $data;
    }

    /**
     * Get Logger.
     *
     * @return iLogger Return the logger.
     */
    protected function getLogger(): iLogger
    {
        return Container::get(iLogger::class);
    }
}
