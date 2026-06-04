<?php

declare(strict_types=1);

namespace Tests\Commands\Events;

use App\Commands\Events\DispatchCommand;
use App\Libs\Events\DataEvent;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\TestCase;
use App\Model\Events\Event;
use App\Model\Events\EventsRepository;
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

        $command = new DispatchCommand(
            $dispatcher,
            $this->repo(),
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

        $command = new DispatchCommand(
            $dispatcher,
            $this->repo(),
            $this->createStub(iCache::class),
            $logger,
        );

        $this->runEvent($command, $this->event(), Level::Notice);

        $records = $handler->getRecords();
        self::assertSame(
            "[event:550e8400-e29b-41d4-a716-446655440000] Dispatching Event: 'on_push' queued at '2026-05-17T08:25:02+00:00'.",
            $records[0]->message,
        );
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

        $command = new DispatchCommand(
            $dispatcher,
            $this->repo(),
            $this->createStub(iCache::class),
            $logger,
        );

        $event = $this->event();
        $this->runEvent($command, $event, Level::Debug, true);

        self::assertSame(
            [
                "NOTICE: Dispatching Event: 'on_push' queued at '2026-05-17T08:25:02+00:00'.",
                'DEBUG: listener debug',
                "NOTICE: Event 'on_push' was dispatched.",
            ],
            $event->logs,
        );
        self::assertSame(
            ["[event:550e8400-e29b-41d4-a716-446655440000] Dispatching Event: 'on_push' queued at '2026-05-17T08:25:02+00:00'."],
            array_map(static fn($record): string => $record->message, $handler->getRecords()),
        );
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

    private function runEvent(DispatchCommand $command, Event $event, Level $level, bool $debug = false): void
    {
        $method = new ReflectionMethod(DispatchCommand::class, 'runEvent');
        $method->invoke($command, $event, $level, $debug);
    }
}
