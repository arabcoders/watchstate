<?php

declare(strict_types=1);

namespace App\Libs\Exceptions;

interface AppExceptionInterface
{
    /**
     * Add extra context to the exception.
     *
     * @param string $key The context key
     * @param mixed $val The context value
     *
     * @return AppExceptionInterface
     */
    public function addContext(string $key, mixed $val): AppExceptionInterface;

    /**
     * Replace the context with the provided array.
     *
     * @param array $context The context array.
     *
     * @return AppExceptionInterface
     */
    public function setContext(array $context): AppExceptionInterface;

    /**
     * Get the context array or a specific key from the context.
     *
     * @param string|null $key The context key.
     *
     * @return mixed
     */
    public function getContext(?string $key = null): mixed;

    /**
     * Does the exception contain context?
     *
     * @return bool
     */
    public function hasContext(): bool;
}
