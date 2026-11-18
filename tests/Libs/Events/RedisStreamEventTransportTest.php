<?php

declare(strict_types=1);

namespace Tests\Libs\Events;

use App\Libs\Events\Queue\EventEnvelope;
use App\Libs\Events\Queue\EventEnvelopeState;
use App\Libs\Events\Queue\RedisStreamEventTransport;
use App\Libs\TestCase;
use Redis;
use RuntimeException;

final class RedisStreamEventTransportTest extends TestCase
{
    public function test_inspect(): void
    {
        $redis = $this->createMock(Redis::class);
        $pending = EventEnvelope::create('on_webhook');
        $processing = EventEnvelope::create('on_push');
        $redis
            ->expects($this->once())
            ->method('xRange')
            ->willReturn([
                '1-0' => ['payload' => json_encode($pending->toArray(), flags: JSON_THROW_ON_ERROR)],
                '2-0' => ['payload' => json_encode($processing->toArray(), flags: JSON_THROW_ON_ERROR)],
            ]);
        $redis
            ->expects($this->exactly(2))
            ->method('rawCommand')
            ->willReturnOnConsecutiveCalls(
                [1, '2-0', '2-0', [['consumer', 1]]],
                [['2-0', 'consumer', 1, 1]],
            );
        $redis->expects($this->never())->method('xAck');
        $redis->expects($this->never())->method('xDel');

        $items = $this->transport($redis)->inspect();

        self::assertCount(2, $items);
        self::assertSame(EventEnvelopeState::PENDING, $items[0]->state);
        self::assertSame(EventEnvelopeState::PROCESSING, $items[1]->state);
    }

    public function test_inspect_error(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('xRange')->willThrowException(new RuntimeException('Redis unavailable.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis unavailable.');

        $this->transport($redis)->inspect();
    }

    public function test_count_pending(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('xLen')->with('events')->willReturn(100_000);
        $redis
            ->expects(self::once())
            ->method('rawCommand')
            ->with('XPENDING', 'events', 'watchstate')
            ->willReturn([2, '1-0', '2-0', [['consumer', 2]]]);
        $redis->expects(self::never())->method('xRange');

        self::assertSame(99_998, $this->transport($redis)->inspectCount(EventEnvelopeState::PENDING));
    }

    public function test_count_pending_no_group(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('xLen')->with('events')->willReturn(2);
        $redis
            ->expects(self::once())
            ->method('rawCommand')
            ->with('XPENDING', 'events', 'watchstate')
            ->willThrowException(new RuntimeException('NOGROUP No such consumer group.'));
        $redis->expects(self::never())->method('xRange');

        self::assertSame(2, $this->transport($redis)->inspectCount(EventEnvelopeState::PENDING));
    }

    private function transport(Redis $redis): RedisStreamEventTransport
    {
        return new RedisStreamEventTransport($redis, 'events', 'watchstate', 'test');
    }
}
