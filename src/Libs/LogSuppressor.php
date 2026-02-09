<?php

declare(strict_types=1);

namespace App\Libs;

use Monolog\Handler\HandlerInterface as iHandler;
use Monolog\LogRecord;

/**
 * This class is a wrapper for the Monolog HandlerInterface.
 * It the user to suppress certain log records from being shown.
 */
final class LogSuppressor implements iHandler
{
    private static array $suppress = [];
    private static int $count = 0;
    private ?iHandler $handler;

    public function __construct(array $suppress, ?iHandler $handler = null)
    {
        self::$suppress = $suppress;
        self::$count = count($suppress);
        $this->handler = $handler;
    }

    /**
     * This method sets the handler to be used for handling log records.
     *
     * @param iHandler $handler The handler to be used for handling log records
     *
     * @return self Returns an instance of the class
     */
    public function withHandler(iHandler $handler): self
    {
        $instance = clone $this;
        $instance->handler = $handler;
        return $instance;
    }

    /**
     * This method suppresses log records that match the given criteria.
     *
     * @param LogRecord|string $record The log record to be suppressed
     *
     * @return bool Returns true if the log record should be suppressed, false otherwise
     */
    public function isSuppressed(LogRecord|string $record): bool
    {
        if (0 === self::$count) {
            return false;
        }

        $log = $record instanceof LogRecord ? $record->message : $record;

        if (empty($log)) {
            return false;
        }

        foreach (self::$suppress as $suppress) {
            $rule = ag($suppress, 'rule', '');
            if (empty($rule)) {
                continue;
            }
            if ('regex' === ag($suppress, 'type', 'contains')) {
                if (1 === @preg_match($rule, $log)) {
                    return true;
                }
                continue;
            }

            if (str_contains($log, $rule)) {
                return true;
            }
        }

        return false;
    }

    public function isHandling(LogRecord $record): bool
    {
        return $this->handler?->isHandling($record) ?? false;
    }

    public function handle(LogRecord $record): bool
    {
        if (true === $this->isSuppressed($record)) {
            return true;
        }
        return $this->handler?->handle($record) ?? false;
    }

    public function handleBatch(array $records): void
    {
        $records = array_filter($records, fn($record) => false === $this->isSuppressed($record));
        if (count($records) > 0) {
            $this->handler?->handleBatch($records);
        }
    }

    public function close(): void
    {
        $this->handler?->close();
    }
}
