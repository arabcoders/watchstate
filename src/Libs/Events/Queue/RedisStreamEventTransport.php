<?php

declare(strict_types=1);

namespace App\Libs\Events\Queue;

use JsonException;
use Redis;
use RuntimeException;
use Throwable;

final class RedisStreamEventTransport implements EventTransportInterface
{
    private bool $groupReady = false;

    public function __construct(
        private readonly Redis $redis,
        private readonly string $stream,
        private readonly string $group,
        private readonly string $consumer,
        private readonly int $claimAfterMs = 300_000,
    ) {}

    /**
     * @inheritdoc
     */
    public function enqueue(EventEnvelope $envelope): EventEnvelope
    {
        $this->createGroup();

        try {
            $payload = json_encode($envelope->toArray(), flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(r('Unable to encode queue envelope: {error}', ['error' => $e->getMessage()]), previous: $e);
        }

        $id = $this->redis->xAdd($this->stream, '*', ['payload' => $payload]);
        if (false === is_string($id) || '' === $id) {
            throw new RuntimeException(r("Unable to append event to Redis stream '{stream}'.", ['stream' => $this->stream]));
        }

        return $envelope->withAck($id);
    }

    /**
     * @inheritdoc
     */
    public function dequeue(int $limit): array
    {
        $this->createGroup();

        $limit = max(1, $limit);
        $items = $this->claimStale($limit);
        $remaining = $limit - count($items);

        if ($remaining < 1) {
            return $items;
        }

        return [...$items, ...$this->readNew($remaining)];
    }

    /**
     * @inheritdoc
     */
    public function ack(EventEnvelope $envelope): void
    {
        if (!is_string($envelope->ack) || '' === $envelope->ack) {
            return;
        }

        $this->redis->xAck($this->stream, $this->group, [$envelope->ack]);
        $this->redis->xDel($this->stream, [$envelope->ack]);
    }

    /**
     * @inheritdoc
     */
    public function release(EventEnvelope $envelope): void
    {
        // Redis Streams keep unacked messages in the pending list. They will be reclaimed later.
    }

    /**
     * @inheritdoc
     */
    public function fail(EventEnvelope $envelope): void
    {
        $this->ack($envelope);
    }

    /**
     * @inheritdoc
     */
    public function count(): int
    {
        $count = $this->redis->xLen($this->stream);

        return is_int($count) ? $count : 0;
    }

    private function createGroup(): void
    {
        if (true === $this->groupReady) {
            return;
        }

        try {
            $this->redis->xGroup('CREATE', $this->stream, $this->group, '0', true);
        } catch (Throwable $e) {
            if (false === str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }

        $this->groupReady = true;
    }

    /**
     * @return array<EventEnvelope>
     */
    private function readNew(int $limit): array
    {
        try {
            $records = $this->redis->xReadGroup($this->group, $this->consumer, [$this->stream => '>'], $limit);
        } catch (Throwable) {
            return [];
        }

        return $this->parseReadRecords(is_array($records) ? $records : []);
    }

    /**
     * @return array<EventEnvelope>
     */
    private function claimStale(int $limit): array
    {
        try {
            $records = $this->redis->rawCommand(
                'XAUTOCLAIM',
                $this->stream,
                $this->group,
                $this->consumer,
                (string) $this->claimAfterMs,
                '0-0',
                'COUNT',
                (string) $limit,
            );
        } catch (Throwable) {
            return [];
        }

        if (false === is_array($records) || false === isset($records[1]) || false === is_array($records[1])) {
            return [];
        }

        return $this->parseStreamEntries($records[1]);
    }

    /**
     * @param array<mixed> $records
     * @return array<EventEnvelope>
     */
    private function parseReadRecords(array $records): array
    {
        $streamRecords = $records[$this->stream] ?? [];
        if (false === is_array($streamRecords)) {
            return [];
        }

        return $this->parseStreamEntries($streamRecords);
    }

    /**
     * @param array<mixed> $entries
     * @return array<EventEnvelope>
     */
    private function parseStreamEntries(array $entries): array
    {
        $items = [];

        foreach ($entries as $id => $fields) {
            if (is_array($fields) && array_key_exists(0, $fields) && is_string($fields[0] ?? null)) {
                $id = $fields[0];
                $fields = $fields[1] ?? [];
            }

            if (false === is_string($id) || false === is_array($fields)) {
                continue;
            }

            $payload = $fields['payload'] ?? null;
            if (false === is_string($payload)) {
                continue;
            }

            $data = json_decode($payload, true);
            if (false === is_array($data)) {
                $this->redis->xAck($this->stream, $this->group, [$id]);
                $this->redis->xDel($this->stream, [$id]);
                continue;
            }

            try {
                $items[] = EventEnvelope::fromArray($data, $id);
            } catch (Throwable) {
                $this->redis->xAck($this->stream, $this->group, [$id]);
                $this->redis->xDel($this->stream, [$id]);
            }
        }

        return $items;
    }
}
