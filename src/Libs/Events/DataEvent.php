<?php

declare(strict_types=1);

namespace App\Libs\Events;

use App\Model\Events\Event as EventInfo;
use App\Model\Events\EventStatus;
use Monolog\Level;
use Symfony\Contracts\EventDispatcher\Event;

class DataEvent extends Event
{
    private EventStatus $status;
    private ?Level $visibleLevel = null;
    private bool $hasVisibleLogs = false;

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

    public function addLog(Level $level, string $message, array $context = []): bool
    {
        $added = $this->eventInfo->addLog($level, $message, $context);

        if (true === $added && null !== $this->visibleLevel && $level->value >= $this->visibleLevel->value) {
            $this->hasVisibleLogs = true;
        }

        return $added;
    }

    /**
     * Track persisted typed logs at or above the dispatch-visible level.
     */
    public function setVisibleLevel(?Level $visibleLevel): DataEvent
    {
        $this->visibleLevel = $visibleLevel;
        $this->hasVisibleLogs = false;
        return $this;
    }

    /**
     * Check whether a persisted typed log met the tracked visible level.
     */
    public function hasVisibleLogs(): bool
    {
        return $this->hasVisibleLogs;
    }

    /**
     * Reset the tracked visible-log flag.
     */
    public function resetVisibleLogs(): void
    {
        $this->hasVisibleLogs = false;
    }

    /**
     * Direct usage of this function is discouraged.
     * Use {@see self::addLog()} instead to ensure log levels are respected.
     *
     * @param string $log The log message to add.
     */
    public function addRawLog(string $log): void
    {
        $this->eventInfo->addRawLog($log);
    }

    public function clearLogs(): void
    {
        $this->eventInfo->clearLogs();
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
}
