<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use Closure;
use Monolog\Level;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * LoggerProxy is PSR logger implementation that passes log messages to a callback function.
 */
final readonly class LoggerProxy implements LoggerInterface
{
    public function __construct(
        private Closure $callback,
    ) {}

    /**
     * Create a new LoggerProxy instance.
     *
     * @param Closure{mixed,string,array} $callback The callback to handle log messages.
     * @return self
     */
    public static function create(Closure $callback): self
    {
        return new self($callback);
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        if (false === $level instanceof Level) {
            $level = Level::tryFrom($level) ?? Level::Notice;
        }
        ($this->callback)($level, $message, $context);
    }

    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->log(Level::Emergency, $message, $context);
    }

    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->log(Level::Alert, $message, $context);
    }

    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->log(Level::Critical, $message, $context);
    }

    public function error(Stringable|string $message, array $context = []): void
    {
        $this->log(Level::Error, $message, $context);
    }

    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->log(Level::Warning, $message, $context);
    }

    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->log(Level::Notice, $message, $context);
    }

    public function info(Stringable|string $message, array $context = []): void
    {
        $this->log(Level::Info, $message, $context);
    }

    public function debug(Stringable|string $message, array $context = []): void
    {
        $this->log(Level::Debug, $message, $context);
    }
}
