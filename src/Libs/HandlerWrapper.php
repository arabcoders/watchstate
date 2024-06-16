<?php

declare(strict_types=1);

namespace App\Libs;


use Monolog\Handler\HandlerInterface as iHandler;
use Monolog\LogRecord;

/**
 * This class is a wrapper for the Monolog HandlerInterface.
 * It the user to suppress certain log records from being shown.
 */
readonly final class HandlerWrapper implements iHandler
{
    private int $count;

    public function __construct(private iHandler $handler, private array $suppress = [])
    {
        $this->count = count($this->suppress);
    }

    public function isHandling(LogRecord $record): bool
    {
        return $this->handler->isHandling($record);
    }

    public function handle(LogRecord $record): bool
    {
        if (true === $this->suppress($record)) {
            return true;
        }
        return $this->handler->handle($record);
    }

    public function handleBatch(array $records): void
    {
        $records = array_filter($records, fn($record) => !$this->suppress($record));
        $this->handler->handleBatch($records);
    }

    public function close(): void
    {
        $this->handler->close();
    }

    private function suppress(LogRecord $record): bool
    {
        if (0 === $this->count) {
            return false;
        }

        foreach ($this->suppress as $message) {
            if (str_starts_with($message, 're:')) {
                if (1 == @preg_match(after($message, 're:'), $record->message)) {
                    return true;
                }
                continue;
            }

            if (str_contains($record->message, $message)) {
                return true;
            }
        }

        return false;
    }
}
