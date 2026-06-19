<?php

declare(strict_types=1);

namespace Tests\Commands\System;

use App\Commands\System\LogsCommand;
use App\Libs\TestCase;

final class LogsCommandTest extends TestCase
{
    public function test_level_color(): void
    {
        $notice = LogsCommand::formatEventLine([
            'id' => 'log-1',
            'datetime' => '2026-06-18T12:00:00+00:00',
            'level' => 'notice',
            'logger' => 'app',
            'message' => 'Import failed but was retried.',
        ]);

        self::assertStringContainsString('<fg=magenta;options=bold>NOTICE</>', $notice);
        self::assertStringNotContainsString('<fg=red;options=bold>NOTICE</>', $notice);

        $error = LogsCommand::formatEventLine([
            'id' => 'log-2',
            'datetime' => '2026-06-18T12:00:00+00:00',
            'level' => 'error',
            'logger' => 'app',
            'message' => 'Import completed.',
        ]);

        self::assertStringContainsString('<fg=red;options=bold>ERROR</>', $error);
    }
}
