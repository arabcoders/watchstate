<?php

namespace App\Libs\Events;

use App\Model\Events\Event as EventInfo;
use Symfony\Contracts\EventDispatcher\Event;

class DataEvent extends Event
{
    public function __construct(private readonly EventInfo $eventInfo)
    {
    }

    public function getEvent(): EventInfo
    {
        return $this->eventInfo;
    }

    public function addLog(string $log): void
    {
        $this->eventInfo->logs[] = $log;
    }

    public function getLogs(): array
    {
        return $this->eventInfo->logs;
    }

    public function getData(): array
    {
        return $this->eventInfo->event_data;
    }
}
