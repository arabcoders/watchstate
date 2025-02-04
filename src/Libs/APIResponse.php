<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Enums\Http\Status;
use Psr\Http\Message\StreamInterface as iStream;

/**
 * Class Config
 *
 * This class provides methods to manage configuration settings.
 */
final readonly class APIResponse
{
    public function __construct(
        public Status $status,
        public array $headers = [],
        public array $body = [],
        public iStream|null $stream = null
    ) {
    }

    /**
     * Check if the response has a stream.
     *
     * @return bool True if the response has a stream, false otherwise.
     */
    public function hasStream(): bool
    {
        return null !== $this->stream;
    }

    /**
     * Check if the response has a body.
     *
     * @return bool True if the response has a body, false otherwise.
     */
    public function hasBody(): bool
    {
        return !empty($this->body);
    }

    /**
     * Check if the response has headers.
     *
     * @return bool  True if the response has headers, false otherwise.
     */
    public function hasHeaders(): bool
    {
        return !empty($this->headers);
    }

    /**
     * Get Header from the response headers.
     *
     * @param string $key The key to get from the headers.
     * @param mixed|null $default The default value to return if the key is not found.
     *
     * @return mixed The value of the key from the headers. If the key is not found, the default value is returned.
     */
    public function getHeader(string $key, mixed $default = null): mixed
    {
        return ag($this->headers, $key, $default);
    }

    /**
     * Get Parameter from the parsed body content.
     *
     * @param string $key The key to get from the body.
     * @param mixed|null $default The default value to return if the key is not found.
     *
     * @return mixed The value of the key from the body. If the key is not found, the default value is returned.
     */
    public function getParam(string $key, mixed $default = null): mixed
    {
        return ag($this->body, $key, $default);
    }
}
