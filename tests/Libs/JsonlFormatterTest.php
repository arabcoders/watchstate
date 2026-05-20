<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Extends\JsonlFormatter;
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
        self::assertSame('main', $payload['fields']['user']);
        self::assertSame('[redacted]', $payload['fields']['backendToken']);
        self::assertSame('/v1/api?apikey=[redacted]&ok=1', $payload['fields']['request.uri']);
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
                    'kind' => 'RuntimeException',
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
        self::assertSame('/srv/app.php', $payload['source']['path']);
        self::assertSame('app.php', $payload['source']['file']);
        self::assertSame(42, $payload['source']['line']);
    }
}
