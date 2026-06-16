<?php

declare(strict_types=1);

namespace Tests\Commands\Events;

use App\Commands\Events\DispatchCommand;
use App\Libs\Container;
use App\Libs\Events\DataEvent;
use App\Libs\Events\EventQueue;
use App\Libs\Events\Queue\EventEnvelope;
use App\Libs\Events\Queue\EventTransportInterface;
use App\Libs\Events\Queue\FilesystemEventTransport;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\TestCase;
use App\Model\Events\Event;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\SimpleCache\CacheInterface as iCache;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class DispatchCommandTest extends TestCase
{
    public function test_skip_marker_for_raw_logs(): void
    {
        $handler = new TestHandler(Level::Debug);
        $logger = new Logger('test', [$handler], [new LogMessageProcessor()]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('on_push', static function (DataEvent $event) use ($logger): void {
            $event->addRawLog('listener raw output');
            $logger->notice('Listener raw output.');
        });
        $repo = $this->repo();
        $transport = $this->transport();

        $command = new DispatchCommand(
            $dispatcher,
            $repo,
            new EventQueue($transport, $repo),
            $transport,
            $this->createStub(iCache::class),
            $logger,
        );

        $this->runEvent($command, $this->event(), Level::Notice);

        self::assertSame(['Listener raw output.'], array_map(
            static fn($record): string => $record->message,
            $handler->getRecords(),
        ));
    }

    public function test_orders_marker(): void
    {
        $handler = new TestHandler(Level::Debug);
        $logger = new Logger('test', [$handler], [new LogMessageProcessor()]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('on_push', static function (DataEvent $event) use ($logger): void {
            $event->addLog(Level::Notice, 'listener visible');
            $logger->notice('Listener visible.');
        });
        $repo = $this->repo();
        $transport = $this->transport();

        $command = new DispatchCommand(
            $dispatcher,
            $repo,
            new EventQueue($transport, $repo),
            $transport,
            $this->createStub(iCache::class),
            $logger,
        );

        $this->runEvent($command, $this->event(), Level::Notice);

        $records = $handler->getRecords();
        self::assertSame(
            "Dispatching Event: 'on_push' queued at '2026-05-17T08:25:02+00:00'.",
            $records[0]->message,
        );
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $records[0]->context['event']['id'] ?? '');
        self::assertSame('Listener visible.', $records[1]->message);
    }

    public function test_allow_debug_marker(): void
    {
        $handler = new TestHandler(Level::Debug);
        $logger = new Logger('test', [$handler], [new LogMessageProcessor()]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('on_push', static function (DataEvent $event): void {
            $event->addLog(Level::Debug, 'listener debug');
        });
        $repo = $this->repo();
        $transport = $this->transport();

        $command = new DispatchCommand(
            $dispatcher,
            $repo,
            new EventQueue($transport, $repo),
            $transport,
            $this->createStub(iCache::class),
            $logger,
        );

        $event = $this->event();
        $this->runEvent($command, $event, Level::Debug, true);

        self::assertCount(3, $event->logs);
        self::assertStringContainsString('Dispatching Event:', $event->logs[0]);
        self::assertStringContainsString('"message":"listener debug"', $event->logs[1]);
        self::assertStringContainsString('was dispatched', $event->logs[2]);
        self::assertSame(
            ["Dispatching Event: 'on_push' queued at '2026-05-17T08:25:02+00:00'."],
            array_map(static fn($record): string => $record->message, $handler->getRecords()),
        );
    }

    public function test_drain_transport(): void
    {
        $this->initTempDir();
        Container::init();

        $logger = new Logger('test');
        $repo = new EventsRepository($this->createDb($logger)->getDBLayer());
        $transport = new FilesystemEventTransport(self::$tmpPath . '/queue/events');
        $queue = new EventQueue($transport, $repo);

        $transport->enqueue(EventEnvelope::create(
            'on_push',
            ['ok' => true],
            [
                EventsTable::COLUMN_REFERENCE => 'push://1',
            ],
        ));

        $command = new DispatchCommand(
            new EventDispatcher(),
            $repo,
            $queue,
            $transport,
            $this->createStub(iCache::class),
            $logger,
        );

        $method = new ReflectionMethod(DispatchCommand::class, 'drainTransport');
        $method->invoke($command, 10);

        $events = $repo->findAll([EventsTable::COLUMN_EVENT => 'on_push']);

        self::assertCount(1, $events);
        self::assertSame(['ok' => true], $events[0]->event_data);
        self::assertSame('push://1', $events[0]->reference);
        self::assertSame(0, $transport->count());
    }

    private function event(): Event
    {
        $event = new Event([]);
        $event->id = '550e8400-e29b-41d4-a716-446655440000';
        $event->event = 'on_push';
        $event->created_at = make_date('2026-05-17T08:25:02+00:00');

        return $event;
    }

    private function repo(): EventsRepository
    {
        $repo = $this->createMock(EventsRepository::class);
        $repo->expects(self::exactly(2))->method('save')->willReturn('event-id');

        return $repo;
    }

    private function transport(): EventTransportInterface
    {
        return $this->createStub(EventTransportInterface::class);
    }

    private function runEvent(DispatchCommand $command, Event $event, Level $level, bool $debug = false): void
    {
        $method = new ReflectionMethod(DispatchCommand::class, 'runEvent');
        $method->invoke($command, $event, $level, $debug);
    }
}
