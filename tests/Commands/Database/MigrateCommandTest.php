<?php

declare(strict_types=1);

namespace Tests\Commands\Database;

use App\Commands\Database\MigrateCommand;
use App\Libs\Database\PackageMigrationFactory;
use App\Libs\Database\PdoFactory;
use App\Libs\TestCase;
use Monolog\Logger;
use PDO;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class MigrateCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
    }

    public function test_all_targets(): void
    {
        mkdir(self::$tmpPath . '/users/alice', 0o755, true);
        mkdir(self::$tmpPath . '/users/bob', 0o755, true);

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--execute' => true,
        ]);

        self::assertSame(MigrateCommand::SUCCESS, $status);

        $this->assertDbHasCoreTables(self::$tmpPath . '/db/' . PdoFactory::DB_FILE);
        $this->assertDbHasCoreTables(self::$tmpPath . '/users/alice/' . PdoFactory::DB_FILE);
        $this->assertDbHasCoreTables(self::$tmpPath . '/users/bob/' . PdoFactory::DB_FILE);
    }

    public function test_selected_user(): void
    {
        mkdir(self::$tmpPath . '/users/alice', 0o755, true);
        mkdir(self::$tmpPath . '/users/bob', 0o755, true);

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--execute' => true,
            '--user' => 'alice',
        ]);

        self::assertSame(MigrateCommand::SUCCESS, $status);

        self::assertFalse(file_exists(self::$tmpPath . '/db/' . PdoFactory::DB_FILE));
        $this->assertDbHasCoreTables(self::$tmpPath . '/users/alice/' . PdoFactory::DB_FILE);
        self::assertFalse(file_exists(self::$tmpPath . '/users/bob/' . PdoFactory::DB_FILE));
    }

    private function makeTester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new MigrateCommand(new PdoFactory(), new PackageMigrationFactory(), new Logger('test')));

        return new CommandTester($application->find(MigrateCommand::ROUTE));
    }

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
