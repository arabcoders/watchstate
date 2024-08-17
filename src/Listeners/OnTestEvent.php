<?php

declare(strict_types=1);

namespace App\Listeners;

use App\libs\Events\DataEvent;
use App\Model\Events\EventListener;

#[EventListener(self::NAME)]
final readonly class OnTestEvent
{
    public const string NAME = 'test_event';

    public function __invoke(DataEvent $e): DataEvent
    {
        $e->stopPropagation();
        return $e;
    }
}
