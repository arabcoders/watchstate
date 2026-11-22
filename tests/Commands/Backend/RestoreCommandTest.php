<?php

declare(strict_types=1);

namespace Tests\Commands\Backend;

use App\Commands\Backend\RestoreCommand;
use App\Libs\Container;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use App\Libs\LogSuppressor;
use App\Libs\QueueRequests;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Tests\Support\FakeBackendClient;
use Tests\Support\ProgressCommandTestSupport;
use Tests\Support\StateCommandTestSupport;

final class RestoreCommandTest extends TestCase
{
    use StateCommandTestSupport;
    use ProgressCommandTestSupport;

    public function test_fatal_resets_queue(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_restore'));
        FakeBackendClient::setExportError('main', 'fake_restore', new RuntimeException('restore export failed'));

        $backupFile = self::$tmpPath . '/backup.json';
        file_put_contents($backupFile, json_encode(
            [
                [
                    'type' => 'movie',
                    'watched' => 1,
                    'updated' => 1_700_000_000,
                    'title' => 'Fake Movie',
                    'year' => 2024,
                    'guids' => [
                        'guid_imdb' => 'tt1234567',
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        $queue = new QueueRequests();
        FakeBackendClient::setQueuedExportRequests('main', 'fake_restore', 1);

        $command = new RestoreCommand(
            $queue,
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );

        $status = $this->makeTester($command)->execute([
            '--assume-yes' => true,
            '--user' => 'main',
            '--select-backend' => 'fake_restore',
            'file' => $backupFile,
        ]);

        self::assertSame(RestoreCommand::FAILURE, $status);
        self::assertCount(1, FakeBackendClient::getCalls('export'));
        self::assertCount(0, $queue->getQueue());
    }

    public function test_item_failure(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_restore', [
            'export' => ['enabled' => false],
        ]));

        $backupFile = self::$tmpPath . '/backup.json';
        file_put_contents($backupFile, json_encode(
            [
                [
                    'type' => 'movie',
                    'watched' => 1,
                    'updated' => 1_700_000_000,
                    'title' => 'Fake Movie',
                    'year' => 2024,
                    'guids' => [
                        'guid_imdb' => 'tt1234567',
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        $queue = new QueueRequests();
        FakeBackendClient::setQueuedExportRequests('main', 'fake_restore', 1);

        $http = new HttpClient(new MockHttpClient(
            static fn(string $method, string $url, array $options) => throw new RuntimeException('removed item'),
        ));

        $command = new RestoreCommand(
            $queue,
            $logger,
            new LogSuppressor([]),
            $http,
        );

        $status = $this->makeTester($command)->execute([
            '--assume-yes' => true,
            '--user' => 'main',
            '--select-backend' => 'fake_restore',
            '--execute' => true,
            'file' => $backupFile,
        ]);

        self::assertSame(RestoreCommand::SUCCESS, $status);
        self::assertCount(1, FakeBackendClient::getCalls('export'));
        self::assertCount(0, $queue->getQueue());
    }

    /**
     * @param array<string,string> $expectedProgress
     */
    #[DataProvider('progressBackends')]
    public function test_progress(string $backend, string $progressPath, array $expectedProgress): void
    {
        $this->initTempApp();
        $this->seedTestServersConfig();

        $items = $backup = $writes = [];

        foreach ([1 => 90_000, 2 => null, 3 => 0, 4 => 120_000] as $id => $position) {
            $guid = 'tt123456' . $id;
            $title = 'Test Movie ' . $id;
            $backup[] =
                [
                    'type' => 'movie',
                    'watched' => 0,
                    'updated' => 1_700_000_000,
                    'title' => $title,
                    'year' => 2024,
                    'guids' => ['guid_imdb' => $guid],
                ] + (null === $position ? [] : ['progress' => $position]);

            if (4 === $id) {
                continue;
            }

            $items[$id] = $this->progressMovie($backend, $id, 180_000);
        }

        $backupFile = self::$tmpPath . '/backup.json';
        file_put_contents($backupFile, json_encode($backup, JSON_THROW_ON_ERROR));

        $http = $this->progressHttp($backend, $items, $writes);

        $logs = new TestHandler();
        $logger = new Logger('test', [$logs]);
        Container::getContainer()->add(LoggerInterface::class, fn() => $logger, overwrite: true);
        $command = new RestoreCommand(new QueueRequests(), $logger, new LogSuppressor([]), $http);
        $tester = $this->makeTester($command);
        $input = [
            '--assume-yes' => true,
            '--user' => 'main',
            '--select-backend' => 'test_' . $backend,
            '--sync-requests' => true,
            'file' => $backupFile,
        ];

        self::assertSame(RestoreCommand::SUCCESS, $tester->execute($input + ['--execute' => true]));
        self::assertSame([], $writes);

        $input['--restore-watch-progress'] = true;
        self::assertSame(RestoreCommand::SUCCESS, $tester->execute($input));
        self::assertTrue($logs->hasRecordThatPasses(
            static fn(LogRecord $record): bool => (
                str_contains($record->message, 'No progress was written.')
                && 1 === ($record->context['stats']['matched'] ?? null)
            ),
            Level::Notice,
        ));
        self::assertSame([], $writes);

        self::assertSame(RestoreCommand::SUCCESS, $tester->execute($input + ['--execute' => true]));
        self::assertFalse($logs->hasRecords(Level::Error));
        $this->assertProgressWrite($writes, $progressPath, $expectedProgress);
    }

    private function makeTester(RestoreCommand $command): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('trace', null, InputOption::VALUE_NONE));
        $application->addCommand($command);

        return new CommandTester($application->find(RestoreCommand::ROUTE));
    }
}
