<?php

declare(strict_types=1);

namespace Tests\Libs\Prune;

use App\Libs\Config;
use App\Libs\Prune\FilePruner;
use App\Libs\TestCase;
use Psr\Log\NullLogger;

final class FilePrunerTest extends TestCase
{
    public function test_backup_retention(): void
    {
        $this->initTempApp();
        $backupPath = (string) Config::get('path') . '/backup';
        mkdir($backupPath);

        $file = $backupPath . '/server.20260101.json';
        file_put_contents($file, '{}');
        touch($file, strtotime('-60 DAYS'));
        Config::save('backup.prune.after', 30);

        (new FilePruner(new NullLogger()))(true);

        self::assertFileDoesNotExist($file);
    }

    public function test_log_retention(): void
    {
        $this->initTempApp();
        $logsPath = (string) Config::get('tmpDir') . '/logs';
        mkdir($logsPath);

        $file = $logsPath . '/app.log';
        file_put_contents($file, 'test');
        touch($file, strtotime('-10 DAYS'));
        Config::save('logs.prune.after', 8);

        (new FilePruner(new NullLogger()))(true);

        self::assertFileDoesNotExist($file);
    }
}
