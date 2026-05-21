<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\TestCase;

final class LoggingPrefixGuardTest extends TestCase
{
    public function test_scoped_logger_messages_do_not_use_legacy_prefixes(): void
    {
        $paths = [
            ROOT_PATH . '/src/Commands/State/BackupCommand.php',
            ROOT_PATH . '/src/Backends/Jellyfin/Action/Backup.php',
        ];

        $pattern = '/->(?:debug|info|notice|warning|error|critical|alert|emergency)\(\s*(?:message:\s*)?["\'](?:SYSTEM:|PLAYLIST:|MAPPER:|PDOAdapter:|HttpClient -|[A-Z][A-Za-z0-9_\\\\]+:)/';

        foreach ($paths as $path) {
            $contents = file_get_contents($path);

            self::assertIsString($contents);
            self::assertSame(0, preg_match($pattern, $contents), $path);
        }
    }
}
