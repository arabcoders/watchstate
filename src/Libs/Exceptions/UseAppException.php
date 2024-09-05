<?php

namespace App\Libs\Exceptions;

trait UseAppException
{
    protected array $appContext = [];

    public function addContext(string $key, mixed $value): AppExceptionInterface
    {
        $this->appContext = ag_set($this->appContext, $key, $value);
        return $this;
    }

    public function setContext(array $context): AppExceptionInterface
    {
        $this->appContext = $context;

        return $this;
    }

    public function getContext(string|null $key = null): mixed
    {
        if (null === $key) {
            return $this->appContext;
        }

        return ag($this->appContext, $key);
    }

    public function hasContext(): bool
    {
        return !empty($this->appContext);
    }
}
