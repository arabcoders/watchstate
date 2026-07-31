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

    /**
     * @inheritdoc
     */
    public function inspect(
        int $limit = 100,
        int $offset = 0,
        ?EventEnvelopeState $state = null,
        ?string $filter = null,
    ): array {
        if (null === $state && '' === trim((string) $filter)) {
            $records = $this->readInspectable(max(1, $offset + $limit));
            if ([] === $records) {
                return [];
            }

            $ids = array_keys($records);
            $items = $this->parseStreamEntries(
                $records,
                false,
                $this->processingIds((string) reset($ids), (string) end($ids)),
            );

            return array_slice($items, max(0, $offset), max(1, $limit));
        }

        return array_slice($this->inspectable($state, $filter), max(0, $offset), max(1, $limit));
    }

    /**
     * @inheritdoc
     */
    public function inspectCount(?EventEnvelopeState $state = null, ?string $filter = null): int
    {
        if (null === $state && '' === trim((string) $filter)) {
            return $this->count();
        }

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
        $records = $this->readInspectable();
        $items = $this->parseStreamEntries($records, false, $this->processingIds());
        $filter = strtolower(trim((string) $filter));

        return array_values(array_filter($items, static function (EventEnvelope $envelope) use ($state, $filter): bool {
            if (null !== $state && $envelope->state !== $state) {
                return false;
            }

            return '' === $filter || str_contains(strtolower($envelope->id . ' ' . $envelope->event), $filter);
        }));
    }

    /**
     * @return array<string, true>
     */
    private function processingIds(string $start = '-', string $end = '+'): array
    {
        try {
            $summary = $this->redis->rawCommand('XPENDING', $this->stream, $this->group);
            $count = is_array($summary) && is_numeric($summary[0] ?? null) ? (int) $summary[0] : 0;
            if ($count < 1) {
                return [];
            }

            $records = $this->redis->rawCommand('XPENDING', $this->stream, $this->group, $start, $end, (string) $count);
        } catch (Throwable $e) {
            if (true === str_contains($e->getMessage(), 'NOGROUP')) {
                return [];
            }

            throw $e;
        }

        if (false === is_array($records)) {
            return [];
        }

        $ids = [];
        foreach ($records as $record) {
            if (false === is_array($record) || false === is_string($record[0] ?? null)) {
                continue;
            }

            $ids[$record[0]] = true;
        }

        return $ids;
    }

    /**
     * @return array<mixed>
     */
    private function readInspectable(?int $count = null): array
    {
        $records = null === $count
            ? $this->redis->xRange($this->stream, '-', '+')
            : $this->redis->xRange($this->stream, '-', '+', $count);

        if (false === is_array($records)) {
            throw new RuntimeException(r("Unable to inspect Redis stream '{stream}'.", ['stream' => $this->stream]));
        }

        return $records;
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
     * @param array<string, true> $processingIds
     * @return array<EventEnvelope>
     */
    private function parseStreamEntries(array $entries, bool $cleanup = true, array $processingIds = []): array
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
                if (true === $cleanup) {
                    $this->redis->xAck($this->stream, $this->group, [$id]);
                    $this->redis->xDel($this->stream, [$id]);
                }
                continue;
            }

            try {
                $state = true === ($processingIds[$id] ?? false)
                    ? EventEnvelopeState::PROCESSING
                    : EventEnvelopeState::PENDING;
                $items[] = EventEnvelope::fromArray($data, $id)->withState($state);
            } catch (Throwable) {
                if (true === $cleanup) {
                    $this->redis->xAck($this->stream, $this->group, [$id]);
                    $this->redis->xDel($this->stream, [$id]);
                }
            }
        }

        return $items;
    }
}
