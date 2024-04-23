<?php

declare(strict_types=1);

namespace App\Backends\Common;

use Psr\Http\Message\UriInterface;

readonly class Context
{
    /**
     * Make backend context for classes to work with.
     *
     * @param string $clientName Backend client name.
     * @param string $backendName Backend name.
     * @param UriInterface $backendUrl Backend URL.
     * @param Cache $cache A global cache for backend.
     * @param string|int|null $backendId Backend id.
     * @param string|int|null $backendToken Backend access token
     * @param string|int|null $backendUser Backend user id.
     * @param array $backendHeaders Headers to pass for backend requests.
     * @param bool $trace Enable debug tracing mode.
     * @param array $options optional options.
     */
    public function __construct(
        public string $clientName,
        public string $backendName,
        public UriInterface $backendUrl,
        public Cache $cache,
        public string|int|null $backendId = null,
        public string|int|null $backendToken = null,
        public string|int|null $backendUser = null,
        public array $backendHeaders = [],
        public bool $trace = false,
        public array $options = []
    ) {
    }
}
