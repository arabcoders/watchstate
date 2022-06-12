<?php

declare(strict_types=1);

namespace App\Backends\Common;

use Psr\Http\Message\UriInterface;

class Context
{
    public function __construct(
        public readonly string $clientName,
        public readonly string $backendName,
        public readonly UriInterface $backendUrl,
        public readonly string|int|null $backendId = null,
        public readonly string|int|null $backendToken = null,
        public readonly string|int|null $backendUser = null,
        public readonly array $backendHeaders = [],
        public readonly bool $trace = false,
        public readonly array $options = []
    ) {
    }
}
