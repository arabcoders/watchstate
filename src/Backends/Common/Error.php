<?php

declare(strict_types=1);

namespace App\Backends\Common;

use Stringable;
use Throwable;

final readonly class Error implements Stringable
{
    /**
     * Wrap error in easy to consume way.
     *
     * @param string $message Error message.
     * @param array $context Error message context.
     * @param Levels $level Which log level the error should be logged into.
     * @param Throwable|null $previous Previous exception stack trace.
     */
    public function __construct(
        public string $message,
        public array $context = [],
        public Levels $level = Levels::ERROR,
        public Throwable|null $previous = null,
    ) {
    }

    /**
     * Does the error object contain previous stack trace?
     *
     * @return bool
     */
    public function hasException(): bool
    {
        return null !== $this->previous;
    }

    /**
     * Does the message contain tags?
     *
     * @return bool
     */
    public function hasTags(): bool
    {
        return true === str_contains($this->message, '{') && true === str_contains($this->message, '}');
    }

    /**
     * Get which log level should this message be logged into.
     *
     * @return string
     */
    public function level(): string
    {
        return $this->level->value;
    }

    /**
     * This method convert our logging messages into an actual human-readable message.
     * If no tags are found. we simply return the message as it is.
     *
     * @return string
     */
    public function format(): string
    {
        if (false === $this->hasTags()) {
            return $this->message;
        }

        return r($this->message, $this->context, ['log_behavior' => true]);
    }

    public function __toString(): string
    {
        $params = [
            'level' => $this->level->value,
            'message' => $this->format(),
        ];

        if ($this->hasException()) {
            $params['line'] = $this->previous->getLine();
            $params['file'] = after($this->previous->getFile(), ROOT_PATH);
        }

        return r('{level}: {message}' . ($this->hasException() ? '. In [{file}:{line}].' : ''), $params);
    }
}
