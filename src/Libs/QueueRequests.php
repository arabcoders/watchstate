<?php

declare(strict_types=1);

namespace App\Libs;

use Countable;
use Iterator;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class QueueRequests implements Countable, Iterator
{
    /**
     * @var array<ResponseInterface> Queued Requests.
     */
    private array $queue = [];

    public function add(ResponseInterface $request): self
    {
        $this->queue[] = $request;

        return $this;
    }

    /**
     * @return array<ResponseInterface>
     */
    public function getQueue(): array
    {
        return $this->queue;
    }

    public function reset(): self
    {
        $this->queue = [];

        return $this;
    }

    public function count(): int
    {
        return count($this->queue);
    }

    public function current(): ResponseInterface|bool
    {
        return current($this->queue);
    }

    public function next(): void
    {
        next($this->queue);
    }

    public function key(): string|int|null
    {
        return key($this->queue);
    }

    public function valid(): bool
    {
        return null !== key($this->queue);
    }

    public function rewind(): void
    {
        reset($this->queue);
    }
}
