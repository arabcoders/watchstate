<?php

declare(strict_types=1);

namespace App\Backends\Common;

use App\Libs\Enums\Http\Method;
use App\Libs\Uri;
use Closure;

final readonly class Request
{
    public readonly string $id;

    /**
     * Wrap client requests into object.
     *
     * @param Method $method The HTTP method to use.
     * @param Uri|string $url The URL to send the request to.
     * @param array $options The options to pass to the request.
     * @param callable|null $success The callback to call on successful response.
     * @param callable|null $error The callback to call on error response.
     * @param array $extras An array that can contain anything. Should be rarely used.
     */
    public function __construct(
        public Method $method,
        public Uri|string $url,
        public array $options = [],
        public Closure|null $success = null,
        public Closure|null $error = null,
        public array $extras = [],
    ) {
        $this->id = generateUUID();
    }

    public function toRequest(): array
    {
        return [
            'method' => $this->method->value,
            'url' => (string)$this->url,
            'options' => $this->options,
        ];
    }

    public function __debugInfo()
    {
        return [
            'id' => $this->id,
            'method' => $this->method,
            'url' => $this->url,
            'options' => $this->options,
            'success' => $this->success,
            'error' => $this->error,
        ];
    }
}
