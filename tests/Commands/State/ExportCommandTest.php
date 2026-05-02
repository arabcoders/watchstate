<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Commands\State\ExportCommand;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\LogSuppressor;
use App\Libs\QueueRequests;
use App\Libs\TestCase;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
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

        $aliceContext = $this->makeUserContext('alice', $logger);
        $entity = require __DIR__ . '/../../Fixtures/MovieEntity.php';
        $entity[iState::COLUMN_VIA] = 'fake_export';
        $entity[iState::COLUMN_UPDATED] = 1_700_000_200;
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
    }

    private function makeTester(ExportCommand $command): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('trace', null, InputOption::VALUE_NONE));
        $application->addCommand($command);

        return new CommandTester($application->find(ExportCommand::ROUTE));
    }
}
