<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Enums\Http\Status;
use Psr\Http\Message\StreamInterface;

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
        public StreamInterface|null $stream = null
    ) {
    }

    public function hasStream(): bool
    {
        return null !== $this->stream;
    }

    public function hasBody(): bool
    {
        return !empty($this->body);
    }
}
