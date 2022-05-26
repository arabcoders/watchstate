<?php

declare(strict_types=1);

namespace App\Libs;

use Countable;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class QueueRequests implements Countable
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
}
