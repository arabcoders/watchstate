<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Commands\State\ExportCommand;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\QueueRequests;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Tests\Support\FakeBackendClient;
use Tests\Support\ProgressCommandTestSupport;
use Tests\Support\StateCommandTestSupport;

final class ExportCommandTest extends TestCase
{
    use StateCommandTestSupport;
    use ProgressCommandTestSupport;

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
        self::assertSame(
            [
                [
                    'backend' => 'fake_export',
                    'user' => 'alice',
                    'after' => null,
                ],
            ],
            FakeBackendClient::getCalls('export'),
        );
        self::assertSame([], FakeBackendClient::getCalls('push'));

        $aliceConfig = Yaml::parseFile(self::$tmpPath . '/users/alice/servers.yaml');
        self::assertGreaterThan(1_700_000_100, ag($aliceConfig, 'fake_export.export.lastSync', 0));
    }

    public function test_invalid_user_returns_failure(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

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
        self::assertTrue(
            $this->hasRecordWith(
                $handler,
                Level::Error,
                ['operation' => 'export.resolve_users'],
            ),
            'Assert resolve-users error logged with operation token.',
        );
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
        $entity[iState::COLUMN_CREATED_AT] = 1_700_000_000;
        $entity[iState::COLUMN_UPDATED_AT] = 1_700_000_200;
        $entity[iState::COLUMN_META_DATA] = [
            'fake_export' => [
                iState::COLUMN_ID => 901,
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_WATCHED => 0,
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
        self::assertSame(
            [
                [
                    'backend' => 'fake_export',
                    'user' => 'alice',
                    'count' => 1,
                    'after' => null,
                ],
            ],
            FakeBackendClient::getCalls('push'),
        );
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
        self::assertSame(
            [
                [
                    'backend' => 'fake_export',
                    'user' => 'alice',
                    'count' => 1,
                    'after' => null,
                ],
            ],
            FakeBackendClient::getCalls('push'),
        );

        $aliceConfig = Yaml::parseFile(self::$tmpPath . '/users/alice/servers.yaml');
        self::assertSame(1_700_000_150, ag($aliceConfig, 'fake_export.export.lastSync'));
    }

    public function test_old_change_uses_push(): void
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
                iState::COLUMN_WATCHED => 0,
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
        self::assertSame(
            [
                [
                    'backend' => 'fake_export',
                    'user' => 'alice',
                    'count' => 1,
                    'after' => null,
                ],
            ],
            FakeBackendClient::getCalls('push'),
        );

        $aliceConfig = Yaml::parseFile(self::$tmpPath . '/users/alice/servers.yaml');
        self::assertSame(1_700_000_150, ag($aliceConfig, 'fake_export.export.lastSync'));
    }

    /**
     * @param array<string,string> $expectedProgress
     */
    #[DataProvider('progressModes')]
    public function test_progress(string $backend, string $progressPath, array $expectedProgress, string $mode): void
    {
        $this->initTempApp();
        $this->seedTestServersConfig();

        $logs = new TestHandler();
        $logger = new Logger('test', [$logs]);
        Container::getContainer()->add(LoggerInterface::class, fn() => $logger, overwrite: true);

        $source = 'emby' === $backend ? 'test_plex' : 'test_emby';
        $target = 'test_' . $backend;
        $context = $this->makeUserContext('main', $logger);
        $cursor = 1_720_000_000;
        $full = 'full' === $mode || 'ignore-date' === $mode;
        $ignoreDate = 'ignore-date' === $mode;
        $context->config->set($target . '.export.lastSync', $cursor);
        if ('compare' === $mode) {
            $context->config->set($target . '.import.lastSync', null);
        }
        $context->config->persist();

        foreach (range(1, $full ? 6 : 7) as $id) {
            $via = 1 === $id ? $target : $source;
            $metadata = [
                $via => [
                    iState::COLUMN_ID => 'source-' . $id,
                    iState::COLUMN_META_DATA_PROGRESS => 6 === $id ? '0' : '90000',
                ],
            ];
            if ('push' === $mode) {
                $metadata[$target][iState::COLUMN_ID] = 'plex' === $backend ? 100 + $id : 'item-' . $id;
            }

            $context->db->insert(StateEntity::fromArray([
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_TITLE => 'Test Movie ' . $id,
                iState::COLUMN_YEAR => 2024,
                iState::COLUMN_VIA => $via,
                iState::COLUMN_WATCHED => 6 === $id ? 1 : 0,
                iState::COLUMN_UPDATED => 1_700_000_000,
                iState::COLUMN_CREATED_AT => 1_700_000_000,
                iState::COLUMN_UPDATED_AT => $full || 7 === $id ? $cursor : $cursor + 1,
                iState::COLUMN_GUIDS => ['guid_imdb' => 'tt123456' . $id],
                iState::COLUMN_META_DATA => $metadata,
                iState::COLUMN_EXTRA => [$via => [iState::COLUMN_EXTRA_DATE => 1_704_067_400]],
            ]));
        }

        $writes = [];
        $http = $this->progressHttp(
            $backend,
            [
                1 => $this->progressMovie($backend, 1, 0),
                2 => $this->progressMovie($backend, 2, 90_000),
                3 => $this->progressMovie($backend, 3, 180_000, 1_704_067_500),
                4 => $this->progressMovie($backend, 4, 0),
                5 => $this->progressMovie($backend, 5, 60_000, 1_704_067_300),
                6 => $this->progressMovie($backend, 6, 0, 1_704_067_300, played: true),
            ] + ($full ? [] : [7 => $this->progressMovie($backend, 7, 0)]),
            $writes,
            playing: 4,
        );

        $tester = $this->makeTester(new ExportCommand(
            $this->createRuntimeMapper($logger),
            new QueueRequests(),
            $logger,
            new LogSuppressor([]),
            $http,
        ));
        $input = ['--user' => 'main', '--select-backend' => [$target], '--sync-requests' => true];
        if (true === $full) {
            $input['--force-full'] = true;
            self::assertSame(ExportCommand::SUCCESS, $tester->execute($input));
            self::assertSame([], $writes);
        }

        $input['--with-progress'] = true;
        if (true === $ignoreDate) {
            $input['--ignore-date'] = true;
        }
        self::assertSame(ExportCommand::SUCCESS, $tester->execute($input + ['--dry-run' => true]));
        self::assertSame([], $writes);

        self::assertSame(ExportCommand::SUCCESS, $tester->execute($input));
        self::assertFalse($logs->hasErrorRecords());
        self::assertSame('push' === $mode, $logs->hasNoticeThatContains('Push mode started'));
        self::assertSame('push' !== $mode, $logs->hasNoticeThatContains('Export mode started'));

        $expectedItems = $ignoreDate ? [1, 3, 5] : [1, 5];
        self::assertCount(count($expectedItems), $writes);
        foreach ($expectedItems as $index => $id) {
            $path = str_replace('/item-1/', '/item-' . $id . '/', $progressPath);
            $payload = array_key_exists('ratingKey', $expectedProgress)
                ? array_replace($expectedProgress, ['ratingKey' => (string) (100 + $id)])
                : $expectedProgress;
            $this->assertProgressWrite([$writes[$index]], $path, $payload);
        }
    }

    /**
     * @return array<string,array{string,string,array<string,string>,string}>
     */
    public static function progressModes(): array
    {
        $cases = [];
        foreach (self::progressBackends() as $backend => $fixture) {
            foreach (['full', 'push', 'compare', 'ignore-date'] as $mode) {
                $cases[$backend . ' ' . $mode] = [...$fixture, $mode];
            }
        }

        return $cases;
    }

    private function makeTester(ExportCommand $command): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('trace', null, InputOption::VALUE_NONE));
        $application->addCommand($command);

        return new CommandTester($application->find(ExportCommand::ROUTE));
    }
}
