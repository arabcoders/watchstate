<?php

declare(strict_types=1);

namespace Tests\Libs\Events;

use App\Libs\Events\Queue\EventEnvelope;
use App\Libs\Events\Queue\FilesystemEventTransport;
use App\Libs\TestCase;

final class FilesystemEventTransportTest extends TestCase
{
    public function test_ack(): void
    {
        $transport = $this->transport();
        $transport->enqueue(EventEnvelope::create('on_push', ['ok' => true]));

        $events = $transport->dequeue(10);

        self::assertCount(1, $events);
        self::assertSame('on_push', $events[0]->event);
        self::assertSame(['ok' => true], $events[0]->data);

        $transport->ack($events[0]);

        self::assertSame(0, $transport->count());
        self::assertSame([], $transport->dequeue(10));
    }

    public function test_reclaims_stale(): void
    {
        $transport = $this->transport(claimAfterSeconds: 1);
        $transport->enqueue(EventEnvelope::create('on_webhook', ['ok' => true]));

        $events = $transport->dequeue(10);
        self::assertCount(1, $events);
        self::assertIsString($events[0]->ack);
        touch($events[0]->ack, time() - 10);

        $reclaimed = $transport->dequeue(10);

        self::assertCount(1, $reclaimed);
        self::assertSame($events[0]->id, $reclaimed[0]->id);
        self::assertSame($events[0]->event, $reclaimed[0]->event);
        self::assertSame($events[0]->data, $reclaimed[0]->data);
    }

    private function transport(int $claimAfterSeconds = 300): FilesystemEventTransport
    {
        $this->initTempDir();

        return new FilesystemEventTransport(self::$tmpPath . '/queue/events', $claimAfterSeconds);
    }
}
