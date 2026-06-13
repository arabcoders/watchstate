<?php

declare(strict_types=1);

namespace App\Libs\Events\Queue;

final class ArrayEventTransport implements EventTransportInterface
{
    /** @var array<EventEnvelope> */
    private array $items = [];

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
            $claimed[] = $envelope;
        }

        $this->items = array_values($this->items);

        return $claimed;
    }

    /**
     * @inheritdoc
     */
    public function ack(EventEnvelope $envelope): void {}

    /**
     * @inheritdoc
     */
    public function release(EventEnvelope $envelope): void
    {
        $this->items[] = $envelope;
    }

    /**
     * @inheritdoc
     */
    public function fail(EventEnvelope $envelope): void {}

    /**
     * @inheritdoc
     */
    public function count(): int
    {
        return count($this->items);
    }
}
