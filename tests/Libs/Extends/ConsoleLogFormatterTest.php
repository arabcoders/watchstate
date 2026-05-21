<?php

declare(strict_types=1);

namespace Tests\Libs\Extends;

use App\Libs\Extends\ConsoleLogFormatter;
use App\Libs\TestCase;
use DateTimeImmutable;

final class ConsoleLogFormatterTest extends TestCase
{
    public function test_backend_host(): void
    {
        $formatter = new ConsoleLogFormatter();
        $date = new DateTimeImmutable('2026-05-21T07:36:40+03:00');

        $line = $formatter->format([
            'datetime' => $date,
            'level' => 'warning',
            'logger' => 'logger',
            'message' => 'Skipping backend.',
            'fields' => [
                'backend' => [
                    'name' => 'office_plex',
                ],
            ],
        ]);

        self::assertSame(
            sprintf(
                '<comment>%s</comment> <info>office_plex</info> <fg=yellow;options=bold>WARNING</> <fg=cyan>logger</> Skipping backend.',
                make_date($date)->format('m/d, H:i:s'),
            ),
            $line,
        );
    }

    public function test_event_name_host(): void
    {
        $formatter = new ConsoleLogFormatter();
        $date = new DateTimeImmutable('2026-05-21T07:36:40+03:00');

        $line = $formatter->format([
            'datetime' => $date,
            'level' => 'info',
            'logger' => 'logger',
            'message' => 'Request completed.',
            'fields' => [
                'event_name' => 'http.request.completed',
            ],
            'source' => [],
            'process' => [],
        ]);

        self::assertSame(
            sprintf(
                '<comment>%s</comment> <info>http.request.completed</info> <fg=cyan;options=bold>INFO</> <fg=cyan>logger</> Request completed.',
                make_date($date)->format('m/d, H:i:s'),
            ),
            $line,
        );
    }

    public function test_notice_color_only_uses_level(): void
    {
        $formatter = new ConsoleLogFormatter();
        $line = $formatter->format([
            'datetime' => new DateTimeImmutable('2026-05-21T07:36:40+03:00'),
            'level' => 'notice',
            'logger' => 'logger',
            'message' => 'Import failed for one item.',
            'fields' => [
                'user' => 'main',
            ],
        ]);

        self::assertStringContainsString('<fg=magenta;options=bold>NOTICE</>', $line);
        self::assertStringNotContainsString('<fg=red;options=bold>NOTICE</>', $line);
        self::assertStringContainsString('Import failed for one item.', $line);
    }
}
