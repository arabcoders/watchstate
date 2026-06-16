<?php

declare(strict_types=1);

namespace Tests\Commands\System;

use App\Commands\System\PruneCommand;
use App\Libs\Attributes\Scanner\Item;
use App\Libs\Attributes\Scanner\Target;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\fixtures\Commands\System\FixturePruneCommand;
use Tests\fixtures\Commands\System\TestablePruneCommand;

final class PruneCommandTest extends TestCase
{
    public static array $calls = [];

    /**
     * @return array<string, array{name:string,cron:?string,desc:?string,enabled:bool,callable:mixed,item:Item,target:Target}>
     */
    public static function prunersFixture(): array
    {
        return [
            'always_pruner' => [
                'name' => 'always_pruner',
                'cron' => null,
                'desc' => 'Runs on every execute.',
                'enabled' => true,
                'callable' => static function (bool $execute): void {
                    self::$calls[] = ['always_pruner', $execute];
                },
                'item' => new Item(
                    Target::IS_CLASS,
                    'App\\Libs\\Attributes\\Cli\\Prune',
                    static function (bool $execute): void {
                        self::$calls[] = ['always_pruner', $execute];
                    },
                    ['name' => 'always_pruner', 'desc' => 'Runs on every execute.', 'enabled' => true],
                ),
                'target' => Target::IS_CLASS,
            ],
            'events_remover' => [
                'name' => 'events_remover',
                'cron' => '0 5 * * *',
                'desc' => 'Remove old events.',
                'enabled' => true,
                'callable' => static function (bool $execute): void {
                    self::$calls[] = ['events_remover', $execute];
                },
                'item' => new Item(
                    Target::IS_CLASS,
                    'App\\Libs\\Attributes\\Cli\\Prune',
                    static function (bool $execute): void {
                        self::$calls[] = ['events_remover', $execute];
                    },
                    ['name' => 'events_remover', 'cron' => '0 5 * * *', 'desc' => 'Remove old events.', 'enabled' => true],
                ),
                'target' => Target::IS_CLASS,
            ],
            'logs_remover' => [
                'name' => 'logs_remover',
                'cron' => '*/5 * * * *',
                'desc' => 'Remove old logs.',
                'enabled' => true,
                'callable' => static function (bool $execute): void {
                    self::$calls[] = ['logs_remover', $execute];
                },
                'item' => new Item(
                    Target::IS_CLASS,
                    'App\\Libs\\Attributes\\Cli\\Prune',
                    static function (bool $execute): void {
                        self::$calls[] = ['logs_remover', $execute];
                    },
                    ['name' => 'logs_remover', 'cron' => '*/5 * * * *', 'desc' => 'Remove old logs.', 'enabled' => true],
                ),
                'target' => Target::IS_CLASS,
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->initContainer();

        self::$calls = [];
        TestablePruneCommand::$due = [];
        TestablePruneCommand::$forced = false;
        TestablePruneCommand::$paths = [];
    }

    public function test_list(): void
    {
        TestablePruneCommand::$paths = [ROOT_PATH . '/tests/fixtures/Prune'];
        $cmd = new TestablePruneCommand();

        $output = new BufferedOutput();
        $status = $cmd->run(new ArrayInput([]), $output);

        $this->assertSame(PruneCommand::SUCCESS, $status);

        $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        $byName = [];
        foreach ($payload as $row) {
            $byName[$row['name']] = $row;
        }

        $this->assertGreaterThanOrEqual(3, count($payload));
        $this->assertSame('0 5 * * *', $byName['another_pruner']['cron']);
        $this->assertSame('Another test pruner.', $byName['another_pruner']['description']);
        $this->assertSame('* * * * *', $byName['fake_pruner']['cron']);
        $this->assertArrayHasKey('next', $byName['fake_pruner']);
        $this->assertSame('*/15 * * * *', $byName['method_pruner']['cron']);
    }

    public function test_list_nocache(): void
    {
        TestablePruneCommand::$paths = [ROOT_PATH . '/tests/fixtures/Prune'];
        $cmd = new TestablePruneCommand();

        $status = $cmd->run(new ArrayInput([
            '--no-cache' => true,
        ]), new BufferedOutput());

        $this->assertSame(PruneCommand::SUCCESS, $status);
        $this->assertTrue(TestablePruneCommand::$forced);
    }

    public function test_run_refresh(): void
    {
        TestablePruneCommand::$paths = [ROOT_PATH . '/tests/fixtures/Prune'];
        $cmd = new TestablePruneCommand();

        $status = $cmd->run(new ArrayInput([
            '--run' => true,
            '--refresh-cache' => true,
        ]), new BufferedOutput());

        $this->assertSame(PruneCommand::SUCCESS, $status);
        $this->assertTrue(TestablePruneCommand::$forced);
    }

    public function test_run_due(): void
    {
        TestablePruneCommand::$due = [
            'events_remover' => true,
            'logs_remover' => false,
        ];
        $cmd = new TestablePruneCommand(self::prunersFixture());

        $status = $cmd->run(new ArrayInput([
            '--run' => true,
        ]), new BufferedOutput());

        $this->assertSame(PruneCommand::SUCCESS, $status);
        $this->assertSame(
            [
                ['always_pruner',  false],
                ['events_remover', false],
            ],
            self::$calls,
        );
    }

    public function test_run_exec(): void
    {
        TestablePruneCommand::$due = [
            'events_remover' => false,
            'logs_remover' => true,
        ];
        $cmd = new TestablePruneCommand(self::prunersFixture());

        $status = $cmd->run(new ArrayInput([
            '--run' => true,
            '--execute' => true,
        ]), new BufferedOutput());

        $this->assertSame(PruneCommand::SUCCESS, $status);
        $this->assertSame(
            [
                ['always_pruner', true],
                ['logs_remover',  true],
            ],
            self::$calls,
        );
    }

    public function test_run_one(): void
    {
        TestablePruneCommand::$due = [
            'always_pruner' => false,
            'events_remover' => false,
            'logs_remover' => false,
        ];
        $cmd = new TestablePruneCommand(self::prunersFixture());

        $status = $cmd->run(new ArrayInput([
            '--run' => true,
            '--prune' => 'events_remover',
        ]), new BufferedOutput());

        $this->assertSame(PruneCommand::SUCCESS, $status);
        $this->assertSame(
            [
                ['events_remover', false],
            ],
            self::$calls,
        );
    }

    public function test_run_name(): void
    {
        TestablePruneCommand::$due = [
            'always_pruner' => false,
            'events_remover' => false,
            'logs_remover' => false,
        ];
        $cmd = new TestablePruneCommand(self::prunersFixture());

        $status = $cmd->run(new ArrayInput([
            '--run' => true,
            '--prune' => 'Events Remover',
        ]), new BufferedOutput());

        $this->assertSame(PruneCommand::SUCCESS, $status);
        $this->assertSame(
            [
                ['events_remover', false],
            ],
            self::$calls,
        );
    }

    public function test_run_missing(): void
    {
        $handler = new TestHandler();
        $cmd = new TestablePruneCommand(self::prunersFixture(), handler: $handler);

        $status = $cmd->run(new ArrayInput([
            '--run' => true,
            '--prune' => 'missing_pruner',
        ]), new BufferedOutput());

        $this->assertSame(PruneCommand::FAILURE, $status);
        $this->assertTrue($handler->hasWarningThatContains('No pruner with that name registered'));
    }

    public function test_run_broken(): void
    {
        $handler = new TestHandler();
        $broken = new Item(
            Target::IS_CLASS,
            'App\\Libs\\Attributes\\Cli\\Prune',
            static function (): void {
                throw new \RuntimeException('broken pruner');
            },
            ['name' => 'broken_pruner', 'enabled' => true],
        );

        $ok = new Item(
            Target::IS_CLASS,
            'App\\Libs\\Attributes\\Cli\\Prune',
            static function (bool $execute): void {
                self::$calls[] = ['ok_pruner', $execute];
            },
            ['name' => 'ok_pruner', 'enabled' => true],
        );

        $cmd = new TestablePruneCommand([
            'broken_pruner' => [
                'name' => 'broken_pruner',
                'cron' => null,
                'desc' => 'Broken',
                'enabled' => true,
                'callable' => $broken->getCallable(),
                'item' => $broken,
                'target' => $broken->getTarget(),
            ],
            'ok_pruner' => [
                'name' => 'ok_pruner',
                'cron' => null,
                'desc' => 'Ok',
                'enabled' => true,
                'callable' => $ok->getCallable(),
                'item' => $ok,
                'target' => $ok->getTarget(),
            ],
        ], handler: $handler);

        $status = $cmd->run(new ArrayInput([
            '--run' => true,
        ]), new BufferedOutput());

        $this->assertSame(PruneCommand::SUCCESS, $status);
        $this->assertSame(
            [
                ['ok_pruner', false],
            ],
            self::$calls,
        );
        $this->assertTrue($handler->hasWarningThatContains('Skipping pruner'));
    }

    public function test_run_cron(): void
    {
        $handler = new TestHandler();
        $bad = new Item(
            Target::IS_CLASS,
            'App\\Libs\\Attributes\\Cli\\Prune',
            static function (bool $execute): void {
                self::$calls[] = ['bad_pruner', $execute];
            },
            ['name' => 'bad_pruner', 'cron' => 'not-a-cron', 'enabled' => true],
        );

        $ok = new Item(
            Target::IS_CLASS,
            'App\\Libs\\Attributes\\Cli\\Prune',
            static function (bool $execute): void {
                self::$calls[] = ['ok_pruner', $execute];
            },
            ['name' => 'ok_pruner', 'enabled' => true],
        );

        $cmd = new FixturePruneCommand([
            'bad_pruner' => [
                'name' => 'bad_pruner',
                'cron' => 'not-a-cron',
                'desc' => 'Bad cron',
                'enabled' => true,
                'callable' => $bad->getCallable(),
                'item' => $bad,
                'target' => $bad->getTarget(),
            ],
            'ok_pruner' => [
                'name' => 'ok_pruner',
                'cron' => null,
                'desc' => 'Always run',
                'enabled' => true,
                'callable' => $ok->getCallable(),
                'item' => $ok,
                'target' => $ok->getTarget(),
            ],
        ], handler: $handler);

        $status = $cmd->run(new ArrayInput([
            '--run' => true,
        ]), new BufferedOutput());

        $this->assertSame(PruneCommand::SUCCESS, $status);
        $this->assertSame(
            [
                ['ok_pruner', false],
            ],
            self::$calls,
        );
        $this->assertTrue($handler->hasWarningThatContains('Skipping pruner'));
    }
}
