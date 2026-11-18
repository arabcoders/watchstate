<?php

declare(strict_types=1);

namespace Tests\Libs\Prune;

use App\Libs\Config;
use App\Libs\Prune\FilePruner;
use App\Libs\TestCase;
use Psr\Log\NullLogger;

final class FilePrunerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->initTempApp();
        Config::save('guid.file', self::$tmpPath . '/config/guid.yaml');
    }

    public function test_backup_retention(): void
    {
        $backupPath = (string) Config::get('path') . '/backup';
        mkdir($backupPath);

        $file = $backupPath . '/server.20260101.json';
        file_put_contents($file, '{}');
        touch($file, strtotime('-60 DAYS'));
        Config::save('backup.prune.after', 30);

        new FilePruner(new NullLogger())(true);

        self::assertFileDoesNotExist($file);
    }

    public function test_log_retention(): void
    {
        $logsPath = (string) Config::get('tmpDir') . '/logs';
        mkdir($logsPath);

        $file = $logsPath . '/app.log';
        file_put_contents($file, 'test');
        touch($file, strtotime('-10 DAYS'));
        Config::save('logs.prune.after', 8);

        new FilePruner(new NullLogger())(true);

        self::assertFileDoesNotExist($file);
    }

    public function test_config_backups(): void
    {
        $identityPath = self::$tmpPath . '/users/alice';
        mkdir($identityPath, 0o755, true);
        Config::save('backup.prune.after', 30);
        $expired = [];
        $retained = [];

        foreach ([self::$tmpPath . '/config', $identityPath] as $directory) {
            foreach (['servers.yaml.bak', 'ignore.yaml.bak', 'mapper.yaml.bak'] as $filename) {
                $path = $directory . '/' . $filename;
                file_put_contents($path, 'old backup');
                touch($path, strtotime('-60 DAYS'));
                $expired[] = $path;
            }

            foreach (['servers.yaml', 'config.yaml', '.env', 'servers.yaml.bak.old', 'recent.yaml.bak'] as $filename) {
                $path = $directory . '/' . $filename;
                file_put_contents($path, 'retained');
                if ('recent.yaml.bak' !== $filename) {
                    touch($path, strtotime('-60 DAYS'));
                }
                $retained[] = $path;
            }
        }

        $pruner = new FilePruner(new NullLogger());
        $pruner(false);
        foreach ($expired as $path) {
            self::assertFileExists($path);
        }

        $pruner(true);
        foreach ($expired as $path) {
            self::assertFileDoesNotExist($path);
        }
        foreach ($retained as $path) {
            self::assertFileExists($path);
        }
    }

    public function test_custom_backup(): void
    {
        $directory = self::$tmpPath . '/custom';
        mkdir($directory);
        Config::save('backends_file', $directory . '/backends.yaml');
        Config::save('backup.prune.after', 30);
        foreach (['backends.yaml', 'backends.yaml.bak', 'unrelated.bak'] as $filename) {
            $path = $directory . '/' . $filename;
            file_put_contents($path, 'retained');
            touch($path, strtotime('-60 DAYS'));
        }

        new FilePruner(new NullLogger())(true);

        self::assertFileDoesNotExist($directory . '/backends.yaml.bak');
        self::assertFileExists($directory . '/backends.yaml');
        self::assertFileExists($directory . '/unrelated.bak');
    }
}
