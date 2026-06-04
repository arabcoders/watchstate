<?php

declare(strict_types=1);

namespace Tests\Commands\Backend;

use App\Commands\Backend\RestoreCommand;
use App\Libs\Extends\HttpClient;
use App\Libs\LogSuppressor;
use App\Libs\QueueRequests;
use App\Libs\TestCase;
use App\Libs\Extends\MockHttpClient;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Tests\Support\FakeBackendClient;
use Tests\Support\StateCommandTestSupport;

final class RestoreCommandTest extends TestCase
{
    use StateCommandTestSupport;

    public function test_fatal_resets_queue(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_restore'));
        FakeBackendClient::setExportError('main', 'fake_restore', new \RuntimeException('restore export failed'));

        $backupFile = self::$tmpPath . '/backup.json';
        file_put_contents($backupFile, json_encode([
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
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

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

    public function test_item_failures_keep_success(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_restore'));

        $backupFile = self::$tmpPath . '/backup.json';
        file_put_contents($backupFile, json_encode([
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
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $queue = new QueueRequests();
        FakeBackendClient::setQueuedExportRequests('main', 'fake_restore', 1);

        $http = new HttpClient(new MockHttpClient(
            static fn(string $method, string $url, array $options) => throw new \RuntimeException('removed item'),
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

    public function test_progress_flag_calls_progress(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_restore'));

        $backupFile = self::$tmpPath . '/backup.json';
        file_put_contents($backupFile, json_encode([
            [
                'type' => 'movie',
                'watched' => 0,
                'updated' => 1_700_000_000,
                'title' => 'Fake Movie',
                'year' => 2024,
                'progress' => 90000,
                'guids' => [
                    'guid_imdb' => 'tt1234567',
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $queue = new QueueRequests();

        $command = new RestoreCommand(
            $queue,
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );

        $status = $this->makeTester($command)->execute([
            '--assume-yes' => true,
            '--restore-watch-progress' => true,
            '--user' => 'main',
            '--select-backend' => 'fake_restore',
            'file' => $backupFile,
        ]);

        self::assertSame(RestoreCommand::SUCCESS, $status);
        self::assertCount(1, FakeBackendClient::getCalls('export'));
        self::assertSame([
            [
                'backend' => 'fake_restore',
                'user' => 'main',
                'count' => 1,
                'after' => null,
            ],
        ], FakeBackendClient::getCalls('progress'));
    }

    private function makeTester(RestoreCommand $command): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('trace', null, InputOption::VALUE_NONE));
        $application->addCommand($command);

        return new CommandTester($application->find(RestoreCommand::ROUTE));
    }
}
