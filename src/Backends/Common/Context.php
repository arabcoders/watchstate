<?php

declare(strict_types=1);

namespace App\Backends\Common;

use Psr\Http\Message\UriInterface;

class Context
{
    /**
     * Make Context for classes to work with.
     *
     * @param string $clientName Backend Client Name
     * @param string $backendName Backend Name
     * @param UriInterface $backendUrl Backend Url
     * @param Cache $cache A Global Cache for backend.
     * @param string|int|null $backendId Backend Id.
     * @param string|int|null $backendToken Backend access token
     * @param string|int|null $backendUser Backend user id.
     * @param array $backendHeaders Headers to pass for backend.
     * @param bool $trace Enable debug tracing mode.
     * @param array $options optional options.
     */
    public function __construct(
        public readonly string $clientName,
        public readonly string $backendName,
        public readonly UriInterface $backendUrl,
        public readonly Cache $cache,
        public readonly string|int|null $backendId = null,
        public readonly string|int|null $backendToken = null,
        public readonly string|int|null $backendUser = null,
        public readonly array $backendHeaders = [],
        public readonly bool $trace = false,
        public readonly array $options = []
    ) {
    }
}
