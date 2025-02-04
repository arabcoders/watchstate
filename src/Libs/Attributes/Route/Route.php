<?php

declare(strict_types=1);

namespace App\Libs\Attributes\Route;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Route
{
    public readonly bool $isCli;
    public readonly array $methods;
    public readonly string $pattern;
    public readonly array $middleware;
    public readonly string|null $host;
    public readonly string|null $name;
    public readonly string|null $scheme;
    public readonly string|int|null $port;

    /**
     * Generate Dynamic Route.
     *
     * @param array $methods HTTP Methods.
     * @param string $pattern Path pattern.
     * @param array|string $middleware List of middlewares.
     * @param string|null $host Required host. Use %{config.name} for config value
     * @param string|null $name Route name.
     * @param string|null $scheme Request scheme. Use %{config.name} for config value
     * @param string|int|null $port Request Port. Use %{config.name} for config value
     */
    public function __construct(
        array $methods,
        string $pattern,
        array|string $middleware = [],
        string|null $host = null,
        string|null $name = null,
        string|null $scheme = null,
        string|int|null $port = null,
        array $opts = []
    ) {
        $this->name = $name;
        $this->methods = $methods;
        $this->pattern = parseConfigValue($pattern);
        $this->middleware = is_string($middleware) ? [$middleware] : $middleware;
        $this->port = null !== $port ? parseConfigValue($port) : $port;
        $this->scheme = null !== $scheme ? parseConfigValue($scheme) : $scheme;
        $this->host = null !== $host ? parseConfigValue($host, fn($v) => parse_url($v, PHP_URL_HOST)) : $host;

        $this->isCli = true === (bool)ag($opts, 'cli', false);
    }
}
