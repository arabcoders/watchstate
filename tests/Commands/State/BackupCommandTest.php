<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Commands\State\BackupCommand;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\TestCase;
use Monolog\Logger;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class BackupCommandTest extends TestCase
{
    public function test_signature(): void
    {
        $command = new BackupCommand(new DirectMapper(new Logger('test'), $this->createDb(), $this->createCache()), new Logger('test'), new LogSuppressor([]), $this->createStub(iHttp::class));

        self::assertSame('state:backup', $command->getName());
        self::assertTrue($command->getDefinition()->hasOption('user'));
    }

    public function test_invalid_user_returns_failure(): void
    {
        $command = new BackupCommand(new DirectMapper(new Logger('test'), $this->createDb(), $this->createCache()), new Logger('test'), new LogSuppressor([]), $this->createStub(iHttp::class));
        $tester = $this->makeTester($command);
        $status = $tester->execute([
            '--user' => 'ghost',
        ]);

        self::assertSame(BackupCommand::FAILURE, $status);
    }

    private function makeTester(BackupCommand $command): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('trace', null, InputOption::VALUE_NONE));
        $application->addCommand($command);

        return new CommandTester($application->find(BackupCommand::ROUTE));
    }

    private function createCache(): iCache
    {
        return new Psr16Cache(new ArrayAdapter());
    }
}
