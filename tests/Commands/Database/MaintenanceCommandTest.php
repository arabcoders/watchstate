<?php

declare(strict_types=1);

namespace Tests\Commands\Database;

use App\Commands\Database\MaintenanceCommand;
use App\Libs\Container;
use App\Libs\Database\PdoFactory;
use App\Libs\Entity\StateEntity;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Libs\TestCase;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class MaintenanceCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();

        mkdir(self::$tmpPath . '/users/alice', 0o755, true);
        mkdir(self::$tmpPath . '/users/bob', 0o755, true);
    }

    public function test_all_targets(): void
    {
        $tester = $this->makeTester(new MaintenanceCommand($this->makeMapper(), new Logger('test')));
        $status = $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertSame(MaintenanceCommand::SUCCESS, $status);
        self::assertStringContainsString("Optimizing user 'main' database.", $tester->getDisplay());
        self::assertStringContainsString("Optimizing user 'alice' database.", $tester->getDisplay());
        self::assertStringContainsString("Optimizing user 'bob' database.", $tester->getDisplay());
        self::assertFileExists(self::$tmpPath . '/users/alice/' . PdoFactory::DB_FILE);
        self::assertFileExists(self::$tmpPath . '/users/bob/' . PdoFactory::DB_FILE);
    }

    public function test_selected_user(): void
    {
        $tester = $this->makeTester(new MaintenanceCommand($this->makeMapper(), new Logger('test')));
        $status = $tester->execute([
            '--user' => 'alice',
        ], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertSame(MaintenanceCommand::SUCCESS, $status);
        self::assertStringContainsString("Optimizing user 'alice' database.", $tester->getDisplay());
        self::assertStringNotContainsString("Optimizing user 'main' database.", $tester->getDisplay());
        self::assertStringNotContainsString("Optimizing user 'bob' database.", $tester->getDisplay());
        self::assertFileDoesNotExist(self::$tmpPath . '/db/' . PdoFactory::DB_FILE);
        self::assertFileExists(self::$tmpPath . '/users/alice/' . PdoFactory::DB_FILE);
        self::assertFileDoesNotExist(self::$tmpPath . '/users/bob/' . PdoFactory::DB_FILE);
    }

    public function test_invalid_user(): void
    {
        $tester = $this->makeTester(new MaintenanceCommand($this->makeMapper(), new Logger('test')));
        $status = $tester->execute([
            '--user' => 'ghost',
        ]);

        self::assertSame(MaintenanceCommand::FAILURE, $status);
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

    private function makeTester(MaintenanceCommand $command): CommandTester
    {
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find(MaintenanceCommand::ROUTE));
    }
}
