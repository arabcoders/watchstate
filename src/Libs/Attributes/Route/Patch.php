<?php

declare(strict_types=1);

namespace App\Libs\Attributes\Route;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Patch extends Route
{
    public function __construct(
        string $pattern,
        array|string $middleware = [],
        ?string $host = null,
        ?string $name = null,
        ?string $scheme = null,
        string|int|null $port = null,
    ) {
        parent::__construct(
            methods: ['PATCH'],
            pattern: $pattern,
            middleware: $middleware,
            host: $host,
            name: $name,
            scheme: $scheme,
            port: $port,
        );
    }
}
