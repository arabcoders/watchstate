<?php

declare(strict_types=1);

namespace Tests\Libs\Events;

use App\Libs\Events\Queue\ArrayEventTransport;
use App\Libs\Events\Queue\EventEnvelope;
use App\Libs\Events\Queue\NullEventTransport;
use App\Libs\TestCase;

final class ArrayAndNullEventTransportTest extends TestCase
{
    public function test_null_enqueue(): void
    {
        $transport = new NullEventTransport();
        $envelope = EventEnvelope::create('on_push', ['ok' => true]);

        self::assertSame($envelope, $transport->enqueue($envelope));
    }

    public function test_null_dequeue(): void
    {
        $transport = new NullEventTransport();
        $transport->enqueue(EventEnvelope::create('on_push', ['ok' => true]));

        self::assertSame([], $transport->dequeue(10));
        self::assertSame(0, $transport->count());
    }

    public function test_array_enqueue(): void
    {
        $transport = new ArrayEventTransport();
        $envelope = EventEnvelope::create('on_push', ['ok' => true]);

        self::assertSame($envelope, $transport->enqueue($envelope));
        self::assertSame(1, $transport->count());
    }

    public function test_array_dequeue(): void
    {
        $transport = new ArrayEventTransport();
        $transport->enqueue(EventEnvelope::create('on_push', ['ok' => true]));
        $transport->enqueue(EventEnvelope::create('on_progress', ['progress' => 50]));

        $events = $transport->dequeue(10);

        self::assertCount(2, $events);
        self::assertSame('on_push', $events[0]->event);
        self::assertSame('on_progress', $events[1]->event);
        self::assertSame(0, $transport->count());
    }

    public function test_array_release(): void
    {
        $transport = new ArrayEventTransport();
        $transport->enqueue(EventEnvelope::create('on_push', ['ok' => true]));

        $events = $transport->dequeue(10);
        self::assertCount(1, $events);
        self::assertSame(0, $transport->count());

        $transport->release($events[0]);

        self::assertSame(1, $transport->count());

        $reclaimed = $transport->dequeue(10);
        self::assertCount(1, $reclaimed);
        self::assertSame($events[0]->id, $reclaimed[0]->id);
    }
}
