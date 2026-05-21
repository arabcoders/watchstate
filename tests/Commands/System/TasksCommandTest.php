<?php

declare(strict_types=1);

namespace Tests\Commands\System;

use App\Commands\System\TasksCommand;
use App\Libs\Extends\ConsoleOutput;
use App\Libs\LogSuppressor;
use App\Libs\TestCase;
use App\Model\Events\EventsRepository;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\InputInterface;

final class TasksCommandTest extends TestCase
{
    public function test_wraps_task_output_as_jsonl(): void
    {
        $command = new TasksCommand(
            $this->createStub(EventsRepository::class),
            new LogSuppressor([]),
            $this->createStub(iCache::class),
            $this->createStub(iLogger::class),
        );

        $logContext = new ReflectionProperty(TasksCommand::class, 'logContext');
        $logContext->setValue($command, [
            'task_id' => 'backup',
            'command' => 'state:backup',
            'source' => 'task',
        ]);

        $input = $this->createMock(InputInterface::class);
        $input->expects($this->once())->method('hasOption')->with('live')->willReturn(true);
        $input->expects($this->once())->method('getOption')->with('live')->willReturn(false);

        $method = new ReflectionMethod(TasksCommand::class, 'captureProcessOutputLine');
        $method->invoke($command, 'out', 'processed 5 items', $input, new ConsoleOutput());

        $taskOutput = new ReflectionProperty(TasksCommand::class, 'taskOutput');
        $logs = $taskOutput->getValue($command);

        self::assertCount(1, $logs);
        self::assertTrue(\App\Libs\Extends\JsonlFormatter::isJsonlRecord($logs[0]));

        $payload = json_decode(trim($logs[0]), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame("stdout from task 'backup': processed 5 items", $payload['message']);
        self::assertSame('task.output', ag($payload, 'fields.event_name'));
        self::assertSame('stdout', ag($payload, 'fields.stream'));
        self::assertSame('processed 5 items', ag($payload, 'fields.line'));
        self::assertSame(1, ag($payload, 'fields.line_number'));
        self::assertSame('backup', ag($payload, 'fields.task_id'));
        self::assertSame('state:backup', ag($payload, 'fields.command'));
    }
}
