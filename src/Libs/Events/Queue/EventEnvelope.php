<?php

declare(strict_types=1);

namespace App\Libs\Events\Queue;

use DateTimeInterface;
use InvalidArgumentException;

final class EventEnvelope
{
    /**
     * @param array<string, mixed> $data Event payload.
     * @param array<string, mixed> $opts Event queue options.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $event,
        public readonly array $data,
        public readonly array $opts,
        public readonly string $createdAt,
        public readonly mixed $ack = null,
    ) {}

    /**
     * Create an event envelope for transport enqueueing.
     *
     * @param array<string, mixed> $data Event payload.
     * @param array<string, mixed> $opts Event queue options.
     */
    public static function create(string $event, array $data = [], array $opts = []): self
    {
        return new self(
            id: generate_uuid(),
            event: $event,
            data: $data,
            opts: $opts,
            createdAt: (string) make_date(),
        );
    }

    /**
     * Recreate an event envelope from transport payload data.
     *
     * @param array<string, mixed> $payload Transport payload.
     */
    public static function fromArray(array $payload, mixed $ack = null): self
    {
        $id = (string) ($payload['id'] ?? '');
        $event = (string) ($payload['event'] ?? '');

        if ('' === trim($id)) {
            throw new InvalidArgumentException('Queue envelope id is missing.');
        }

        if ('' === trim($event)) {
            throw new InvalidArgumentException('Queue envelope event name is missing.');
        }

        $data = $payload['data'] ?? [];
        $opts = $payload['opts'] ?? [];

        return new self(
            id: $id,
            event: $event,
            data: is_array($data) ? $data : [],
            opts: is_array($opts) ? $opts : [],
            createdAt: (string) ($payload['created_at'] ?? make_date()->format(DateTimeInterface::ATOM)),
            ack: $ack,
        );
    }

    /**
     * Return this envelope with transport acknowledgement metadata.
     */
    public function withAck(mixed $ack): self
    {
        return new self(
            id: $this->id,
            event: $this->event,
            data: $this->data,
            opts: $this->opts,
            createdAt: $this->createdAt,
            ack: $ack,
        );
    }

    /**
     * Convert envelope to transport payload data.
     *
     * @return array{id:string,event:string,data:array<string,mixed>,opts:array<string,mixed>,created_at:string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'data' => $this->data,
            'opts' => $this->opts,
            'created_at' => $this->createdAt,
        ];
    }
}
