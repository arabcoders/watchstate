<?php

declare(strict_types=1);

namespace App\Model\Events;

enum EventStatus: int
{
    case PENDING = 0;
    case RUNNING = 1;
    case SUCCESS = 2;
    case FAILED = 3;
    case CANCELLED = 4;
}
