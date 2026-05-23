<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Commands\State\ExportCommand;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\LogSuppressor;
use App\Libs\QueueRequests;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Tests\Support\FakeBackendClient;
use Tests\Support\StateCommandTestSupport;

final class ExportCommandTest extends TestCase
{
    use StateCommandTestSupport;

    public function test_force_full_updates_last_sync(): void
    {
        $logger = $this->initFakeBackendApp(
            mainBackends: $this->fakeBackendConfig('fake_export'),
            userBackends: [
                'alice' => $this->fakeBackendConfig('fake_export', [
                    'export' => [
                        'enabled' => true,
                        'lastSync' => 1_700_000_100,
                    ],
                ]),
            ],
        );
        $this->makeUserContext('alice', $logger);

        $command = new ExportCommand(
            $this->createRuntimeMapper($logger),
            new QueueRequests(),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );
        $tester = $this->makeTester($command);
        $status = $tester->execute([
            '--user' => 'alice',
            '--force-full' => true,
        ]);

        self::assertSame(ExportCommand::SUCCESS, $status);
        self::assertSame([
            [
                'backend' => 'fake_export',
                'user' => 'alice',
                'after' => null,
            ],
        ], FakeBackendClient::getCalls('export'));
        self::assertSame([], FakeBackendClient::getCalls('push'));

        $aliceConfig = Yaml::parseFile(self::$tmpPath . '/users/alice/servers.yaml');
        self::assertGreaterThan(1_700_000_100, ag($aliceConfig, 'fake_export.export.lastSync', 0));
    }

    public function test_invalid_user_returns_failure(): void
    {
        $logger = new Logger('test');
        $command = new ExportCommand(
            $this->createStub(iImport::class),
            new QueueRequests(),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );
        $tester = $this->makeTester($command);
        $status = $tester->execute([
            '--user' => 'ghost',
        ]);

        self::assertSame(ExportCommand::FAILURE, $status);
        self::assertStringContainsString("User 'ghost' not found.", $tester->getDisplay());
    }

    public function test_matching_backend_uses_push(): void
    {
        $logger = $this->initFakeBackendApp(
            mainBackends: $this->fakeBackendConfig('fake_export'),
            userBackends: [
                'alice' => $this->fakeBackendConfig('fake_export', [
                    'import' => [
                        'enabled' => true,
                        'lastSync' => 1_700_000_000,
                    ],
                    'export' => [
                        'enabled' => true,
                        'lastSync' => 1_700_000_050,
                    ],
                ]),
            ],
        );
        $handler = new TestHandler();
        $logger->setHandlers([$handler]);
        $logger->pushProcessor(new LogMessageProcessor());

        $aliceContext = $this->makeUserContext('alice', $logger);
        $entity = require __DIR__ . '/../../Fixtures/MovieEntity.php';
        $entity[iState::COLUMN_VIA] = 'fake_export';
        $entity[iState::COLUMN_UPDATED] = 1_700_000_200;
        $entity[iState::COLUMN_CREATED_AT] = 1_700_000_000;
        $entity[iState::COLUMN_UPDATED_AT] = 1_700_000_200;
        $entity[iState::COLUMN_META_DATA] = [
            'fake_export' => [
                iState::COLUMN_ID => 901,
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_WATCHED => 1,
                iState::COLUMN_META_DATA_ADDED_AT => 1_700_000_000,
                iState::COLUMN_META_DATA_PLAYED_AT => 1_700_000_150,
            ],
        ];
        $aliceContext->db->insert(new StateEntity($entity));

        $command = new ExportCommand(
            $this->createRuntimeMapper($logger),
            new QueueRequests(),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );
        $tester = $this->makeTester($command);
        $status = $tester->execute([
            '--user' => 'alice',
        ]);

        self::assertSame(ExportCommand::SUCCESS, $status);
        self::assertSame([], FakeBackendClient::getCalls('export'));
        self::assertSame([
            [
                'backend' => 'fake_export',
                'user' => 'alice',
                'count' => 1,
                'after' => 1_700_000_050,
            ],
        ], FakeBackendClient::getCalls('push'));

        $records = array_values(array_filter(
            $handler->getRecords(),
            static fn($record): bool => 'state.export.push.started' === ($record->context['event_name'] ?? null),
        ));

        self::assertCount(1, $records);
        self::assertSame("Pushing 1 local changes to 1 backends for 'alice'.", $records[0]->message);
        self::assertSame(1, $records[0]->context['item_count']);
        self::assertSame(['fake_export'], $records[0]->context['backends']);
    }

    public function test_row_timestamp_uses_push(): void
    {
        $logger = $this->initFakeBackendApp(
            mainBackends: $this->fakeBackendConfig('fake_export'),
            userBackends: [
                'alice' => $this->fakeBackendConfig('fake_export', [
                    'import' => [
                        'enabled' => true,
                        'lastSync' => 1_700_000_000,
                    ],
                    'export' => [
                        'enabled' => true,
                        'lastSync' => 1_700_000_100,
                    ],
                ]),
            ],
        );

        $aliceContext = $this->makeUserContext('alice', $logger);
        $entity = require __DIR__ . '/../../Fixtures/MovieEntity.php';
        $entity[iState::COLUMN_VIA] = 'fake_export';
        $entity[iState::COLUMN_UPDATED] = 1_700_000_050;
        $entity[iState::COLUMN_CREATED_AT] = 1_700_000_000;
        $entity[iState::COLUMN_UPDATED_AT] = 1_700_000_150;
        $entity[iState::COLUMN_META_DATA] = [
            'fake_export' => [
                iState::COLUMN_ID => 901,
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_WATCHED => 1,
                iState::COLUMN_META_DATA_ADDED_AT => 1_700_000_000,
                iState::COLUMN_META_DATA_PLAYED_AT => 1_700_000_050,
            ],
        ];
        $aliceContext->db->insert(new StateEntity($entity));

        $command = new ExportCommand(
            $this->createRuntimeMapper($logger),
            new QueueRequests(),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );
        $status = $this->makeTester($command)->execute([
            '--user' => 'alice',
        ]);

        self::assertSame(ExportCommand::SUCCESS, $status);
        self::assertSame([], FakeBackendClient::getCalls('export'));
        self::assertSame([
            [
                'backend' => 'fake_export',
                'user' => 'alice',
                'count' => 1,
                'after' => 1_700_000_100,
            ],
        ], FakeBackendClient::getCalls('push'));

        $aliceConfig = Yaml::parseFile(self::$tmpPath . '/users/alice/servers.yaml');
        self::assertSame(1_700_000_150, ag($aliceConfig, 'fake_export.export.lastSync'));
    }

