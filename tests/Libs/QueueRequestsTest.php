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
        $this->queue = new QueueRequests();
        parent::setUp();
    }

    public function test_message_init_count(): void
    {
        $this->assertCount(0, $this->queue, 'When queue empty count on object is 0');
        $this->queue->add(new Request(Method::GET, 'http://example.test/1'));
        $this->assertCount(1, $this->queue, 'When queue has 1 item count on object is 1');
    }

    public function test_message_add(): void
    {
        $obj = new Request(Method::GET, 'http://example.test/1');
        $this->queue->add($obj);
        $this->assertSame([$obj],
            $this->queue->getQueue(),
            'When message is added, it can be retrieved with getQueue() in same order it was added in'
        );
    }

    public function test_message_reset(): void
    {
        $obj = new Request(Method::GET, 'http://example.test/1');
        $this->queue->add($obj);
        $this->assertCount(1, $this->queue, 'When queue has 1 item count on object is 1');
        $this->queue->reset();
        $this->assertCount(0, $this->queue, 'When queue is reset count on object is 0');
    }

    public function test_message_iterator(): void
    {
        $objs = [
            new Request(Method::GET, 'http://example.test/1'),
            new Request(Method::POST, 'http://example.test/2'),
        ];

        foreach ($objs as $obj) {
            $this->queue->add($obj);
        }

        $this->assertCount(
            count($objs),
            $this->queue,
            'When running count on queue it should return the correct number of queued items.'
        );

        $x = 0;
        foreach ($this->queue as $obj) {
            $this->assertSame(
                $objs[$x],
                $obj,
                'When iterating over queue it should return the correct item in same order it was added in.'
            );
            $x++;
        }

        $this->queue->reset();

        $this->assertCount(0, $this->queue, 'When queue is reset count on object is 0');
    }
}
