<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Backends\Common\Request;
use App\Libs\Enums\Http\Method;
use App\Libs\QueueRequests;
use App\Libs\TestCase;

class QueueRequestsTest extends TestCase
{
    private QueueRequests|null $queue = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = new QueueRequests();
    }

    public function test_queue_add_iterate(): void
    {
        $requests = [
            new Request(Method::GET, 'http://example.test/1'),
            new Request(Method::POST, 'http://example.test/2'),
        ];

        foreach ($requests as $request) {
            $this->queue->add($request);
        }

        $this->assertCount(
            count($requests),
            $this->queue,
            'Count reflects the number of queued requests.'
        );

        $this->assertSame(
            $requests,
            $this->queue->getQueue(),
            'getQueue exposes the queued requests in insertion order.'
        );
        $this->assertSame(
            $requests,
            iterator_to_array($this->queue),
            'Iteration yields queued requests in insertion order.'
        );
    }

    public function test_queue_reset(): void
    {
        $this->queue->add(new Request(Method::GET, 'http://example.test/1'));

        $this->queue->reset();

        $this->assertCount(0, $this->queue, 'Reset clears the queue count.');
        $this->assertSame([], $this->queue->getQueue(), 'Reset clears queued requests.');
    }
}
