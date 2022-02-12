<?php

declare(strict_types=1);

namespace App\Commands\Scheduler;

use App\Command;
use App\Libs\Container;
use App\Libs\Scheduler\Task;
use Closure;
use League\Container\ReflectionContainer;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class RunClosure extends Command
{
    public function __construct(private LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('scheduler:closure')
            ->addArgument('task', InputArgument::REQUIRED, 'Run task closure.', null)
            ->setDescription('Run task closure.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $runTask = $input->getArgument('task');

        if (!is_string($runTask)) {
            throw new RuntimeException('Invalid Task name was given.');
        }

        $tasks = Run::getTasks();

        if (!array_key_exists($runTask, $tasks)) {
            throw new RuntimeException(sprintf('Task \'%s\' was not found in Tasks config file.', $runTask));
        }

        $task = Run::fixTask($tasks[$runTask]);

        $isRunnable = ($task[Task::COMMAND] instanceof Closure) && false === $task[Task::USE_CLOSURE_AS_COMMAND];

        if (false === $isRunnable) {
            throw new RuntimeException(
                sprintf(
                    'Was Expecting Command to be \'Closure\' for \'%s\'. But got \'%s\' instead.',
                    $runTask,
                    gettype($task[Task::COMMAND])
                )
            );
        }

        try {
            $commandOutput = (string)self::runClosure($task[Task::COMMAND], $task[Task::CONFIG]);
        } catch (Throwable $e) {
            $commandOutput = sprintf(
                'Task \'%s\' has thrown unhandled exception. \'%s\'. (%s:%d)',
                $task[Task::NAME],
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );

            $this->logger->error($e->getMessage(), $e->getTrace());
        }

        $output->write($commandOutput);

        return self::SUCCESS;
    }

    public static function runClosure(Closure $fn, array $config = []): mixed
    {
        return Container::get(ReflectionContainer::class)->call($fn, $config);
    }
}
