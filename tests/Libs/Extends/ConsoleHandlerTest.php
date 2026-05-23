<?php

declare(strict_types=1);

namespace Tests\Libs\Extends;

use App\Libs\Extends\ConsoleHandler;
use App\Libs\TestCase;
use DateTimeImmutable;
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
            message: 'Playlist sync completed without syncable results across 2 users.',
        ));

        $raw = $output->fetch();
        $display = preg_replace('/\x1b\[[0-9;]*m/', '', $raw);

        self::assertStringContainsString("\033[", $raw);
        self::assertIsString($display);
        self::assertSame(
            make_date($date)->format('m/d, H:i:s') . ' logger WARNING logger Playlist sync completed without syncable results across 2 users.' . PHP_EOL,
            $display,
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
            message: "Playlist summary for 'tester@office_plex': 0 playlists, 3 items, added 0, updated 3, removed 0.",
            context: ['user' => 'tester'],
        ));

        $raw = $output->fetch();

        self::assertStringContainsString('NOTICE', $raw);
        self::assertStringContainsString("\033[35", $raw);
        self::assertStringNotContainsString("\033[31", $raw);
    }

    public function test_event_name_host_fallback(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $handler = new ConsoleHandler($output);
        $date = new DateTimeImmutable('2026-05-21T07:36:40+03:00');

        $handler->handle(new LogRecord(
            datetime: $date,
            channel: 'logger',
            level: Level::Warning,
            message: "Skipping playlist backend 'main@office_plex': type 'bad' is unsupported.",
            context: [
                'event_name' => 'playlist.backend.skipped',
                'backend' => [
                    'name' => 'office_plex',
                ],
            ],
        ));

        $display = preg_replace('/\x1b\[[0-9;]*m/', '', $output->fetch());

        self::assertIsString($display);
        self::assertSame(
            make_date($date)->format('m/d, H:i:s') . " office_plex WARNING logger Skipping playlist backend 'main@office_plex': type 'bad' is unsupported." . PHP_EOL,
            $display,
        );
    }
}
