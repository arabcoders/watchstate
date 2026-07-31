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

    /**
     * @inheritdoc
     */
    public function inspect(
        int $limit = 100,
        int $offset = 0,
        ?EventEnvelopeState $state = null,
        ?string $filter = null,
    ): array {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function inspectCount(?EventEnvelopeState $state = null, ?string $filter = null): int
    {
        return 0;
    }

    /**
     * @inheritdoc
     */
    public function inspectOne(string $id): ?EventEnvelope
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function inspectStates(): array
    {
        return [];
    }
}
