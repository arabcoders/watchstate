<?php

declare(strict_types=1);

namespace Tests\Libs\Extends;

use App\Libs\Config;
use App\Libs\Extends\ConsoleOutput;
use App\Libs\Extends\StreamLogHandler;
use App\Libs\Stream;
use App\Libs\TestCase;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;

final class StreamLogHandlerTest extends TestCase
{
    public function test_console_output_jsonl(): void
    {
        Config::save('console.output', 'text');

        $output = new ConsoleOutput();
        $output->setJsonl(true);

        $stream = Stream::create();
        $handler = new StreamLogHandler($stream, $output);
        $handler->handle(new LogRecord(
            datetime: new DateTimeImmutable('2026-05-20T12:00:00.123+00:00'),
            channel: 'app',
            level: Level::Warning,
            message: 'Hello world',
        ));

        $payload = json_decode(trim((string) $stream), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('warning', $payload['level']);
        self::assertSame('app', $payload['logger']);
        self::assertSame('Hello world', $payload['message']);
    }
}
