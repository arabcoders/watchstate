<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\QueueRequests;
use App\Libs\TestCase;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

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
        self::assertCount(0, $this->queue);
        $this->queue->add(new JsonMockResponse(['test' => 'foo']));
        self::assertCount(1, $this->queue);
    }

    public function test_message_add(): void
    {
        $obj = new JsonMockResponse(['test' => 'foo']);
        $this->queue->add($obj);
        self::assertSame([$obj], $this->queue->getQueue());
    }

    public function test_message_reset(): void
    {
        $obj = new JsonMockResponse(['test' => 'foo']);
        $this->queue->add($obj);
        self::assertCount(1, $this->queue);
        $this->queue->reset();
        self::assertCount(0, $this->queue);
    }

    public function test_message_iterator(): void
    {
        $objs = [
            new JsonMockResponse(['test' => 'foo']),
            new MockResponse(['test' => 'foo2']),
        ];

        foreach ($objs as $obj) {
            $this->queue->add($obj);
        }

        self::assertCount(count($objs), $this->queue);

        $x = 0;
        foreach ($this->queue as $obj) {
            $this->assertSame($objs[$x], $obj);
            $x++;
        }

        $this->queue->reset();

        self::assertCount(0, $this->queue);
    }
}
