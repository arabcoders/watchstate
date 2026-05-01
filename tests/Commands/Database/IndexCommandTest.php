<?php

declare(strict_types=1);

namespace Tests\Commands\Database;

use App\Commands\Database\IndexCommand;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\PdoFactory;
use App\Libs\Entity\StateEntity;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Libs\TestCase;
use Monolog\Logger;
use PDO;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class IndexCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempDir();

        $configDir = self::$tmpPath . '/config';
        mkdir($configDir, 0o755, true);

        Config::init(require __DIR__ . '/../../../config/config.php');
        Config::save('path', self::$tmpPath);
        Config::save('tmpDir', self::$tmpPath);
        Config::save('cache.path', self::$tmpPath . '/cache');
        Config::save('backends_file', $configDir . '/servers.yaml');
        Config::save('mapper_file', $configDir . '/mapper.yaml');
        Config::save('database.file', self::$tmpPath . '/db/' . PdoFactory::DB_FILE);
        Config::save('database.dsn', 'sqlite:' . self::$tmpPath . '/db/' . PdoFactory::DB_FILE);

        Container::reset();
        Container::init();

        foreach ((array) require __DIR__ . '/../../../config/services.php' as $name => $definition) {
            Container::add($name, $definition);
        }

        mkdir(self::$tmpPath . '/users/alice', 0o755, true);
        mkdir(self::$tmpPath . '/users/bob', 0o755, true);
    }

    public function test_signature(): void
    {
        $command = new IndexCommand($this->makeMapper(), new Logger('test'));

        self::assertSame('db:index', $command->getName());
        self::assertTrue($command->getDefinition()->hasOption('user'));
        self::assertTrue($command->getDefinition()->hasOption('dry-run'));
        self::assertTrue($command->getDefinition()->hasOption('force-reindex'));
    }

    public function test_runs_all_targets_by_default(): void
    {
        $tester = $this->makeTester(new IndexCommand($this->makeMapper(), new Logger('test')));
        $status = $tester->execute([
            '--force-reindex' => true,
        ]);

        self::assertSame(IndexCommand::SUCCESS, $status);
        self::assertStringContainsString("User 'main' Database Indexes have been recreated successfully.", $tester->getDisplay());
        self::assertStringContainsString("User 'alice' Database Indexes have been recreated successfully.", $tester->getDisplay());
        self::assertStringContainsString("User 'bob' Database Indexes have been recreated successfully.", $tester->getDisplay());
        $this->assertDbHasCoreTables(self::$tmpPath . '/db/' . PdoFactory::DB_FILE);
        $this->assertDbHasCoreTables(self::$tmpPath . '/users/alice/' . PdoFactory::DB_FILE);
        $this->assertDbHasCoreTables(self::$tmpPath . '/users/bob/' . PdoFactory::DB_FILE);
    }

    public function test_runs_selected_user_only(): void
    {
        $tester = $this->makeTester(new IndexCommand($this->makeMapper(), new Logger('test')));
        $status = $tester->execute([
            '--user' => 'alice',
            '--force-reindex' => true,
        ]);

        self::assertSame(IndexCommand::SUCCESS, $status);
        self::assertStringContainsString("User 'alice' Database Indexes have been recreated successfully.", $tester->getDisplay());
        self::assertStringNotContainsString("User 'main' Database Indexes have been recreated successfully.", $tester->getDisplay());
        self::assertStringNotContainsString("User 'bob' Database Indexes have been recreated successfully.", $tester->getDisplay());
        self::assertFalse(file_exists(self::$tmpPath . '/db/' . PdoFactory::DB_FILE));
        self::assertFalse(file_exists(self::$tmpPath . '/users/bob/' . PdoFactory::DB_FILE));
        $this->assertDbHasCoreTables(self::$tmpPath . '/users/alice/' . PdoFactory::DB_FILE);
    }

    public function test_invalid_user_returns_failure(): void
    {
        $tester = $this->makeTester(new IndexCommand($this->makeMapper(), new Logger('test')));
        $status = $tester->execute([
            '--user' => 'ghost',
        ]);

        self::assertSame(IndexCommand::FAILURE, $status);
        self::assertStringContainsString("User 'ghost' not found.", $tester->getDisplay());
    }

    private function makeMapper(): DirectMapper
    {
        $logger = new Logger('test');
        $db = $this->createDb($logger);
        $db->setOptions([
            Options::DEBUG_TRACE => true,
            'class' => new StateEntity([]),
        ]);

        return new DirectMapper(
            logger: $logger,
            db: $db,
            cache: Container::get(\Psr\SimpleCache\CacheInterface::class),
        );
    }

    private function makeTester(IndexCommand $command): CommandTester
    {
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find(IndexCommand::ROUTE));
    }

    private function assertDbHasCoreTables(string $file): void
    {
        self::assertFileExists($file);

        $pdo = new PDO('sqlite:' . $file);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")?->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('events', $tables);
        self::assertContains('migration_version', $tables);
        self::assertContains('playlist_items', $tables);
        self::assertContains('playlists', $tables);
        self::assertContains('state', $tables);
    }
}
