<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Backends\Common\ClientInterface as iClient;
use App\Commands\State\PlaylistCommand;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Playlists\PlaylistSyncService;
use App\Libs\TestCase;
use App\Libs\UserContext;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class PlaylistCommandTest extends TestCase
{
    public function test_summary(): void
    {
        $service = $this->makeServiceMock();
        $service
            ->expects(self::once())
            ->method('sync')
            ->with(
                self::isInstanceOf(UserContext::class),
                self::callback(function (array $clients): bool {
                    self::assertArrayHasKey('test_plex', $clients);
                    self::assertInstanceOf(iClient::class, $clients['test_plex']);

                    return true;
                }),
                [
                    'DRY_RUN' => false,
                    'FORCE_FULL' => false,
                    'source_backends' => ['test_plex'],
                    'target_backends' => ['test_plex'],
                ],
            )
            ->willReturn([
                'test_plex' => [
                    'playlists' => 1,
                    'items' => 2,
                    'added' => 1,
                    'updated' => 0,
                    'removed' => 0,
                ],
            ]);

        $client = $this->createStub(iClient::class);
        $client->method('getName')->willReturn('test_plex');
        $client->method('getType')->willReturn('plex');

        $tester = $this->makeTester($service, $client);
        $status = $tester->execute([]);

        self::assertSame(PlaylistCommand::SUCCESS, $status);
    }

    public function test_skips_disabled_backend(): void
    {
        $service = $this->makeServiceMock();
        $service
            ->expects(self::once())
            ->method('sync')
            ->with(
                self::isInstanceOf(UserContext::class),
                self::callback(function (array $clients): bool {
                    self::assertArrayHasKey('test_disabled', $clients);

                    return true;
                }),
                [
                    'DRY_RUN' => false,
                    'FORCE_FULL' => false,
                    'source_backends' => [],
                    'target_backends' => [],
                ],
            )
            ->willReturn([]);

        $tester = $this->makeTester($service, $this->createStub(iClient::class), 'test_disabled');
        $status = $tester->execute([]);

        self::assertSame(PlaylistCommand::SUCCESS, $status);
    }

    public function test_disabled_passed_empty(): void
    {
        $service = $this->makeServiceMock();
        $service
            ->expects(self::once())
            ->method('sync')
            ->with(
                self::isInstanceOf(UserContext::class),
                self::callback(function (array $clients): bool {
                    self::assertArrayHasKey('test_disabled', $clients);

                    return true;
                }),
                [
                    'DRY_RUN' => false,
                    'FORCE_FULL' => false,
                    'source_backends' => [],
                    'target_backends' => [],
                ],
            )
            ->willReturn([]);

        $client = $this->createStub(iClient::class);
        $client->method('getName')->willReturn('test_disabled');
        $client->method('getType')->willReturn('plex');

        $tester = $this->makeTester($service, $client, 'test_disabled');
        $status = $tester->execute(['--select-backend' => ['test_disabled']]);

        self::assertSame(PlaylistCommand::SUCCESS, $status);
    }

    public function test_logfile_lifecycle(): void
    {
        $this->initTempDir();

        $service = $this->makeServiceMock();
        $service
            ->expects(self::once())
            ->method('sync')
            ->willReturn([]);

        $client = $this->createStub(iClient::class);
        $client->method('getName')->willReturn('test_plex');
        $client->method('getType')->willReturn('plex');

        $logfile = self::$tmpPath . '/playlist-log.txt';
        touch($logfile);

        $tester = $this->makeTester($service, $client);
        $status = $tester->execute(['--logfile' => $logfile], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertSame(PlaylistCommand::SUCCESS, $status);

        $contents = file_get_contents($logfile);
        self::assertIsString($contents);
        self::assertStringContainsString('Starting playlist sync process', $contents);
        self::assertStringContainsString('Playlist sync process completed', $contents);
    }

    public function test_selected_user_only(): void
    {
        $service = $this->makeServiceMock();
        $service
            ->expects(self::once())
            ->method('sync')
            ->with(
                self::callback(static fn(UserContext $userContext): bool => 'alice' === $userContext->name),
                self::isArray(),
                self::isArray(),
            )
            ->willReturn([]);

        $client = $this->createStub(iClient::class);
        $client->method('getName')->willReturn('test_plex');
        $client->method('getType')->willReturn('plex');

        $tester = $this->makeTester($service, $client, 'test_plex', ['main', 'alice']);
        $status = $tester->execute([
            '--user' => 'alice',
        ]);

        self::assertSame(PlaylistCommand::SUCCESS, $status);
    }

    public function test_invalid_user(): void
    {
        $service = $this->makeServiceMock();
        $service->expects(self::never())->method('sync');

        $tester = $this->makeTester($service, $this->createStub(iClient::class));
        $status = $tester->execute([
            '--user' => 'ghost',
        ]);

        self::assertSame(PlaylistCommand::FAILURE, $status);
        self::assertStringContainsString("User 'ghost' not found.", $tester->getDisplay());
    }

    /**
     * @return PlaylistSyncService&MockObject
     */
    private function makeServiceMock(): PlaylistSyncService
    {
        return $this->createMock(PlaylistSyncService::class);
    }

    private function makeTester(
        PlaylistSyncService $service,
        iClient $client,
        string $backendName = 'test_plex',
        array $userNames = ['main'],
    ): CommandTester {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('output', 'o', InputOption::VALUE_REQUIRED, '', 'table'));
        $application->getDefinition()->addOption(new InputOption('trace', null, InputOption::VALUE_NONE));
        $application->addCommand($this->makeCommand($service, $client, $backendName, $userNames));

        return new CommandTester($application->find(PlaylistCommand::ROUTE));
    }

    private function makeCommand(
        PlaylistSyncService $service,
        iClient $client,
        string $backendName = 'test_plex',
        array $userNames = ['main'],
    ): PlaylistCommand {
        $this->initTempApp();
        $this->seedTestServersConfig();

        foreach ($userNames as $name) {
            if ('main' === $name) {
                continue;
            }

            $this->seedTestServersConfig($name);
        }

        $logger = new Logger('test');
        $mapper = new DirectMapper($logger, $this->createDb($logger), new Psr16Cache(new ArrayAdapter()));

        return new class($service, $mapper, $logger, $client, $backendName) extends PlaylistCommand {
            public function __construct(
                PlaylistSyncService $service,
                DirectMapper $mapper,
                Logger $logger,
                private readonly iClient $client,
                private readonly string $backendName,
            ) {
                parent::__construct(
                    $service,
                    $mapper,
                    $logger,
                    new LogSuppressor([]),
                );
            }

            protected function getClients(
                UserContext $userContext,
                array $selected = [],
                bool $exclude = false,
                bool $trace = false,
            ): array {
                $selected = array_values(array_filter(array_map(trim(...), $selected), static fn($item) => '' !== $item));

                if ($selected !== [] && false === in_array($this->backendName, $selected, true)) {
                    return [];
                }

                return [$this->backendName => $this->client];
            }
        };
    }
}
