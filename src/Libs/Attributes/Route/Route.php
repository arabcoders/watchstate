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
    public readonly ?string $host;
    public readonly ?string $name;
    public readonly ?string $scheme;
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
        ?string $host = null,
        ?string $name = null,
        ?string $scheme = null,
        string|int|null $port = null,
        array $opts = [],
    ) {
        $this->name = $name;
        $this->methods = $methods;
        $this->pattern = parse_config_value($pattern);
        $this->middleware = is_string($middleware) ? [$middleware] : $middleware;
        $this->port = null !== $port ? parse_config_value($port) : $port;
        $this->scheme = null !== $scheme ? parse_config_value($scheme) : $scheme;
        $this->host = null !== $host ? parse_config_value($host, static fn($v) => parse_url($v, PHP_URL_HOST)) : $host;

        $this->isCli = true === (bool) ag($opts, 'cli', false);
    }
}
