<?php

declare(strict_types=1);

namespace Tests\API\System;

use App\API\System\Stats;
use App\Libs\Events\Queue\ArrayEventTransport;
use App\Libs\Events\Queue\EventEnvelope;
use App\Libs\TestCase;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventStatus;

final class StatsTest extends TestCase
{
    public function test_get(): void
    {
        $events = $this->createMock(EventsRepository::class);
        $events
            ->expects(self::once())
            ->method('countByStatus')
            ->with(EventStatus::PENDING)
            ->willReturn(4);

        $transport = new ArrayEventTransport();
        $transport->enqueue(EventEnvelope::create('on_push'));
        $transport->enqueue(EventEnvelope::create('on_webhook'));

        $response = new Stats($events, $transport)->get();
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(4, ag($payload, 'events.pending'));
        self::assertSame(2, ag($payload, 'transport.pending'));
    }
}
