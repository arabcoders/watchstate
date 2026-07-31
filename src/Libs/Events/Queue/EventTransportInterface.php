<?php

declare(strict_types=1);

namespace App\Libs\Events\Queue;

interface EventTransportInterface
{
    /**
     * Push a new event envelope into the transport.
     */
    public function enqueue(EventEnvelope $envelope): EventEnvelope;

    /**
     * Claim up to the requested number of queued event envelopes.
     *
     * @return array<EventEnvelope>
     */
    public function dequeue(int $limit): array;

    /**
     * Acknowledge successful processing and remove the envelope from the transport.
     */
    public function ack(EventEnvelope $envelope): void;

    /**
     * Release a claimed envelope back to the transport for a later retry.
     */
    public function release(EventEnvelope $envelope): void;

    /**
     * Reject a malformed or permanently failed envelope.
     */
    public function fail(EventEnvelope $envelope): void;

    /**
     * Count currently queued envelopes when supported by the transport.
     */
    public function count(): int;

    /**
     * Inspect envelopes currently held by the transport without claiming them.
     *
     * @return array<EventEnvelope>
     */
    public function inspect(
        int $limit = 100,
        int $offset = 0,
        ?EventEnvelopeState $state = null,
        ?string $filter = null,
    ): array;

    /**
     * Count envelopes visible through transport inspection.
     */
    public function inspectCount(?EventEnvelopeState $state = null, ?string $filter = null): int;

    /**
     * Find one envelope by its exact identifier without claiming it.
     */
    public function inspectOne(string $id): ?EventEnvelope;

    /**
     * Return the states exposed by this transport.
     *
     * @return array<EventEnvelopeState>
     */
    public function inspectStates(): array;
}
