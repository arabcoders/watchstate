<?php

declare(strict_types=1);

namespace Tests\Commands\Database;

use App\Commands\Database\MigrationsCommand;
use App\Libs\Database\PackageMigrationFactory;
use App\Libs\Database\PdoFactory;
use App\Libs\TestCase;
use PDO;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class MigrationsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
    }

    public function test_autogen_creates_migration(): void
    {
        $migrationDir = self::$tmpPath . '/generated-migrations';
        $command = new class($this->mainPdo(), new PackageMigrationFactory(), $migrationDir) extends MigrationsCommand {
            public function __construct(
                PDO $pdo,
                PackageMigrationFactory $migrations,
                private readonly string $migrationDir,
            ) {
                parent::__construct($pdo, $migrations);
            }

            protected function migrationDirectory(): string
            {
                return $this->migrationDir;
            }
        };

        $application = new Application();
        $application->addCommand($command);

        $tester = new CommandTester($application->find(MigrationsCommand::ROUTE));
        $status = $tester->execute([
            '--autogen' => 'initial_schema',
            '--execute' => true,
        ]);

        self::assertSame(MigrationsCommand::SUCCESS, $status);

        $files = glob($migrationDir . '/Migration_*.php');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        self::assertStringContainsString('App\\Migration', (string) file_get_contents($files[0]));
    }

    public function test_autogen_no_changes(): void
    {
        $migrationDir = self::$tmpPath . '/generated-migrations';
        mkdir($migrationDir, 0o755, true);

        $pdo = $this->mainPdo();
        $migrations = new PackageMigrationFactory();

        $migrations->migrate($pdo, dryRun: false);

        $db = $pdo->query(
            "CREATE INDEX IF NOT EXISTS \"state_parent_guid_imdb\" ON \"state\" (JSON_EXTRACT(parent,'$.guid_imdb'));",
        );
        unset($db);

        $command = new class($pdo, new PackageMigrationFactory(), $migrationDir) extends MigrationsCommand {
            public function __construct(
                PDO $pdo,
                PackageMigrationFactory $migrations,
                private readonly string $migrationDir,
            ) {
                parent::__construct($pdo, $migrations);
            }

            protected function migrationDirectory(): string
            {
                return $this->migrationDir;
            }
        };

        $application = new Application();
        $application->addCommand($command);

        $tester = new CommandTester($application->find(MigrationsCommand::ROUTE));
        $status = $tester->execute([
            '--autogen' => 'no_changes',
            '--execute' => true,
        ]);

        self::assertSame(MigrationsCommand::SUCCESS, $status);
        self::assertStringContainsString('No schema changes found.', $tester->getDisplay());

        $files = glob($migrationDir . '/Migration_*.php');
        self::assertIsArray($files);
        self::assertCount(0, $files);
    }

    private function mainPdo(): PDO
    {
        return new PdoFactory()->createForFile(self::$tmpPath . '/db/' . PdoFactory::DB_FILE);
    }
}
