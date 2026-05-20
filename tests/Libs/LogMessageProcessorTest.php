<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Extends\LogMessageProcessor;
use App\Libs\TestCase;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;

final class LogMessageProcessorTest extends TestCase
{
    public function test_context_keep(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable('2026-05-20T12:00:00+00:00'),
            channel: 'test',
            level: Level::Notice,
            message: 'event {id} request {request.id}',
            context: [
                'id' => 'event-1',
                'request' => [
                    'id' => 'request-1',
                ],
            ],
        );

        $processed = (new LogMessageProcessor())($record);

        self::assertSame('event event-1 request request-1', $processed->message);
        self::assertSame('event-1', $processed->context['id']);
        self::assertSame('request-1', $processed->context['request']['id']);
    }
}
