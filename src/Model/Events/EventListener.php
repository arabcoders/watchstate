<?php

declare(strict_types=1);

namespace App\Model\Events;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class EventListener
{
    /**
     * Listen to an event.
     *
     * @param string $event Event to listen to.
     */
    public function __construct(
        public string $event,
    ) {}
}
