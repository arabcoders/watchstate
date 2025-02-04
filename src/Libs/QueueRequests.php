<?php

declare(strict_types=1);

namespace App\Libs;

use Countable;
use Iterator;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Class QueueRequests
 *
 * This class holds a queue of requests to be processed.
 *
 * @implements Iterator, Countable
 */
final class QueueRequests implements Countable, Iterator
{
    /**
     * @var array<ResponseInterface> Queued requests.
     */
    private array $queue = [];

    /**
     * Adds a response to the queue.
     *
     * @param ResponseInterface $request The response to be added to the queue.
     *
     * @return self Returns the current instance.
     */
    public function add(ResponseInterface $request): self
    {
        $this->queue[] = $request;

        return $this;
    }

    /**
     * Gets the queue.
     *
     * @return array<ResponseInterface> The current queue.
     */
    public function getQueue(): array
    {
        return $this->queue;
    }

    /**
     * Resets the queue.
     *
     * @return self Returns an instance of the current class.
     */
    public function reset(): self
    {
        $this->queue = [];

        return $this;
    }

    /**
     * Returns the number of elements in the queue.
     *
     * @return int The number of elements in the queue.
     */
    public function count(): int
    {
        return count($this->queue);
    }

    /**
     * Returns the current element in the queue.
     *
     * @return ResponseInterface|bool The current element in the queue, or false if the queue is empty.
     */
    public function current(): ResponseInterface|bool
    {
        return current($this->queue);
    }

    /**
     * Moves the internal pointer of the queue to the next element.
     */
    public function next(): void
    {
        next($this->queue);
    }

    /**
     * Returns the key of the current element in the queue.
     *
     * @return string|int|null The key of the current element in the queue.
     */
    public function key(): string|int|null
    {
        return key($this->queue);
    }

    /**
     * Checks if the current position in the queue is valid.
     *
     * @return bool Returns true if the current position is valid, false otherwise.
     */
    public function valid(): bool
    {
        return null !== key($this->queue);
    }

    /**
     * Rewinds the position in the queue to the first element.
     */
    public function rewind(): void
    {
        reset($this->queue);
    }
}
