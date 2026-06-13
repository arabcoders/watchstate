<?php

declare(strict_types=1);

namespace App\Libs\Events\Queue;

final class NullEventTransport implements EventTransportInterface
{
    /**
     * @inheritdoc
     */
    public function enqueue(EventEnvelope $envelope): EventEnvelope
    {
        return $envelope;
    }

    /**
     * @inheritdoc
     */
    public function dequeue(int $limit): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function ack(EventEnvelope $envelope): void {}

    /**
     * @inheritdoc
     */
    public function release(EventEnvelope $envelope): void {}

    /**
     * @inheritdoc
     */
    public function fail(EventEnvelope $envelope): void {}

    /**
     * @inheritdoc
     */
    public function count(): int
    {
        return 0;
    }
}
