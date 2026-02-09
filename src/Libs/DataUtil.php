<?php

declare(strict_types=1);

namespace App\Libs;

use Closure;
use JsonSerializable;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Stringable;

final readonly class DataUtil implements JsonSerializable, Stringable
{
    private array $data;

    public function __construct(Closure|array $data)
    {
        $this->data = get_value($data);
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function fromRequest(iRequest $request, bool $includeQueryParams = false): self
    {
        $params = $includeQueryParams ? $request->getQueryParams() : [];

        if (null !== ($data = $request->getParsedBody())) {
            $params = array_replace_recursive($params, is_object($data) ? (array) $data : $data);
        }

        return new self($params);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return ag($this->data, $key, $default);
    }

    public function getAll(): array
    {
        return $this->data;
    }

    public function has(string $key): bool
    {
        return ag_exists($this->data, $key);
    }

    public function map(callable $callback): self
    {
        return new self(array_map($callback, $this->data));
    }

    public function filter(callable $callback): self
    {
        return new self(array_filter($this->data, $callback, ARRAY_FILTER_USE_BOTH));
    }

    public function with(string $key, mixed $value): self
    {
        return new self(ag_set($this->data, $key, $value));
    }

    public function without(string $key): self
    {
        return new self(ag_delete($this->data, $key));
    }

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }

    public function __toString(): string
    {
        return json_encode($this->jsonSerialize());
    }
}
