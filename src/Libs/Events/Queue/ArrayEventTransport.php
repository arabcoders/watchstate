<?php

declare(strict_types=1);

namespace App\Libs\Events\Queue;

final class ArrayEventTransport implements EventTransportInterface
{
    /** @var array<EventEnvelope> */
    private array $items = [];

    /** @var array<EventEnvelope> */
    private array $processing = [];

    /**
     * @inheritdoc
     */
    public function enqueue(EventEnvelope $envelope): EventEnvelope
    {
        $this->items[] = $envelope;

        return $envelope;
    }

    /**
     * @inheritdoc
     */
    public function dequeue(int $limit): array
    {
        $limit = max(1, $limit);
        $claimed = [];

        foreach ($this->items as $i => $envelope) {
            if (count($claimed) >= $limit) {
                break;
            }

            unset($this->items[$i]);
            $this->processing[$envelope->id] = $envelope;
            $claimed[] = $envelope;
        }

        $this->items = array_values($this->items);

        return $claimed;
    }

    /**
     * @inheritdoc
     */
    public function ack(EventEnvelope $envelope): void
    {
        unset($this->processing[$envelope->id]);
    }

    /**
     * @inheritdoc
     */
    public function release(EventEnvelope $envelope): void
    {
        unset($this->processing[$envelope->id]);
        $this->items[] = $envelope;
    }

    /**
     * @inheritdoc
     */
    public function fail(EventEnvelope $envelope): void
    {
        unset($this->processing[$envelope->id]);
    }

    /**
     * @inheritdoc
     */
    public function count(): int
    {
        return count($this->items);
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
        return array_slice($this->inspectable($state, $filter), max(0, $offset), max(1, $limit));
    }

    /**
     * @inheritdoc
     */
    public function inspectCount(?EventEnvelopeState $state = null, ?string $filter = null): int
    {
        return count($this->inspectable($state, $filter));
    }

    /**
     * @inheritdoc
     */
    public function inspectOne(string $id): ?EventEnvelope
    {
        foreach ($this->inspectable(null, null) as $envelope) {
            if ($envelope->id === $id) {
                return $envelope;
            }
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function inspectStates(): array
    {
        return [EventEnvelopeState::PENDING, EventEnvelopeState::PROCESSING];
    }

    /**
     * @return array<EventEnvelope>
     */
    private function inspectable(?EventEnvelopeState $state, ?string $filter): array
    {
        $items = array_values(array_merge(
            array_map(
                static fn(EventEnvelope $envelope): EventEnvelope => $envelope->withState(EventEnvelopeState::PENDING),
                $this->items,
            ),
            array_map(
                static fn(EventEnvelope $envelope): EventEnvelope => $envelope->withState(EventEnvelopeState::PROCESSING),
                $this->processing,
            ),
        ));

        $filter = strtolower(trim((string) $filter));

        return array_values(array_filter($items, static function (EventEnvelope $envelope) use ($state, $filter): bool {
            if (null !== $state && $envelope->state !== $state) {
                return false;
            }

            return '' === $filter || str_contains(strtolower($envelope->id . ' ' . $envelope->event), $filter);
        }));
    }
}
