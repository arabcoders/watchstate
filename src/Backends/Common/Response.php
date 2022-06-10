<?php

declare(strict_types=1);

namespace App\Backends\Common;

final class Response
{
    /**
     * Wrap Clients responses into easy to consume object.
     *
     * @param bool $status Whether the operation is Successful.
     * @param mixed $response the actual response.
     * @param Error|null $error If the response has an error, populate it using {@see Error} object.
     * @param mixed $extra an array that can contain anything.
     */
    public function __construct(
        public readonly bool $status,
        public readonly mixed $response = null,
        public readonly Error|null $error = null,
        public readonly array $extra = [],
    ) {
    }

    /**
     * Does the response contain an error object?
     */
    public function hasError(): bool
    {
        return null !== $this->error;
    }

    /**
     * Return Error response.
     */
    public function getError(): Error
    {
        return $this->error ?? new Error('No error logged.');
    }

    /**
     * Is the Operation Successful?
     */
    public function isSuccessful(): bool
    {
        return $this->status;
    }
}
