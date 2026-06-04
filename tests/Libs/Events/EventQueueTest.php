<?php

declare(strict_types=1);

namespace Tests\Libs\Events;

use App\Libs\Events\DataEvent;
use App\Libs\Events\EventQueue;
use App\Libs\Options;
use App\Libs\TestCase;
use App\Model\Events\Event as EventInfo;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use PDOException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class EventQueueTest extends TestCase
{
    public function test_cache_only(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $repo = $this
            ->getMockBuilder(EventsRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getObject', 'findByReference', 'remove', 'save'])
            ->getMock();

        $repo->expects($this->never())->method('getObject');
        $repo->expects($this->never())->method('findByReference');
        $repo->expects($this->never())->method('remove');
        $repo->expects($this->never())->method('save');

        $queued = new EventQueue($cache, $repo)->queue(
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

        $events = $cache->get('events', []);

        self::assertCount(1, $events);
        self::assertSame('on_request', $events[0]['event']);
        self::assertSame(['ok' => true], $events[0]['data']);
        self::assertSame('request://1', $events[0]['opts'][EventsTable::COLUMN_REFERENCE]);
        self::assertTrue($events[0]['opts']['cached']);
        self::assertArrayNotHasKey(Options::CACHE_ONLY, $events[0]['opts']);
        self::assertArrayNotHasKey(Options::CACHE_TTL, $events[0]['opts']);
        self::assertArrayNotHasKey(EventsRepository::class, $events[0]['opts']);
    }

    public function test_lock_fallback(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $modes = ['find', 'remove', 'save'];

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

            $queued = new EventQueue($cache, $repo)->queue(
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

        $events = $cache->get('events', []);

        self::assertCount(3, $events);

        foreach ($events as $index => $event) {
            self::assertSame('process_request', $event['event']);
            self::assertSame(['ok' => true], $event['data']);
            self::assertTrue($event['opts']['cached']);
            self::assertTrue($event['opts'][Options::FAIL_FAST_ON_LOCK]);
            self::assertSame('alice', $event['opts'][Options::CONTEXT_USER]);
            self::assertSame('ref://' . $modes[$index], $event['opts'][EventsTable::COLUMN_REFERENCE]);
            self::assertArrayNotHasKey(EventsRepository::class, $event['opts']);
        }
    }
}
