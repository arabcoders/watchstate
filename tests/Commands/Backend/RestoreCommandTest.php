<?php

declare(strict_types=1);

namespace Tests\Commands\Backend;

use App\Commands\Backend\RestoreCommand;
use App\Libs\Config;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\LogSuppressor;
use App\Libs\QueueRequests;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Tests\Support\StateCommandTestSupport;

final class RestoreCommandTest extends TestCase
{
    use StateCommandTestSupport;

    public function test_logs_restore_events(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_restore', [
            'import' => [
                'enabled' => false,
            ],
            'export' => [
                'enabled' => false,
            ],
        ]));
        $handler = new TestHandler();
        $logger->setHandlers([$handler]);
        $logger->pushProcessor(new LogMessageProcessor());
        $this->migrateMainDb($logger);

        $file = self::$tmpPath . '/backup/restore.json';
        mkdir(dirname($file), 0o755, true);
        $entity = require __DIR__ . '/../../Fixtures/MovieEntity.php';
        file_put_contents($file, json_encode([$entity], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $command = new RestoreCommand(
            new QueueRequests(),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );

        $status = $this->makeTester($command)->execute([
            '--select-backend' => 'fake_restore',
            '--user' => 'main',
            '--ignore' => true,
            'file' => $file,
        ]);

        self::assertSame(RestoreCommand::SUCCESS, $status);

        $records = $handler->getRecords();

        $byEvent = [];
        foreach ($records as $record) {
            $eventName = $record->context['event_name'] ?? null;
            if (is_string($eventName)) {
                $byEvent[$eventName] = $record;
            }
        }

        self::assertSame('restore.json', basename($byEvent['backend.restore.data.loading']->context['path']));
        self::assertSame(1, $byEvent['backend.restore.data.loaded']->context['item_count']);
        self::assertSame(0, $byEvent['backend.restore.compare.completed']->context['change_count']);
        self::assertSame('dry_run', $byEvent['backend.restore.cancelled']->context['reason']);
    }

    private function makeTester(RestoreCommand $command): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('trace', null, InputOption::VALUE_NONE));
        $application->addCommand($command);

        return new CommandTester($application->find(RestoreCommand::ROUTE));
    }
}
