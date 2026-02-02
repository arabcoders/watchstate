<?php

declare(strict_types=1);

namespace App\Libs\Events;

use App\Model\Events\Event as EventInfo;
use App\Model\Events\EventStatus;
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

    public function addLog(string $log): void
    {
        if (count($this->eventInfo->logs) >= 200) {
            array_shift($this->eventInfo->logs);
        }
        $this->eventInfo->logs[] = $log;
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
}
