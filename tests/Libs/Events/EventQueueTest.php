<?php

declare(strict_types=1);

namespace Tests\Libs\Events;

use App\Libs\Events\DataEvent;
use App\Libs\Events\EventQueue;
use App\Libs\Events\Queue\EventEnvelope;
use App\Libs\Events\Queue\EventTransportInterface;
use App\Libs\Options;
use App\Libs\TestCase;
use App\Model\Events\Event as EventInfo;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use PDOException;

final class EventQueueTest extends TestCase
{
    public function test_cache_only(): void
    {
        $transport = $this->createMock(EventTransportInterface::class);
        $repo = $this
            ->getMockBuilder(EventsRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getObject', 'findByReference', 'remove', 'save'])
            ->getMock();

        $repo->expects($this->never())->method('getObject');
        $repo->expects($this->never())->method('findByReference');
        $repo->expects($this->never())->method('remove');
        $repo->expects($this->never())->method('save');

        $transport
            ->expects($this->once())
            ->method('enqueue')
            ->with($this->callback(static function (EventEnvelope $event): bool {
                return (
                    'on_request' === $event->event
                    && ['ok' => true] === $event->data
                    && 'request://1' === $event->opts[EventsTable::COLUMN_REFERENCE]
                    && true === $event->opts['cached']
                    && 'alice' === $event->opts[Options::CONTEXT_USER]
                    && false === array_key_exists(Options::CACHE_ONLY, $event->opts)
                    && false === array_key_exists(Options::CACHE_TTL, $event->opts)
                    && false === array_key_exists(EventsRepository::class, $event->opts)
                );
            }))
            ->willReturnCallback(static fn(EventEnvelope $event): EventEnvelope => $event);

        $queued = new EventQueue($transport, $repo)->queue(
            'on_request',
            ['ok' => true],
            [
                'unique' => true,
                EventsTable::COLUMN_REFERENCE => 'request://1',
                Options::CACHE_ONLY => true,
                Options::CACHE_TTL => new \DateInterval('PT6H'),
                Options::CONTEXT_USER => 'alice',
            ],
        );

        self::assertSame('on_request', $queued->event);
        self::assertSame(EventStatus::PENDING, $queued->status);
        self::assertSame('request://1', $queued->reference);
        self::assertSame(['ok' => true], $queued->event_data);
        self::assertSame(DataEvent::class, $queued->options['class']);
        self::assertSame('alice', $queued->options[Options::CONTEXT_USER]);
    }

    public function test_lock_fallback(): void
    {
        $modes = ['find', 'remove', 'save'];
        $queuedEvents = [];
        $transport = $this->createMock(EventTransportInterface::class);
        $transport
            ->expects($this->exactly(3))
            ->method('enqueue')
            ->willReturnCallback(static function (EventEnvelope $event) use (&$queuedEvents): EventEnvelope {
                $queuedEvents[] = $event;

                return $event;
            });

        foreach ($modes as $mode) {
            $reference = 'ref://' . $mode;
            $item = new EventInfo([], true);
            $repo = $this
                ->getMockBuilder(EventsRepository::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getObject', 'findByReference', 'remove', 'save'])
                ->getMock();

            $repo
                ->expects($this->once())
                ->method('getObject')
                ->with([])
                ->willReturn($item);

            $optsMatcher = $this->callback(static function (array $opts) use ($reference): bool {
                return (
                    true === $opts[Options::FAIL_FAST_ON_LOCK]
                    && 'alice' === $opts[Options::CONTEXT_USER]
                    && $reference === $opts[EventsTable::COLUMN_REFERENCE]
                    && true === $opts['unique']
                );
            });

            if ('find' === $mode) {
                $repo
                    ->expects($this->once())
                    ->method('findByReference')
                    ->with($reference, [], $optsMatcher)
                    ->willThrowException(new PDOException('database is locked'));

                $repo->expects($this->never())->method('remove');
                $repo->expects($this->never())->method('save');
            }

            if ('remove' === $mode) {
                $existing = new EventInfo([], true);

                $repo
                    ->expects($this->once())
                    ->method('findByReference')
                    ->with($reference, [], $optsMatcher)
                    ->willReturn($existing);

                $repo
                    ->expects($this->once())
                    ->method('remove')
                    ->with($existing, $optsMatcher)
                    ->willThrowException(new PDOException('database is locked'));

                $repo->expects($this->never())->method('save');
            }

            if ('save' === $mode) {
                $repo
                    ->expects($this->once())
                    ->method('findByReference')
                    ->with($reference, [], $optsMatcher)
                    ->willReturn(null);

                $repo->expects($this->never())->method('remove');
                $repo
                    ->expects($this->once())
                    ->method('save')
                    ->with(
                        $this->callback(static function (EventInfo $event) use ($reference): bool {
                            return (
                                'process_request' === $event->event
                                && EventStatus::PENDING === $event->status
                                && $reference === $event->reference
                                && ['ok' => true] === $event->event_data
                                && DataEvent::class === $event->options['class']
                                && 'alice' === $event->options[Options::CONTEXT_USER]
                            );
                        }),
                        $optsMatcher,
                    )
                    ->willThrowException(new PDOException('database is locked'));
            }

            $queued = new EventQueue($transport, $repo)->queue(
                'process_request',
                ['ok' => true],
                [
                    'unique' => true,
                    EventsTable::COLUMN_REFERENCE => $reference,
                    Options::FAIL_FAST_ON_LOCK => true,
                    Options::CONTEXT_USER => 'alice',
                ],
            );

            self::assertSame('process_request', $queued->event);
            self::assertSame(EventStatus::PENDING, $queued->status);
            self::assertSame($reference, $queued->reference);
            self::assertSame(['ok' => true], $queued->event_data);
            self::assertSame(DataEvent::class, $queued->options['class']);
            self::assertSame('alice', $queued->options[Options::CONTEXT_USER]);
            self::assertTrue($queued->options[Options::FAIL_FAST_ON_LOCK]);
        }

        self::assertCount(3, $queuedEvents);

        foreach ($queuedEvents as $index => $event) {
            self::assertSame('process_request', $event->event);
            self::assertSame(['ok' => true], $event->data);
            self::assertTrue($event->opts['cached']);
            self::assertTrue($event->opts[Options::FAIL_FAST_ON_LOCK]);
            self::assertSame('alice', $event->opts[Options::CONTEXT_USER]);
            self::assertSame('ref://' . $modes[$index], $event->opts[EventsTable::COLUMN_REFERENCE]);
            self::assertArrayNotHasKey(EventsRepository::class, $event->opts);
        }
    }
}
