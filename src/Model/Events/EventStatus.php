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

    static public function fromName(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if (strtolower($case->name) === strtolower($name)) {
                return $case;
            }
        }
        return null;
    }
}
