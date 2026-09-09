<?php

declare(strict_types=1);

namespace Tests\Commands\System;

use App\Commands\System\RoutesCommand;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;

final class RoutesCommandTest extends TestCase
{
    public function test_list(): void
    {
        $tester = $this->makeTester();
        $status = $tester->execute(['--list' => true]);

        self::assertSame(RoutesCommand::SUCCESS, $status);
        self::assertSame(
            "1. /health\n   methods: GET\n   callable: App\\API\\Health::__invoke\n",
            $tester->getDisplay(),
        );
    }

    public function test_json(): void
    {
        $tester = $this->makeTester();
        $status = $tester->execute(['--list' => true, '--json' => true]);

        self::assertSame(RoutesCommand::SUCCESS, $status);
        self::assertSame('/health', json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)[0]['path']);
    }

    public function test_filter_priority(): void
    {
        $routes = [
            $this->route('/health'),
            $this->route('/health/details'),
        ];

        $tester = $this->makeTester($routes);
        $tester->execute(['--list' => true, '--filter' => '/health', '--json' => true]);
        self::assertSame(['/health'], array_column(
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
            'path',
        ));

        $tester = $this->makeTester($routes);
        $tester->execute(['--list' => true, '--filter' => 'health', '--json' => true]);
        self::assertSame(['/health', '/health/details'], array_column(
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
            'path',
        ));

        $tester = $this->makeTester($routes);
        $tester->execute(['--list' => true, '--filter' => '*details', '--json' => true]);
        self::assertSame(['/health/details'], array_column(
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
            'path',
        ));
    }

    private function makeTester(?array $routes = null): CommandTester
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache
            ->method('get')
            ->willReturn($routes ?? [$this->route('/health')]);

        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('json', null, InputOption::VALUE_NONE));
        $application->addCommand(new RoutesCommand($cache));

        return new CommandTester($application->find(RoutesCommand::ROUTE));
    }

    private function route(string $path): array
    {
        return [
            'host' => 'localhost',
            'path' => $path,
            'method' => ['GET'],
            'callable' => ['App\\API\\Health', '__invoke'],
        ];
    }
}
