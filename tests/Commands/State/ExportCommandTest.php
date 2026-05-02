<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Commands\State\ExportCommand;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\QueueRequests;
use App\Libs\TestCase;
use Monolog\Logger;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class ExportCommandTest extends TestCase
{
    public function test_signature(): void
    {
        $command = new ExportCommand(new DirectMapper(new Logger('test'), $this->createDb(), $this->createCache()), new QueueRequests(), new Logger('test'), new LogSuppressor([]), $this->createStub(iHttp::class));

        self::assertSame('state:export', $command->getName());
        self::assertTrue($command->getDefinition()->hasOption('user'));
    }

    public function test_invalid_user_returns_failure(): void
    {
        $command = new ExportCommand(new DirectMapper(new Logger('test'), $this->createDb(), $this->createCache()), new QueueRequests(), new Logger('test'), new LogSuppressor([]), $this->createStub(iHttp::class));
        $tester = $this->makeTester($command);
        $status = $tester->execute([
            '--user' => 'ghost',
        ]);

        self::assertSame(ExportCommand::FAILURE, $status);
        self::assertStringContainsString("User 'ghost' not found.", $tester->getDisplay());
    }

    private function makeTester(ExportCommand $command): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('trace', null, InputOption::VALUE_NONE));
        $application->addCommand($command);

        return new CommandTester($application->find(ExportCommand::ROUTE));
    }

    private function createCache(): iCache
    {
        return new Psr16Cache(new ArrayAdapter());
    }
}
