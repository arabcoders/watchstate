<?php

declare(strict_types=1);

namespace App\Libs\Events\Queue;

enum EventEnvelopeState: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case FAILED = 'failed';
}
