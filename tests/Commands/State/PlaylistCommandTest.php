<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Backends\Common\ClientInterface as iClient;
use App\Commands\State\PlaylistCommand;
use App\Libs\ConfigFile;
use App\Libs\Database\DBLayer;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Playlists\PlaylistSyncService;
use App\Libs\UserContext;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use App\Libs\TestCase;

final class PlaylistCommandTest extends TestCase
{
    public function test_renders_playlist_sync_summary(): void
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
        self::assertStringContainsString('test_plex', $tester->getDisplay());
        self::assertStringContainsString('Playlists', $tester->getDisplay());
    }

    public function test_skips_backend_when_import_and_export_are_disabled(): void
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
        self::assertStringContainsString('No matching backends produced syncable playlists.', $tester->getDisplay());
    }

    public function test_selected_disabled_backend_is_still_passed_with_direction_sets_empty(): void
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

    public function test_logfile_writes_playlist_lifecycle_logs(): void
    {
        $service = $this->makeServiceMock();
        $service
            ->expects(self::once())
            ->method('sync')
            ->willReturn([]);

        $client = $this->createStub(iClient::class);
        $client->method('getName')->willReturn('test_plex');
        $client->method('getType')->willReturn('plex');

        $logfile = tempnam(sys_get_temp_dir(), 'playlist-log-');
        self::assertNotFalse($logfile);

        try {
            $tester = $this->makeTester($service, $client);
            $status = $tester->execute(['--logfile' => $logfile], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

            self::assertSame(PlaylistCommand::SUCCESS, $status);

            $contents = file_get_contents($logfile);
            self::assertIsString($contents);
            self::assertStringContainsString('Starting playlist sync process', $contents);
            self::assertStringContainsString('Playlist sync process completed', $contents);
        } finally {
            @unlink($logfile);
        }
    }

    /**
     * @return PlaylistSyncService&MockObject
     */
    private function makeServiceMock(): PlaylistSyncService
    {
        return $this->createMock(PlaylistSyncService::class);
    }

    private function makeTester(PlaylistSyncService $service, iClient $client, string $backendName = 'test_plex'): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('output', 'o', InputOption::VALUE_REQUIRED, '', 'table'));
        $application->getDefinition()->addOption(new InputOption('trace', null, InputOption::VALUE_NONE));
        $application->addCommand($this->makeCommand($service, $client, $backendName));

        return new CommandTester($application->find(PlaylistCommand::ROUTE));
    }

    private function makeCommand(PlaylistSyncService $service, iClient $client, string $backendName = 'test_plex'): PlaylistCommand
    {
        $logger = new Logger('test');
        $db = new PDOAdapter($logger, new DBLayer(new PDO('sqlite::memory:')));
        $db->migrations('up');
        $cache = new Psr16Cache(new ArrayAdapter());

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

        return new class($service, $userContext, $logger, $client, $backendName) extends PlaylistCommand {
            public function __construct(
                PlaylistSyncService $service,
                private readonly UserContext $userContext,
                Logger $logger,
                private readonly iClient $client,
                private readonly string $backendName,
            ) {
                parent::__construct(
                    $service,
                    new DirectMapper($logger, $this->userContext->db, $this->userContext->cache),
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

            protected function getUsers(array $dbOpts = []): array
            {
                return ['main' => $this->userContext];
            }
        };
    }
}
