<?php

declare(strict_types=1);

namespace App\Backends\Common;

use App\Libs\Options;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface as iLogger;

final readonly class Context
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
        public iLogger|null $logger = null,
        public string|int|null $backendId = null,
        public string|int|null $backendToken = null,
        public string|int|null $backendUser = null,
        public array $backendHeaders = [],
        public bool $trace = false,
        public array $options = []
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

    /**
     * Add a logger to the context, and return a new instance.
     *
     * @param iLogger $logger
     *
     * @return static A new instance with the logger.
     */
    public function withLogger(iLogger $logger): self
    {
        return new Context(
            clientName: $this->clientName,
            backendName: $this->backendName,
            backendUrl: $this->backendUrl,
            cache: $this->cache,
            logger: $logger,
            backendId: $this->backendId,
            backendToken: $this->backendToken,
            backendUser: $this->backendUser,
            backendHeaders: $this->backendHeaders,
            trace: $this->trace,
            options: $this->options
        );
    }
}
