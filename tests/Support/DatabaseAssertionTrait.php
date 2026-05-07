<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;

trait DatabaseAssertionTrait
{
    private function assertDbHasCoreTables(string $file): void
    {
        self::assertFileExists($file);

        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")?->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('events', $tables);
        self::assertContains('migration_version', $tables);
        self::assertContains('playlist_items', $tables);
        self::assertContains('playlists', $tables);
        self::assertContains('state', $tables);
    }
}
