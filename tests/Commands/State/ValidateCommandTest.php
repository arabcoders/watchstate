<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Commands\State\ValidateCommand;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\TestCase;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ValidateCommandTest extends TestCase
{
    public function test_signature(): void
    {
        $command = new ValidateCommand($this->createMockMapper(), new Logger('test'), new LogSuppressor([]));

        self::assertSame('state:validate', $command->getName());
        self::assertTrue($command->getDefinition()->hasOption('user'));
    }

    public function test_invalid_user_returns_failure(): void
    {
        $command = new ValidateCommand($this->createMockMapper(), new Logger('test'), new LogSuppressor([]));
        $tester = $this->makeTester($command);
        $status = $tester->execute([
            '--user' => 'ghost',
        ]);

        self::assertSame(ValidateCommand::FAILURE, $status);
        self::assertStringContainsString("User 'ghost' not found.", $tester->getDisplay());
    }

    private function makeTester(ValidateCommand $command): CommandTester
    {
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find(ValidateCommand::ROUTE));
    }

    private function createMockMapper(): iImport
    {
        return $this->createStub(iImport::class);
    }
}
