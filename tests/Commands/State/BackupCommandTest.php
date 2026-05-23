<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Commands\State\BackupCommand;
use App\Libs\Extends\JsonlFormatter;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Tests\Support\FakeBackendClient;
use Tests\Support\StateCommandTestSupport;

final class BackupCommandTest extends TestCase
{
    use StateCommandTestSupport;

    public function test_selected_user_backup_file(): void
    {
        $logger = $this->initFakeBackendApp(
            mainBackends: $this->fakeBackendConfig('fake_backup'),
            userBackends: [
                'alice' => $this->fakeBackendConfig('fake_backup'),
            ],
        );
        $logger->pushProcessor(new LogMessageProcessor());
        $this->makeUserContext('alice', $logger);

        $backupDir = self::$tmpPath . '/backup';
        if (false === is_dir($backupDir)) {
            mkdir($backupDir, 0o755, true);
        }

        $command = new BackupCommand(
            $this->createRuntimeMapper($logger),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );
        $tester = $this->makeTester($command);
        $status = $tester->execute([
            '--user' => 'alice',
            '--file' => 'custom.{user}.{backend}.json',
            '--no-compress' => true,
        ]);

        self::assertSame(BackupCommand::SUCCESS, $status);
        self::assertSame([
            [
                'backend' => 'fake_backup',
                'user' => 'alice',
                'dry_run' => false,
                'no_enhance' => false,
            ],
        ], FakeBackendClient::getCalls('backup'));

        $aliceFile = self::$tmpPath . '/backup/custom.alice.fake_backup.json';
        self::assertFileExists($aliceFile);
        self::assertStringContainsString('"user":"alice"', (string) file_get_contents($aliceFile));
        self::assertFileDoesNotExist(self::$tmpPath . '/backup/custom.main.fake_backup.json');
    }

    public function test_logfile_lifecycle(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_backup'));
        $logger->pushProcessor(new LogMessageProcessor());
        $this->migrateMainDb($logger);

        $logfile = self::$tmpPath . '/backup-log.txt';
        touch($logfile);

        $command = new BackupCommand(
            $this->createRuntimeMapper($logger),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );

        $status = $this->makeTester($command)->execute([
            '--logfile' => $logfile,
            '--no-compress' => true,
            '--jsonl' => true,
        ], [
            'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
        ]);

        self::assertSame(BackupCommand::SUCCESS, $status);

        $contents = file_get_contents($logfile);
        self::assertIsString($contents);

        $lines = array_values(array_filter(array_map(trim(...), explode(PHP_EOL, $contents))));
        self::assertNotEmpty($lines);
        self::assertTrue(JsonlFormatter::isJsonlRecord($lines[0]));

        $records = array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );
        $eventNames = array_column(array_column($records, 'fields'), 'event_name');

        self::assertContains('state.backup.started', $eventNames);
        self::assertContains('state.backup.user.started', $eventNames);
        self::assertContains('state.backup.completed', $eventNames);
    }

    public function test_invalid_user_returns_failure(): void
    {
        $logger = new Logger('test');
        $command = new BackupCommand(
            new DirectMapper($logger, $this->createDb($logger), $this->createArrayCache()),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );
        $tester = $this->makeTester($command);
        $status = $tester->execute([
            '--user' => 'ghost',
        ]);

        self::assertSame(BackupCommand::FAILURE, $status);
    }

    public function test_selected_backend_no_backup(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_backup'));
        $this->migrateMainDb($logger);

        $command = new BackupCommand(
            $this->createRuntimeMapper($logger),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );
        $tester = $this->makeTester($command);
        $status = $tester->execute([
            '--select-backend' => ['ghost'],
            '--no-compress' => true,
        ]);

        self::assertSame(BackupCommand::SUCCESS, $status);
        self::assertSame([], FakeBackendClient::getCalls('backup'));
        self::assertDirectoryDoesNotExist(self::$tmpPath . '/backup');
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
                ],
                'export' => [
                    'enabled' => true,
                ],
                'options' => [],
            ],
        ]);
        $handler = new TestHandler();
        $logger->setHandlers([$handler]);
        $logger->pushProcessor(new LogMessageProcessor());
        $this->migrateMainDb($logger);

        $command = new BackupCommand(
            $this->createRuntimeMapper($logger),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );

        $status = $this->makeTester($command)->execute(['--no-compress' => true]);

        self::assertSame(BackupCommand::SUCCESS, $status);

        $records = array_values(array_filter(
            $handler->getRecords(),
            static fn($record): bool => 'state.backup.backend.skipped' === ($record->context['event_name'] ?? null),
        ));

        self::assertCount(1, $records);
        self::assertSame('unsupported_type', $records[0]->context['reason']);
        self::assertSame('bad_backend', $records[0]->context['backend']);
        self::assertSame('bad', $records[0]->context['backend_type']);
    }

    public function test_metadata_only_backend_runs_backup(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_backup', [
            'import' => [
                'enabled' => false,
            ],
        ]));
        $this->migrateMainDb($logger);

        $command = new BackupCommand(
            $this->createRuntimeMapper($logger),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );

        $status = $this->makeTester($command)->execute([
            '--no-compress' => true,
        ]);

        self::assertSame(BackupCommand::SUCCESS, $status);
        self::assertSame([
            [
                'backend' => 'fake_backup',
                'user' => 'main',
                'dry_run' => false,
                'no_enhance' => false,
            ],
        ], FakeBackendClient::getCalls('backup'));
    }

    private function makeTester(BackupCommand $command): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('trace', null, InputOption::VALUE_NONE));
        $application->getDefinition()->addOption(new InputOption('jsonl', null, InputOption::VALUE_NONE));
        $application->addCommand($command);

        return new CommandTester($application->find(BackupCommand::ROUTE));
    }
}
