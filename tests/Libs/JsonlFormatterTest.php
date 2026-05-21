<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Extends\JsonlFormatter;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\TestCase;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Monolog\Level;
use Monolog\LogRecord;

final class JsonlFormatterTest extends TestCase
{
    public function test_basic(): void
    {
        $formatter = new JsonlFormatter();
        $record = new LogRecord(
            datetime: new DateTimeImmutable('2026-05-20T12:00:00.123+00:00'),
            channel: 'app',
            level: Level::Warning,
            message: 'Hello world',
            context: [
                'id' => 'log-id',
                'user' => 'main',
                'backendToken' => 'secret-token',
                'request' => [
                    'id' => 'request-1',
                    'uri' => '/v1/api?apikey=secret&ok=1',
                ],
            ],
        );

        $payload = json_decode(trim($formatter->format($record)), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('log-id', $payload['id']);
        self::assertSame(
            (new DateTimeImmutable('2026-05-20T12:00:00.123+00:00'))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format(DateTimeInterface::RFC3339_EXTENDED),
            $payload['datetime'],
        );
        self::assertSame('warning', $payload['level']);
        self::assertSame(LOG_WARNING, $payload['levelno']);
        self::assertSame('app', $payload['logger']);
        self::assertSame('Hello world', $payload['message']);
        self::assertArrayNotHasKey('id', $payload['fields']);
        self::assertSame('main', $payload['fields']['user']);
        self::assertSame('request-1', $payload['fields']['request.id']);
        self::assertSame('[redacted]', $payload['fields']['backendToken']);
        self::assertSame('/v1/api?apikey=[redacted]&ok=1', $payload['fields']['request.uri']);
    }

    public function test_id_processed(): void
    {
        $formatter = new JsonlFormatter();
        $record = new LogRecord(
            datetime: new DateTimeImmutable('2026-05-20T12:00:00.123+00:00'),
            channel: 'app',
            level: Level::Info,
            message: "Event '{id}' request '{request.id}'.",
            context: [
                'id' => 'event-1',
                'request' => [
                    'id' => 'request-1',
                ],
            ],
        );

        $processed = (new LogMessageProcessor())($record);
        $payload = json_decode(trim($formatter->format($processed)), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame("Event 'event-1' request 'request-1'.", $payload['message']);
        self::assertSame('event-1', $payload['id']);
        self::assertArrayNotHasKey('id', $payload['fields']);
        self::assertSame('request-1', $payload['fields']['request.id']);
    }

    public function test_exception(): void
    {
        $formatter = new JsonlFormatter();
        $record = new LogRecord(
            datetime: new DateTimeImmutable('2026-05-20T12:00:00.123+00:00'),
            channel: 'app',
            level: Level::Error,
            message: 'Failed',
            context: [
                'exception' => [
                    'type' => 'RuntimeException',
                    'message' => 'Boom',
                    'file' => '/srv/app.php',
                    'line' => 42,
                    'trace' => [[
                        'file' => '/srv/app.php',
                        'line' => 42,
                        'function' => 'run',
                    ]],
                ],
            ],
        );

        $payload = json_decode(trim($formatter->format($record)), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('RuntimeException: Boom', $payload['exception_message']);
        self::assertSame(LOG_ERR, $payload['levelno']);
        self::assertStringContainsString('RuntimeException: Boom', $payload['exception']);
        self::assertStringContainsString('#0 run at /srv/app.php:42', $payload['exception']);
        self::assertSame([
            [
                'file' => '/srv/app.php',
                'line' => 42,
                'function' => 'run',
            ],
        ], json_decode($payload['stack'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('/srv/app.php', $payload['source']['path']);
        self::assertSame('app.php', $payload['source']['file']);
        self::assertSame(42, $payload['source']['line']);
        self::assertArrayNotHasKey('exception.trace.0.file', $payload['fields']);
        self::assertArrayNotHasKey('exception.trace.0.line', $payload['fields']);
        self::assertSame('RuntimeException', $payload['fields']['exception.type']);
    }

    public function test_event_name_and_exception_type(): void
    {
        $formatter = new JsonlFormatter();
        $exception = new \RuntimeException('Boom');
        $record = new LogRecord(
            datetime: new DateTimeImmutable('2026-05-20T12:00:00.123+00:00'),
            channel: 'app',
            level: Level::Error,
            message: "Playlist sync failed for 'main'.",
            context: [
                'event_name' => 'playlist.sync.failed',
                'user' => 'main',
                ...exception_log($exception),
            ],
        );

        $payload = json_decode(trim($formatter->format($record)), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('playlist.sync.failed', $payload['fields']['event_name']);
        self::assertSame('main', $payload['fields']['user']);
        self::assertSame(\RuntimeException::class, $payload['fields']['error.type']);
        self::assertSame(\RuntimeException::class, $payload['fields']['exception.type']);
        self::assertArrayNotHasKey('exception.trace.0.file', $payload['fields']);
        self::assertStringContainsString('RuntimeException: Boom', $payload['exception_message']);
    }

    public function test_error_trace_kept_in_stack(): void
    {
        $formatter = new JsonlFormatter();
        $record = new LogRecord(
            datetime: new DateTimeImmutable('2026-05-20T12:00:00.123+00:00'),
            channel: 'app',
            level: Level::Error,
            message: 'Failed',
            context: [
                'error' => [
                    'type' => 'RuntimeException',
                    'message' => 'Boom',
                    'trace' => [[
                        'class' => 'Worker',
                        'type' => '::',
                        'function' => 'run',
                        'file' => '/srv/worker.php',
                        'line' => 17,
                    ]],
                ],
            ],
        );

        $payload = json_decode(trim($formatter->format($record)), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([
            [
                'class' => 'Worker',
                'type' => '::',
                'function' => 'run',
                'file' => '/srv/worker.php',
                'line' => 17,
            ],
        ], json_decode($payload['stack'], true, 512, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('error.trace.0.class', $payload['fields']);
        self::assertArrayNotHasKey('error.trace.0.file', $payload['fields']);
        self::assertSame('Worker::run', $payload['source']['function']);
    }
}
