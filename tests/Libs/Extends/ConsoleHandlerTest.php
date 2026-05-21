<?php

declare(strict_types=1);

namespace Tests\Libs\Extends;

use App\Libs\Extends\ConsoleHandler;
use App\Libs\TestCase;
use DateTimeImmutable;
use DateTimeInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleHandlerTest extends TestCase
{
    public function test_decorated_compact(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $handler = new ConsoleHandler($output);
        $date = new DateTimeImmutable('2026-05-21T06:54:10+03:00');

        $handler->handle(new LogRecord(
            datetime: $date,
            channel: 'logger',
            level: Level::Warning,
            message: 'SYSTEM: Playlist sync completed without any syncable playlist results.',
        ));

        $raw = $output->fetch();
        $display = preg_replace('/\x1b\[[0-9;]*m/', '', $raw);

        self::assertStringContainsString("\033[", $raw);
        self::assertIsString($display);
        self::assertSame(
            make_date($date)->format('m/d, H:i:s') . ' logger WARNING logger SYSTEM: Playlist sync completed without any syncable playlist results.' . PHP_EOL,
            $display,
        );
    }

    public function test_plain_legacy(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
        $handler = new ConsoleHandler($output);
        $date = new DateTimeImmutable('2026-05-21T06:54:10+03:00');

        $handler->handle(new LogRecord(
            datetime: $date,
            channel: 'logger',
            level: Level::Warning,
            message: 'SYSTEM: Playlist sync completed without any syncable playlist results.',
        ));

        self::assertSame(
            '[' . $date->format(DateTimeInterface::ATOM) . '] WARNING: SYSTEM: Playlist sync completed without any syncable playlist results.' . PHP_EOL,
            $output->fetch(),
        );
    }

    public function test_notice_failed_not_red(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE, true);
        $handler = new ConsoleHandler($output);

        $handler->handle(new LogRecord(
            datetime: new DateTimeImmutable('2026-05-21T07:36:40+03:00'),
            channel: 'logger',
            level: Level::Notice,
            message: "SYSTEM: Status for 'tester' Movie: '0000' added, '0003' updated and '0000' failed.",
            context: ['user' => 'tester'],
        ));

        $raw = $output->fetch();

        self::assertStringContainsString('NOTICE', $raw);
        self::assertStringContainsString("\033[35", $raw);
        self::assertStringNotContainsString("\033[31", $raw);
    }
}
