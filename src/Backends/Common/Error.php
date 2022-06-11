<?php

declare(strict_types=1);

namespace App\Backends\Common;

use Monolog\Utils;
use Throwable;

final class Error
{
    /**
     * Wrap Error in easy to consume way.
     *
     * @param string $message Error message.
     * @param array $context Error message context.
     * @param Levels $level Which log level the error should be logged into.
     * @param Throwable|null $previous Previous exception stack trace.
     */
    public function __construct(
        public readonly string $message,
        public readonly array $context = [],
        public readonly Levels $level = Levels::ERROR,
        public readonly Throwable|null $previous = null,
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
        return true === str_contains($this->message, '%(');
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

        $pattern = '#' . preg_quote('%(', '#') . '([\w\d_.]+)' . preg_quote(')', '#') . '#is';

        $status = preg_match_all($pattern, $this->message, $matches);

        if (false === $status || $status < 1) {
            return $this->message;
        }

        $replacements = [];
        $context = $this->context;

        foreach ($matches[1] as $key) {
            $placeholder = '%(' . $key . ')';

            if (false === str_contains($this->message, $placeholder)) {
                continue;
            }

            if (false === ag_exists($context, $key)) {
                continue;
            }

            $val = ag($context, $key);

            $context = ag_delete($context, $key);

            if (is_null($val) || is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replacements[$placeholder] = $val;
            } elseif (is_object($val)) {
                $replacements[$placeholder] = '[object ' . Utils::getClass($val) . ']';
            } elseif (is_array($val)) {
                $replacements[$placeholder] = 'array' . Utils::jsonEncode($val, null, true);
            } else {
                $replacements[$placeholder] = '[' . gettype($val) . ']';
            }
        }

        return strtr($this->message, $replacements);
    }
}
