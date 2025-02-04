<?php

declare(strict_types=1);

namespace App\Libs\Attributes\Route;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Options extends Route
{
    public function __construct(
        string $pattern,
        array|string $middleware = [],
        string|null $host = null,
        string|null $name = null,
        string|null $scheme = null,
        string|int|null $port = null,
    ) {
        parent::__construct(
            methods: ['OPTIONS'],
            pattern: $pattern,
            middleware: $middleware,
            host: $host,
            name: $name,
            scheme: $scheme,
            port: $port
        );
    }
}
