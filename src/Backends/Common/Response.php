<?php

declare(strict_types=1);

namespace App\Backends\Common;

final class Response
{
    /**
     * Wrap clients responses into easy to consume object.
     *
     * @param bool $status Whether the operation is successful.
     * @param mixed $response The actual response.
     * @param Error|null $error If the response has an error.
     * @param mixed $extra An array that can contain anything. Should be rarely used.
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
     *
     * @return bool True if the response has an error.
     */
    public function hasError(): bool
    {
        return null !== $this->error;
    }

    /**
     * Return error response.
     *
     * @return Error the error object if exists otherwise dummy error object is returned.
     */
    public function getError(): Error
    {
        return $this->error ?? new Error('No error logged.');
    }

    /**
     * Is the operation successful?
     *
     * @return bool true if the operation is successful.
     */
    public function isSuccessful(): bool
    {
        return $this->status;
    }
}
