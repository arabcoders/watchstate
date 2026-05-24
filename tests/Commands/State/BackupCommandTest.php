<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Commands\State\BackupCommand;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\TestCase;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
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

    public function test_metadata_only_backend_runs_backup(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_backup', [
            'import' => [
                'enabled' => false,
            ],
        ]));
        $this->migrateMainDb($logger);
        mkdir(self::$tmpPath . '/backup', 0o755, true);

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
        $application->addCommand($command);

        return new CommandTester($application->find(BackupCommand::ROUTE));
    }
}
