<?php

declare(strict_types=1);

namespace Tests;

use App\Command;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class CommandTest extends TestCase
{
    public function test_logs_locked_command_event(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler], [new LogMessageProcessor()]);
        $factory = static fn() => new class() extends Command {
            public function __construct()
            {
                parent::__construct('test:lock');
            }

            public function runSingleForTest(BufferedOutput $output, Logger $logger): int
            {
                return $this->single(static fn(): int => self::SUCCESS, $output, [
                    \Psr\Log\LoggerInterface::class => $logger,
                ]);
            }

            public function runNestedForTest(self $other, BufferedOutput $output, Logger $logger): int
            {
                return $this->single(fn(): int => $other->runSingleForTest($output, $logger), $output, [
                    \Psr\Log\LoggerInterface::class => $logger,
                ]);
            }

        };
        $outer = $factory();
        $inner = $factory();

        $lockName = get_app_version() . ':test:lock';
        $status = $outer->runNestedForTest($inner, new BufferedOutput(), $logger);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('cli.command.locked', $handler->getRecords()[0]->context['event_name']);
        self::assertSame('test:lock', $handler->getRecords()[0]->context['command']);
        self::assertSame($lockName, $handler->getRecords()[0]->context['lock_name']);
    }

    public function test_wraps_display_content_in_jsonl_mode(): void
    {
        $command = new class() extends Command {
            public function __construct()
            {
                parent::__construct('test:data');
            }

            public function render(array $content, BufferedOutput $output, string $mode = 'json'): void
            {
                $this->displayContent($content, $output, $mode);
            }
        };

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
        
        \App\Libs\Config::save('console.output', 'jsonl');

        try {
            $command->render(['name' => 'watchstate'], $output);
        } finally {
            \App\Libs\Config::remove('console.output');
        }

        $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('cli.command.output', $payload['fields']['event_name']);
        self::assertSame('test:data', $payload['fields']['command']);
        self::assertSame('watchstate', $payload['fields']['data.name']);
    }
}
