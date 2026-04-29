<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Backends\Common\ClientInterface as iClient;
use App\Commands\State\ImportCommand;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Entity\StateEntity;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\TestCase;
use App\Libs\UserContext;
use Monolog\Logger;
use PDOException;
use PHPUnit\Framework\Assert;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class ImportCommandTest extends TestCase
{
    public function test_request_phase_rollback(): void
    {
        Config::init(require __DIR__ . '/../../../config/config.php');

        $logger = new Logger('test');
        $db = $this->createDb($logger);
        $db->setOptions(['class' => new StateEntity([])]);
        $cache = new Psr16Cache(new ArrayAdapter());
        $entity = $db->insert(new StateEntity(require __DIR__ . '/../../Fixtures/EpisodeEntity.php'));

        $userContext = new UserContext(
            name: 'main',
            config: new ConfigFile(
                file: __DIR__ . '/../../Fixtures/test_servers.yaml',
                autoSave: false,
                autoCreate: false,
                autoBackup: false,
            ),
            mapper: new DirectMapper(logger: $logger, db: $db, cache: $cache),
            cache: $cache,
            db: $db,
        );

        $client = $this->createStub(iClient::class);
        $client->method('pull')->willReturn([]);

        $http = $this->createStub(iHttp::class);

        $command = new class($userContext, $logger, $client, $http, $entity) extends ImportCommand {
            public bool $sendRequestsCalled = false;

            public function __construct(
                private readonly UserContext $userContext,
                Logger $logger,
                private readonly iClient $client,
                iHttp $http,
                private readonly StateEntity $entity,
            ) {
                parent::__construct(
                    mapper: $this->userContext->mapper,
                    logger: $logger,
                    suppressor: new LogSuppressor([]),
                    http: $http,
                );
            }

            /**
             * @return array<string,UserContext>
             */
            protected function getUsers(array $dbOpts = []): array
            {
                return ['main' => $this->userContext];
            }

            protected function makeBackend(array $backend, string $name, UserContext $userContext): iClient
            {
                return $this->client;
            }

            protected function sendRequests(array $queue, bool $syncRequests): void
            {
                Assert::assertTrue(
                    $this->userContext->db->getDBLayer()->inTransaction(),
                    'Import request phase should run inside a single adapter-managed DB transaction.'
                );

                $this->sendRequestsCalled = true;

                $this->userContext->db->getDBLayer()->exec('DROP TABLE state');
                $this->userContext->db->update(clone $this->entity);
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
