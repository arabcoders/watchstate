<?php

declare(strict_types=1);

namespace App\Libs\Events;

use App\Libs\Extends\JsonlFormatter;
use App\Libs\Options;
use App\Model\Events\Event as EventInfo;
use App\Model\Events\EventStatus;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Contracts\EventDispatcher\Event;

class DataEvent extends Event
{
    private EventStatus $status;

    public function __construct(
        private readonly EventInfo $eventInfo,
    ) {
        $this->status = $eventInfo->status;
    }

    public function getStatus(): EventStatus
    {
        return $this->status;
    }

    public function setStatus(EventStatus $status): DataEvent
    {
        $this->status = $status;
        return $this;
    }

    public function getEvent(): EventInfo
    {
        return $this->eventInfo;
    }

    public function getReference(): ?string
    {
        return $this->eventInfo->reference;
    }

    public function addLog(string $log, mixed $record = null): void
    {
        if (false === $this->shouldPersistRecord($log, $record)) {
            return;
        }

        if (count($this->eventInfo->logs) >= 200) {
            array_shift($this->eventInfo->logs);
        }

        $this->eventInfo->logs[] = $this->normalizeLog($log, $record);
    }

    /**
     * Add a structured log entry to the event log.
     *
     * @param array<string,mixed> $context
     */
    public function addLogEntry(Level $level, string $message, array $context = [], string $channel = 'event'): void
    {
        if (false === $this->shouldPersistLevel($level)) {
            return;
        }

        $this->addLog(new JsonlFormatter()->formatValues(
            channel: $channel,
            level: $level,
            message: r($message, $context, ['log_behavior' => true]),
            context: array_replace($context, $this->eventContext()),
        ));
    }

    public function clearLogs(): void
    {
        $this->eventInfo->logs = [];
    }

    public function getLogs(): array
    {
        return $this->eventInfo->logs;
    }

    public function getData(): array
    {
        return $this->eventInfo->event_data;
    }

    public function getOptions(): array
    {
        return $this->eventInfo->options;
    }

    private function normalizeLog(string $log, mixed $record = null): string
    {
        if (true === JsonlFormatter::isJsonlRecord($log)) {
            return rtrim($log, "\r\n") . PHP_EOL;
        }

        $formatter = new JsonlFormatter();
        $context = $this->eventContext();

        if ($record instanceof LogRecord) {
            return $formatter->format($record->with(context: array_replace($record->context, $context)));
        }

        return $formatter->formatValues(
            channel: 'event',
            level: $this->detectLevel($log),
            message: $log,
            context: $context,
        );
    }

    /**
     * @return array{event_id:string,event:string}
     */
    private function eventContext(): array
    {
        return [
            'event_id' => (string) ($this->eventInfo->id ?? ''),
            'queued_event' => $this->eventInfo->event,
        ];
    }

    private function detectLevel(string $log): Level
    {
        $levelRegex = '/^(?:\[[^\]]+]\s*)?(?:[a-z0-9_.-]+\.)?(?<level>EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG):\s*/i';

        if (1 !== preg_match($levelRegex, trim($log), $matches)) {
            return Level::Info;
        }

        try {
            return Level::fromName(strtoupper((string) $matches['level']));
        } catch (\ValueError) {
            return Level::Info;
        }
    }

    private function shouldPersistRecord(string $log, mixed $record): bool
    {
        if ($record instanceof LogRecord) {
            return $this->shouldPersistLevel($record->level);
        }

        return $this->shouldPersistLevel($this->detectLevel($log));
    }

    private function shouldPersistLevel(Level $level): bool
    {
        if (true === (bool) ($this->eventInfo->options[Options::DEBUG_TRACE] ?? false)) {
            return true;
        }

        return $level->value >= Level::Info->value;
    }
}
