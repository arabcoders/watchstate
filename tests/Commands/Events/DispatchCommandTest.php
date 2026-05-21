<?php

declare(strict_types=1);

namespace Tests\Commands\Events;

use App\Commands\Events\DispatchCommand;
use App\Libs\Events\DataEvent;
use App\Libs\Extends\JsonlFormatter;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\TestCase;
use App\Model\Events\Event;
use App\Model\Events\EventsRepository;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class DispatchCommandTest extends TestCase
{
    public function test_visible_logs(): void
    {
        $method = new ReflectionMethod(DispatchCommand::class, 'isVisible');
        $command = $this->makeCommand();
        $jsonlNotice = (new JsonlFormatter())->formatValues(
            channel: 'event',
            level: Level::Notice,
            message: 'JSONL visible',
            context: ['event_id' => '550e8400-e29b-41d4-a716-446655440000'],
        );
        $jsonlInfo = (new JsonlFormatter())->formatValues(
            channel: 'event',
            level: Level::Info,
            message: 'JSONL hidden',
            context: ['event_id' => '550e8400-e29b-41d4-a716-446655440000'],
        );

        self::assertTrue($method->invoke(
            $command,
            [
                'INFO: hidden',
                'NOTICE: visible',
                '[2026-04-27T10:17:56+03:00] WARNING: visible',
                $jsonlNotice,
                'plain text',
            ],
            Level::Notice,
        ));

        self::assertTrue($method->invoke(
            $command,
            [
                'INFO: visible',
                'NOTICE: visible',
                'WARNING: visible',
                $jsonlInfo,
                'event.DEBUG: hidden',
            ],
            Level::Info,
        ));

        self::assertFalse($method->invoke($command, ['INFO: hidden'], Level::Warning));
    }

    public function test_orders_marker(): void
    {
        $handler = new TestHandler(Level::Debug);
        $logger = new Logger('test', [$handler], [new LogMessageProcessor()]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('on_push', static function (DataEvent $event) use ($logger): void {
            $event->addLog('NOTICE: listener visible');
            $logger->notice('Listener visible.');
        });

        $repo = $this->createMock(EventsRepository::class);
        $repo->expects(self::exactly(2))->method('save')->willReturn('event-id');

        $event = new Event([]);
        $event->id = '550e8400-e29b-41d4-a716-446655440000';
        $event->event = 'on_push';
        $event->created_at = make_date('2026-05-17T08:25:02+00:00');

        $method = new ReflectionMethod(DispatchCommand::class, 'runEvent');
        $method->invoke(
            new DispatchCommand(
                $dispatcher,
                $repo,
                $this->createStub(iCache::class),
                $logger,
            ),
            $event,
            Level::Notice,
        );

        $records = $handler->getRecords();
        self::assertSame(
            "Dispatching queued event 'on_push' from 2026-05-17T08:25:02+00:00.",
            $records[0]->message,
        );
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $records[0]->context['event_id']);
        self::assertSame('on_push', $records[0]->context['queued_event']);
        self::assertSame('events.dispatch.started', $records[0]->context['event_name']);
        self::assertSame('Listener visible.', $records[1]->message);
    }

    private function makeCommand(): DispatchCommand
    {
        return new DispatchCommand(
            new EventDispatcher(),
            $this->createStub(EventsRepository::class),
            $this->createStub(iCache::class),
            $this->createStub(iLogger::class),
        );
    }
}
