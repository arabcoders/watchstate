<?php

declare(strict_types=1);

namespace Tests\Libs\Extends;

use App\Libs\Extends\LogMessageProcessor;
use App\Libs\TestCase;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;

final class LogMessageProcessorTest extends TestCase
{
    public function test_context(): void
    {
        $processor = new LogMessageProcessor();
        $record = new LogRecord(
            datetime: new DateTimeImmutable('2026-05-19T12:00:00+00:00'),
            channel: 'app',
            level: Level::Error,
            message: "Failed to process '{user}@{backend}'. {structured.exception.message}",
            context: [
                'event_name' => 'backend.operation.failed',
                'user' => 'main',
                'backend' => 'emby_main',
                'structured' => [
                    'exception' => [
                        'message' => 'Connection timed out.',
                    ],
                ],
            ],
        );

        $processed = $processor($record);

        self::assertSame(
            "Failed to process 'main@emby_main'. Connection timed out.",
            $processed->message,
        );
        self::assertSame('backend.operation.failed', $processed->context['event_name']);
        self::assertSame('main', $processed->context['user']);
        self::assertSame('emby_main', $processed->context['backend']);
        self::assertSame('Connection timed out.', $processed->context['structured']['exception']['message']);
    }
}
