<?php

declare(strict_types=1);

namespace Tests\Commands\Database;

use App\Commands\Database\IndexCommand;
use App\Libs\Container;
use App\Libs\Database\PdoFactory;
use App\Libs\Entity\StateEntity;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
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
        $handler = new TestHandler();
        $logger = new Logger('test', [], [new LogMessageProcessor()]);
        $logger->pushHandler($handler);

        $tester = $this->makeTester(new IndexCommand($this->makeMapper($logger), $logger));
        $status = $tester->execute([
            '--force-reindex' => true,
        ]);

        self::assertSame(IndexCommand::SUCCESS, $status);
        self::assertTrue($handler->hasNoticeThatContains("User 'main' database indexes have been recreated."));
        self::assertTrue($handler->hasNoticeThatContains("User 'alice' database indexes have been recreated."));
        self::assertTrue($handler->hasNoticeThatContains("User 'bob' database indexes have been recreated."));
        self::assertTrue($handler->hasNoticeThatContains("User 'main' database indexes have been recreated."));
        self::assertTrue($handler->hasNoticeThatContains("User 'alice' database indexes have been recreated."));
        self::assertTrue($handler->hasNoticeThatContains("User 'bob' database indexes have been recreated."));
        $this->assertDbHasCoreTables(self::$tmpPath . '/db/' . PdoFactory::DB_FILE);
        $this->assertDbHasCoreTables(self::$tmpPath . '/users/alice/' . PdoFactory::DB_FILE);
        $this->assertDbHasCoreTables(self::$tmpPath . '/users/bob/' . PdoFactory::DB_FILE);
    }

    public function test_selected_user(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [], [new LogMessageProcessor()]);
        $logger->pushHandler($handler);

        $tester = $this->makeTester(new IndexCommand($this->makeMapper($logger), $logger));
        $status = $tester->execute([
            '--user' => 'alice',
            '--force-reindex' => true,
        ]);

        self::assertSame(IndexCommand::SUCCESS, $status);
        self::assertTrue($handler->hasNoticeThatContains("User 'alice' database indexes have been recreated."));
        self::assertFalse($handler->hasNoticeThatContains("User 'main' database indexes have been recreated."));
        self::assertFalse($handler->hasNoticeThatContains("User 'bob' database indexes have been recreated."));
        self::assertFalse(file_exists(self::$tmpPath . '/db/' . PdoFactory::DB_FILE));
        self::assertFalse(file_exists(self::$tmpPath . '/users/bob/' . PdoFactory::DB_FILE));
        $this->assertDbHasCoreTables(self::$tmpPath . '/users/alice/' . PdoFactory::DB_FILE);
    }

    public function test_invalid_user(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [], [new LogMessageProcessor()]);
        $logger->pushHandler($handler);

        $tester = $this->makeTester(new IndexCommand($this->makeMapper($logger), $logger));
        $status = $tester->execute([
            '--user' => 'ghost',
        ]);

        self::assertSame(IndexCommand::FAILURE, $status);
        self::assertTrue($handler->hasErrorThatContains("User 'ghost' not found."));
    }

    private function makeMapper(Logger $logger): DirectMapper
    {
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
