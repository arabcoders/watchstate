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
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use PDOException;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeBackendClient;
use Tests\Support\StateCommandTestSupport;

final class ImportCommandTest extends TestCase
{
    use StateCommandTestSupport;

    public function test_request_phase_rollback(): void
    {
        $this->initTempApp();
        Config::save('backends_file', __DIR__ . '/../../Fixtures/test_servers.yaml');

        $logger = new Logger('test', processors: [new LogMessageProcessor()]);
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

    public function test_logfile_lifecycle(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_import'));
        $logger->pushProcessor(new LogMessageProcessor());
        $this->migrateMainDb($logger);

        $logfile = self::$tmpPath . '/import-log.txt';
        touch($logfile);

        $command = new ImportCommand(
            $this->createRuntimeMapper($logger),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );

        $status = $this->makeTester($command)->execute([
            '--logfile' => $logfile,
        ], [
            'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
        ]);

        self::assertSame(ImportCommand::SUCCESS, $status);

        $contents = file_get_contents($logfile);
        self::assertIsString($contents);
        self::assertStringContainsString('Import started for 1 users.', $contents);
        self::assertStringContainsString("Importing play states for 'main'.", $contents);
        self::assertStringContainsString('Import completed for 1 users in', $contents);
    }

    public function test_logs_skipped_backend_with_structured_context(): void
    {
        $logger = new Logger('test', [new TestHandler()], [new LogMessageProcessor()]);
        $this->initTempApp();
        $this->writeBackendsFile((string) Config::get('backends_file'), [
            'bad_backend' => [
                'type' => 'bad',
                'url' => 'https://bad.example.invalid',
                'token' => 'token',
                'user' => 'user',
                'uuid' => 'uuid',
                'import' => [
                    'enabled' => true,
                    'lastSync' => 1_700_000_000,
                ],
                'export' => [
                    'enabled' => true,
                    'lastSync' => 1_700_000_000,
                ],
                'options' => [],
            ],
        ]);
        $this->migrateMainDb($logger);

        $command = new ImportCommand(
            new DirectMapper($logger, Container::get(iDB::class), Container::get(iCache::class)),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );

        $status = $this->makeTester($command)->execute([]);

        self::assertSame(ImportCommand::SUCCESS, $status);

        $handler = $logger->getHandlers()[0];
        self::assertInstanceOf(TestHandler::class, $handler);

        $records = array_values(array_filter(
            $handler->getRecords(),
            static fn($record): bool => 'state.import.backend.skipped' === ($record->context['event_name'] ?? null),
        ));

        self::assertCount(1, $records);
        self::assertSame(
            "Skipping 'main@bad_backend': backend type 'bad' is unsupported.",
            $records[0]->message,
        );
        self::assertSame('unsupported_type', $records[0]->context['reason']);
        self::assertSame('bad_backend', $records[0]->context['backend']);
        self::assertSame('bad', $records[0]->context['backend_type']);
    }

    public function test_fake_backend_runs_import(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_import', [
            'import' => [
                'enabled' => false,
            ],
        ]));
        $logger->pushProcessor(new LogMessageProcessor());
        $this->migrateMainDb($logger);
        FakeBackendClient::reset();

        $command = new ImportCommand(
            $this->createRuntimeMapper($logger),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );

        $status = $this->makeTester($command)->execute([]);

        self::assertSame(ImportCommand::SUCCESS, $status);
        self::assertSame([], FakeBackendClient::getCalls('metadata'));
        self::assertSame([], FakeBackendClient::getCalls('backup'));

        $saved = Yaml::parseFile((string) Config::get('backends_file'));
        self::assertFalse(ag_exists(ag($saved, 'fake_import.options', []), 'IMPORT_METADATA_ONLY'));
    }

    public function test_orders_full_before_metadata(): void
    {
        $logger = $this->initFakeBackendApp([
            ...$this->fakeBackendConfig('metadata_first', [
                'import' => [
                    'enabled' => false,
                ],
            ]),
            ...$this->fakeBackendConfig('full_second', [
                'import' => [
                    'enabled' => true,
                ],
            ]),
        ]);
        $this->migrateMainDb($logger);

        FakeBackendClient::reset();

        $command = new ImportCommand(
            $this->createRuntimeMapper($logger),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );

        $status = $this->makeTester($command)->execute([]);

        self::assertSame(ImportCommand::SUCCESS, $status);
        self::assertSame([
            [
                'backend' => 'full_second',
                'user' => 'main',
                'after' => 1_700_000_000,
            ],
            [
                'backend' => 'metadata_first',
                'user' => 'main',
                'after' => 1_700_000_000,
            ],
        ], FakeBackendClient::getCalls('pull'));
    }

    public function test_invalid_user_returns_failure(): void
    {
        $logger = new Logger('test');
        $command = new ImportCommand(
            $this->createStub(iImport::class),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );
        $status = $this->makeTester($command)->execute([
            '--user' => 'ghost',
        ]);

        self::assertSame(ImportCommand::FAILURE, $status);
    }

    private function makeTester(ImportCommand $command): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('trace', null, InputOption::VALUE_NONE));
        $application->getDefinition()->addOption(new InputOption('output', 'o', InputOption::VALUE_REQUIRED, '', 'table'));
        $application->addCommand($command);

        return new CommandTester($application->find(ImportCommand::ROUTE));
    }
}
