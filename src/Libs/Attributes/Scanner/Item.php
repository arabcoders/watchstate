<?php

declare(strict_types=1);

namespace App\Libs\Attributes\Scanner;

use App\Libs\Container;
use Closure;

final readonly class Item
{
    public function __construct(
        public Target $target,
        public string $attribute,
        public string|array|Closure $callable,
        public array $data = [],
    ) {}

    public function getCallable(): string|array|Closure
    {
        return $this->callable;
    }

    public function call(...$args): mixed
    {
        $callable = $this->callable;

        if (is_string($callable) && str_contains($callable, '::')) {
            $callable = explode('::', $callable);
        }

        if (is_array($callable) && isset($callable[0]) && is_object($callable[0])) {
            $callable = [$callable[0], $callable[1]];
        }

        if (is_array($callable) && isset($callable[0]) && is_string($callable[0])) {
            $callable = [$this->resolve($callable[0]), $callable[1]];
        }

        if (is_string($callable)) {
            $callable = $this->resolve($callable);
        }

        return $callable(...$args);
    }

    public function getTarget(): Target
    {
        return $this->target;
    }

    public function getAttribute(): string
    {
        return $this->attribute;
    }

    public function getData(): array
    {
        return $this->data;
    }

    private function resolve(string $class)
    {
        if (Container::has($class)) {
            return Container::get($class);
        }

        if (class_exists($class)) {
            return new $class();
        }

        return $class;
    }
}
