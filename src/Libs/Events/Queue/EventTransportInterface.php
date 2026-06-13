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
}
