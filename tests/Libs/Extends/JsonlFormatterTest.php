<?php

declare(strict_types=1);

namespace Tests\Libs\Extends;

use App\Libs\Extends\JsonlFormatter;
use App\Libs\Extends\JsonlStreamHandler;
use App\Libs\TestCase;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

final class JsonlFormatterTest extends TestCase
{
    public function test_basic(): void
    {
        $formatter = new JsonlFormatter();
        $record = new LogRecord(
            datetime: new DateTimeImmutable('2026-05-19T12:00:00.123+00:00'),
            channel: 'app',
            level: Level::Warning,
            message: 'Hello world',
            context: [
                'id' => 'log-1',
                'event_name' => 'state.test.completed',
                'subsystem' => 'state',
                'operation' => 'test_jsonl',
                'outcome' => 'completed',
                'user' => 'main',
                'backend' => 'emby_main',
                'item' => [
                    'id' => 123,
                ],
                'token' => 'secret',
            ],
        );

        $line = $formatter->format($record);
        $payload = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringEndsWith(PHP_EOL, $line);
        self::assertSame('log-1', $payload['id']);
        self::assertSame('2026-05-19T12:00:00.123+00:00', $payload['datetime']);
        self::assertSame('warning', $payload['level']);
        self::assertSame(LOG_WARNING, $payload['levelno']);
        self::assertSame('app', $payload['logger']);
        self::assertSame('Hello world', $payload['message']);
        self::assertSame('state.test.completed', $payload['event_name']);
        self::assertSame('app', $payload['source']['module']);
        self::assertSame('state', $payload['fields']['subsystem']);
        self::assertSame('test_jsonl', $payload['fields']['operation']);
        self::assertSame('completed', $payload['fields']['outcome']);
        self::assertSame('main', $payload['fields']['user']);
        self::assertSame('emby_main', $payload['fields']['backend']);
        self::assertSame(123, $payload['fields']['item.id']);
        self::assertArrayNotHasKey('event_name', $payload['fields']);
        self::assertArrayNotHasKey('token', $payload['fields']);
    }

    public function test_exception(): void
    {
        $formatter = new JsonlFormatter();
        $record = new LogRecord(
            datetime: new DateTimeImmutable('2026-05-19T12:00:00.123+00:00'),
            channel: 'app',
            level: Level::Error,
            message: 'Failed',
            context: [
                'trace' => [
                    [
                        'file' => '/srv/app.php',
                        'line' => 42,
                        'class' => 'App\\Test',
                        'type' => '::',
                        'function' => 'run',
                    ],
                ],
                'structured' => [
                    'exception' => [
                        'type' => 'RuntimeException',
                        'message' => 'Boom',
                        'file' => '/srv/app.php',
                        'line' => 42,
                    ],
                    'request' => [
                        'path' => '/v1/api/test',
                    ],
                ],
            ],
        );

        $payload = json_decode(trim($formatter->format($record)), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(LOG_ERR, $payload['levelno']);
        self::assertSame('RuntimeException', $payload['exception']['type']);
        self::assertSame('Boom', $payload['exception']['message']);
        self::assertSame('/srv/app.php', $payload['exception']['file']);
        self::assertSame(42, $payload['exception']['line']);
        self::assertSame('/srv/app.php', $payload['exception']['trace'][0]['file']);
        self::assertSame('/srv/app.php', $payload['source']['path']);
        self::assertSame('app.php', $payload['source']['file']);
        self::assertSame(42, $payload['source']['line']);
        self::assertSame('App\\Test::run', $payload['source']['function']);
        self::assertSame('/v1/api/test', $payload['fields']['structured.request.path']);
        self::assertArrayNotHasKey('structured.exception.type', $payload['fields']);
        self::assertArrayNotHasKey('trace', $payload['fields']);
    }

    public function test_values(): void
    {
        $formatter = new JsonlFormatter();
        $payload = json_decode(
            trim($formatter->formatValues(
                channel: 'cli',
                level: Level::Info,
                message: 'Command output',
                context: [
                    'cli' => [
                        'stream' => 'stdout',
                    ],
                ],
                datetime: new DateTimeImmutable('2026-05-19T12:00:00.123+00:00'),
            )),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('cli', $payload['logger']);
        self::assertSame('Command output', $payload['message']);
        self::assertSame('stdout', $payload['fields']['cli.stream']);
    }

    public function test_handler(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $logger = new Logger('app');
        $logger->pushHandler(new JsonlStreamHandler($stream));
        $logger->warning('Handled', ['id' => 'handled-1']);

        rewind($stream);
        $payload = json_decode(trim((string) stream_get_contents($stream)), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('handled-1', $payload['id']);
        self::assertSame('warning', $payload['level']);
        self::assertSame('Handled', $payload['message']);
    }
}