    public function test_trace_logs_items_when_no_backend_updates_are_required(): void
    {
        $logger = $this->initFakeBackendApp(
            mainBackends: $this->fakeBackendConfig('fake_export'),
            userBackends: [
                'alice' => $this->fakeBackendConfig('fake_export', [
                    'import' => [
                        'enabled' => true,
                        'lastSync' => 1_700_000_000,
                    ],
                    'export' => [
                        'enabled' => true,
                        'lastSync' => 1_700_000_100,
                    ],
                ]),
            ],
        );
        $handler = new TestHandler();
        $logger->setHandlers([$handler]);
        $logger->pushProcessor(new LogMessageProcessor());

        $aliceContext = $this->makeUserContext('alice', $logger);
        $entity = require __DIR__ . '/../../Fixtures/MovieEntity.php';
        $entity[iState::COLUMN_VIA] = 'fake_export';
        $entity[iState::COLUMN_UPDATED] = 1_700_000_050;
        $entity[iState::COLUMN_CREATED_AT] = 1_700_000_000;
        $entity[iState::COLUMN_UPDATED_AT] = 1_700_000_150;
        $entity[iState::COLUMN_META_DATA] = [
            'fake_export' => [
                iState::COLUMN_ID => 901,
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_WATCHED => 1,
                iState::COLUMN_META_DATA_ADDED_AT => 1_700_000_000,
                iState::COLUMN_META_DATA_PLAYED_AT => 1_700_000_050,
            ],
        ];
        $inserted = $aliceContext->db->insert(new StateEntity($entity));

        $status = $this->makeTester(new ExportCommand(
            $this->createRuntimeMapper($logger),
            new QueueRequests(),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        ))->execute([
            '--user' => 'alice',
            '--trace' => true,
        ]);

        self::assertSame(ExportCommand::SUCCESS, $status);

        $records = array_values(array_filter(
            $handler->getRecords(),
            static fn($record): bool => 'state.export.no_changes' === ($record->context['event_name'] ?? null),
        ));

        self::assertCount(1, $records);
        self::assertSame("No backend play-state updates were required for 'alice'.", $records[0]->message);
        self::assertSame(1, $records[0]->context['local_change_count']);
        self::assertSame('Movie Title (2020)', $records[0]->context['items'][$inserted->id]['title']);
        self::assertSame('movie', $records[0]->context['items'][$inserted->id]['type']);
        self::assertSame('fake_export', $records[0]->context['items'][$inserted->id]['via']);
        self::assertArrayHasKey('metadata', $records[0]->context['items'][$inserted->id]);
    }

    public function test_logfile_lifecycle(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_export'));
        $logger->pushProcessor(new LogMessageProcessor());
        $this->migrateMainDb($logger);

        $logfile = self::$tmpPath . '/export-log.txt';
        touch($logfile);

        $command = new ExportCommand(
            $this->createRuntimeMapper($logger),
            new QueueRequests(),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );

        $status = $this->makeTester($command)->execute([
            '--logfile' => $logfile,
        ], [
            'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
        ]);

        self::assertSame(ExportCommand::SUCCESS, $status);

        $contents = file_get_contents($logfile);
        self::assertIsString($contents);
        self::assertStringContainsString('Export started for 1 users.', $contents);
        self::assertStringContainsString("Exporting play states for 'main'.", $contents);
        self::assertStringContainsString('Export completed for 1 users in', $contents);
    }

    public function test_logs_unsupported_backend(): void
    {
        $logger = $this->initFakeBackendApp([
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
        $handler = new TestHandler();
        $logger->setHandlers([$handler]);
        $logger->pushProcessor(new LogMessageProcessor());
        $this->migrateMainDb($logger);

        $command = new ExportCommand(
            $this->createRuntimeMapper($logger),
            new QueueRequests(),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );

        $status = $this->makeTester($command)->execute([]);

        self::assertSame(ExportCommand::SUCCESS, $status);

        $records = array_values(array_filter(
            $handler->getRecords(),
            static fn($record): bool => 'state.export.backend.skipped' === ($record->context['event_name'] ?? null),
        ));

        self::assertCount(1, $records);
        self::assertSame("Skipping 'main@bad_backend': backend type 'bad' is unsupported.", $records[0]->message);
        self::assertSame('unsupported_type', $records[0]->context['reason']);
        self::assertSame('bad_backend', $records[0]->context['backend']);
        self::assertSame('bad', $records[0]->context['backend_type']);
    }

    private function makeTester(ExportCommand $command): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('trace', null, InputOption::VALUE_NONE));
        $application->addCommand($command);

        return new CommandTester($application->find(ExportCommand::ROUTE));
    }
}
