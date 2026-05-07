<?php

declare(strict_types=1);

namespace Tests\Commands\Database;

use App\Commands\Database\IndexCommand;
use App\Libs\Container;
use App\Libs\Database\PdoFactory;
use App\Libs\Entity\StateEntity;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Libs\TestCase;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\DatabaseAssertionTrait;

final class IndexCommandTest extends TestCase
{
    use DatabaseAssertionTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();

        mkdir(self::$tmpPath . '/users/alice', 0o755, true);
        mkdir(self::$tmpPath . '/users/bob', 0o755, true);
    }

    public function test_all_targets(): void
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

    public function test_selected_user(): void
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

    public function test_invalid_user(): void
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
}
