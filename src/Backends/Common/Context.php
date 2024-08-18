<?php

declare(strict_types=1);

namespace App\Backends\Common;

use App\Libs\Container;
use App\Libs\Options;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface as iLogger;

final class Context
{
    protected iLogger|null $logger = null;

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

    /**
     * Check if the used token is limited access token.
     *
     * @param bool $withUser Include user check.
     *
     * @return bool
     */
    public function isLimitedToken(bool $withUser = false): bool
    {
        $status = true === (bool)ag($this->options, Options::IS_LIMITED_TOKEN, false);
        return true === $withUser ? $status && null !== $this->backendUser : $status;
    }

    public function hasLogger(): bool
    {
        return null !== $this->logger;
    }

    public function withLogger(iLogger $logger): self
    {
        $clone = clone $this;
        $clone->logger = $logger;

        return $clone;
    }

    public function getLogger(): iLogger
    {
        return $this->logger ?? Container::get(iLogger::class);
    }
}
