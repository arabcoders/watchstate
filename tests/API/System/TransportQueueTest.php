<?php

declare(strict_types=1);

namespace Tests\API\System;

use App\API\System\TransportQueue;
use App\Libs\Enums\Http\Status;
use App\Libs\Events\Queue\ArrayEventTransport;
use App\Libs\Events\Queue\EventEnvelope;
use App\Libs\TestCase;
use Tests\Support\RequestResponseTrait;

final class TransportQueueTest extends TestCase
{
    use RequestResponseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initTempApp();
    }

    public function test_list(): void
    {
        $transport = new ArrayEventTransport();
        $first = EventEnvelope::create('on_webhook', ['payload' => 'first']);
        $second = EventEnvelope::create('on_push', ['payload' => 'second']);
        $transport->enqueue($first);
        $transport->enqueue($second);

        $handler = new TransportQueue($transport);
        $queueResponse = $handler->list($this->getRequest(query: ['page' => 2, 'perpage' => 1]));
        self::assertSame(Status::OK->value, $queueResponse->getStatusCode());
        $queuePayload = json_decode((string) $queueResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($second->id, ag($queuePayload, 'items.0.id'));
        self::assertArrayNotHasKey('data', $queuePayload['items'][0]);
        self::assertArrayNotHasKey('options', $queuePayload['items'][0]);
        self::assertSame(2, ag($queuePayload, 'paging.page'));
        self::assertSame(2, ag($queuePayload, 'paging.total'));
        self::assertSame(1, ag($queuePayload, 'paging.perpage'));
        self::assertSame(1, ag($queuePayload, 'paging.previous'));
        self::assertNull(ag($queuePayload, 'paging.next'));
        self::assertSame(['pending', 'processing'], ag($queuePayload, 'states'));
    }

    public function test_filter(): void
    {
        $transport = new ArrayEventTransport();
        $webhook = EventEnvelope::create('on_webhook');
        $transport->enqueue($webhook);
        $transport->enqueue(EventEnvelope::create('on_push'));
        $transport->dequeue(1);

        $response = new TransportQueue($transport)->list($this->getRequest(query: [
            'state' => 'processing',
            'filter' => 'webhook',
        ]));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($webhook->id, ag($payload, 'items.0.id'));
        self::assertSame('processing', ag($payload, 'items.0.state'));
        self::assertSame(1, ag($payload, 'paging.total'));
    }

    public function test_view(): void
    {
        $transport = new ArrayEventTransport();
        $envelope = EventEnvelope::create('on_webhook', ['payload' => 'view'], ['delay' => 5]);
        $transport->enqueue($envelope);

        $response = new TransportQueue($transport)->view($envelope->id);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Status::OK->value, $response->getStatusCode());
        self::assertSame($envelope->id, ag($payload, 'id'));
        self::assertSame('view', ag($payload, 'data.payload'));
        self::assertSame(5, ag($payload, 'options.delay'));
    }

    public function test_view_missing(): void
    {
        $response = new TransportQueue(new ArrayEventTransport())->view(generate_uuid());

        self::assertSame(Status::NOT_FOUND->value, $response->getStatusCode());
    }

    public function test_invalid_state(): void
    {
        $response = new TransportQueue(new ArrayEventTransport())->list($this->getRequest(query: [
            'state' => 'unknown',
        ]));

        self::assertSame(Status::BAD_REQUEST->value, $response->getStatusCode());
    }
}
