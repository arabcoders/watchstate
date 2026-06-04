<?php

declare(strict_types=1);

namespace Tests\Cli;

use App\Cli;
use App\Cli\CommandLoader;
use App\Command;
use App\Libs\Config;
use App\Libs\Extends\PSRContainer;
use App\Libs\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::init(['name' => 'WatchState']);
    }

    public function test_alias_hidden_resolves(): void
    {
        $command = new class() extends Command {
            protected function configure(): void
            {
                $this
                    ->setName('events:view')
                    ->setDescription('Show a detailed event entry.');
            }
        };

        $container = new class($command) implements ContainerInterface {
            public function __construct(
                private readonly Command $command,
            ) {}

            public function get(string $id): object
            {
                return $this->command;
            }

            public function has(string $id): bool
            {
                return 'events.view' === $id;
            }
        };

        $app = new Cli(new PSRContainer());
        $app->setCommandLoader(
            new CommandLoader(
                $container,
                [
                    'events:view' => 'events.view',
                ],
                [
                    'events:view' => ['events:show'],
                ],
            ),
        );

        self::assertTrue($app->has('events:view'));
        self::assertTrue($app->has('events:show'));
        self::assertSame('events:view', $app->find('events:show')->getName());
        self::assertSame(['events:view'], array_keys($app->all('events')));

        $tester = new CommandTester($app->find('list'));
        $status = $tester->execute(['namespace' => 'events']);

        self::assertSame(0, $status);
        self::assertStringContainsString('events:view', $tester->getDisplay());
        self::assertStringContainsString('[events:show] Show a detailed event entry.', $tester->getDisplay());
        self::assertStringNotContainsString("\n  events:show", $tester->getDisplay());
    }
}
