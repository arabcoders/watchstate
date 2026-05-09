<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Backends\Common\ClientInterface as iClient;
use App\Commands\State\ImportCommand;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\PackageMigrationFactory;
use App\Libs\Entity\StateEntity;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\TestCase;
use Monolog\Logger;
use PDO;
use PDOException;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class ImportCommandTest extends TestCase
{
    public function test_request_phase_rollback(): void
    {
        $this->initTempApp();
        Config::save('backends_file', __DIR__ . '/../../Fixtures/test_servers.yaml');

        $logger = new Logger('test');
        $pdo = Container::get(PDO::class);
        $migrations = new PackageMigrationFactory();
        if (false === $migrations->isMigrated($pdo)) {
            $migrations->migrate($pdo, dryRun: false);
        }
        ensure_indexes($pdo, $logger);

        $db = Container::get(iDB::class);
        $db->setOptions(['class' => new StateEntity([])]);
        $cache = Container::get(iCache::class);
        $entity = $db->insert(new StateEntity(require __DIR__ . '/../../Fixtures/EpisodeEntity.php'));

        $client = $this->createStub(iClient::class);
        $client->method('pull')->willReturn([]);

        $http = $this->createStub(iHttp::class);

        $command = new class($db, $cache, $logger, $client, $http, $entity) extends ImportCommand {
            public bool $sendRequestsCalled = false;

            public function __construct(
                private readonly iDB $db,
                private readonly iCache $cache,
                Logger $logger,
                private readonly iClient $client,
                iHttp $http,
                private readonly StateEntity $entity,
            ) {
                parent::__construct(
                    mapper: new DirectMapper(logger: $logger, db: $this->db, cache: $this->cache),
                    logger: $logger,
                    suppressor: new LogSuppressor([]),
                    http: $http,
                );
            }

            protected function makeBackend(array $backend, string $name, \App\Libs\UserContext $userContext): iClient
            {
                return $this->client;
            }

            protected function sendRequests(array $queue, bool $syncRequests): void
            {
                $this->sendRequestsCalled = true;

                $this->db->getDBLayer()->exec('DROP TABLE state');
                $this->db->update(clone $this->entity);
            }
        };

        $this->checkException(
            closure: fn() => $this->makeTester($command)->execute(['--dry-run' => true]),
            reason: 'Import should bubble database failures from the request phase when wrapped in adapter transaction state.',
            exception: PDOException::class,
            exceptionMessage: 'no such table: state',
        );

        self::assertTrue($command->sendRequestsCalled, 'Import command should reach the request processing phase.');

        $stateTable = $db->getDBLayer()->query(
            sql: "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'state'"
        )->fetchColumn();

        self::assertSame('state', $stateTable, 'Failed request-phase writes should be rolled back with the adapter transaction.');
    }

    private function makeTester(ImportCommand $command): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('trace', null, InputOption::VALUE_NONE));
        $application->addCommand($command);

        return new CommandTester($application->find(ImportCommand::ROUTE));
    }
}
