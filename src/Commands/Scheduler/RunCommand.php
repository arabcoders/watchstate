<?php

declare(strict_types=1);

namespace App\Commands\Scheduler;

use App\Command;
use App\Libs\Config;
use App\Libs\Extends\ConsoleOutput;
use App\Libs\Scheduler\Scheduler;
use App\Libs\Scheduler\Task;
use App\Libs\Scheduler\TaskTimer;
use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RunCommand extends Command
{
    private Scheduler $scheduler;
    private array $registered = [];
    private array $logs = [];

    public function __construct(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('scheduler:run')
            ->addOption('no-headers', 'g', InputOption::VALUE_NONE, 'Do not prefix output with headers.')
            ->addOption('save-log', null, InputOption::VALUE_NONE, 'Save Tasks Output to file.')
            ->addArgument('task', InputArgument::OPTIONAL, 'Run specific task.', null)
            ->setDescription('Run Scheduled Tasks.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $runSpecificTask = $input->getArgument('task');

        $tasks = self::getTasks();

        if (is_string($runSpecificTask)) {
            if (!array_key_exists($runSpecificTask, $tasks)) {
                throw new RuntimeException(
                    sprintf('Task \'%s\' was not found in Tasks config file.', $runSpecificTask)
                );
            }

            $tasks[$runSpecificTask][Task::RUN_AT] = TaskTimer::everyMinute();

            $tasks = [$tasks[$runSpecificTask]];
        }

        foreach ($tasks as $task) {
            $newTask = $this->makeTask($task);

            if (true !== $task[Task::ENABLED] && $task[Task::NAME] !== $runSpecificTask) {
                continue;
            }

            $this->scheduler->queueTask($newTask);
        }

        $this->scheduler->run(new DateTimeImmutable('now'));

        $executedTasks = $this->scheduler->getExecutedTasks();

        $count = count($executedTasks);

        if (0 === $count) {
            $this->write(
                sprintf('[%s] <info>No Tasks Scheduled to run at this time.</info>', makeDate()),
                $input,
                $output,
                OutputInterface::VERBOSITY_VERY_VERBOSE
            );
        }

        $tasks = array_reverse($executedTasks);
        $noHeaders = (bool)$input->getOption('no-headers');

        foreach ($tasks as $task) {
            $taskOutput = trim($task->getOutput());

            if (empty($taskOutput)) {
                continue;
            }

            if (false === $noHeaders) {
                $this->write('--------------------------', $input, $output);
                $this->write('Command: ' . $task->getCommand() . ' ' . $task->getArgs(), $input, $output);
                $this->write('--------------------------', $input, $output);
                $this->write(sprintf('Task %s Output.', $task->getName()), $input, $output);
                $this->write('--------------------------', $input, $output);
                $this->write('', $input, $output);
            }

            $this->write($taskOutput, $input, $output);

            if (false === $noHeaders) {
                $this->write('', $input, $output);
            }
        }

        if ($input->getOption('save-log')) {
            file_put_contents(
                Config::get('tmpDir') . '/logs/crons/' . gmdate('Y_m_d') . '.log',
                preg_replace('#\R+#', PHP_EOL, implode(PHP_EOL, $this->logs)),
                FILE_APPEND
            );
        }

        return self::SUCCESS;
    }

    private function write(
        string $text,
        InputInterface $input,
        OutputInterface $output,
        int $level = OutputInterface::OUTPUT_NORMAL
    ): void {
        assert($output instanceof ConsoleOutput);
        $output->writeln($text, $level);

        if ($input->getOption('save-log')) {
            $this->logs[] = $output->getLastMessage();
        }
    }

    public static function getTasks(): array
    {
        $tasks = [];

        foreach (Config::get('tasks', []) as $i => $task) {
            $task[Task::NAME] = $task[Task::NAME] ?? 'task_' . ((int)($i) + 1);
            $tasks[$task[Task::NAME]] = $task;
        }

        return $tasks;
    }

    private function makeTask(array $task): Task
    {
        $task = self::fixTask($task);
        $cli = env('IN_DOCKER') ? 'console' : 'php ' . ROOT_PATH . '/console';

        if (null === $task[Task::COMMAND]) {
            throw new RuntimeException(sprintf('Task \'%s\' does not have any execute command.', $task[Task::NAME]));
        }

        if (false === $task[Task::USE_CLOSURE_AS_COMMAND] && ($task[Task::COMMAND] instanceof Closure)) {
            $task[Task::COMMAND] = $cli . ' scheduler:closure ' . escapeshellarg($task[Task::NAME]);
        } else {
            if (($task[Task::COMMAND] instanceof Closure)) {
                $task[Task::COMMAND] = RunClosureCommand::runClosure($task[Task::COMMAND], $task[Task::CONFIG]);
            }

            if (!is_string($task[Task::COMMAND])) {
                throw new RuntimeException(
                    sprintf('Task \'%s\' Command did not evaluated to a string.', $task[Task::NAME])
                );
            }

            if (str_starts_with($task[Task::COMMAND], '@')) {
                $task[Task::COMMAND] = substr($task[Task::COMMAND], 1);
                $task[Task::COMMAND] = $cli . ' ' . $task[Task::COMMAND];
            }
        }

        if (in_array($task[Task::NAME], $this->registered)) {
            throw new RuntimeException(
                sprintf('There another task registered already with name \'%s\'.', $task[Task::NAME])
            );
        }

        $this->registered[] = $task[Task::NAME];

        $obj = Task::newTask($task[Task::NAME], $task[Task::COMMAND], $task[Task::ARGS], $task[Task::CONFIG]);

        $timer = $task[Task::RUN_AT] ?? TaskTimer::everyMinute(5);
        if ((!$timer instanceof CronExpression)) {
            $timer = TaskTimer::at($timer);
        }

        $obj->runAt($timer);

        if (true === $task[Task::RUN_IN_FOREGROUND]) {
            $obj->inForeground();
        }

        if (null !== $task[Task::BEFORE_CALL]) {
            $obj->setBeforeCall($task[Task::BEFORE_CALL]);
        }

        if (null !== $task[Task::WHEN_OVER_LAPPING_CALL]) {
            $obj->whenOverlapping($task[Task::WHEN_OVER_LAPPING_CALL]);
        }

        return $obj;
    }

    public static function fixTask(array $task): array
    {
        $task[Task::CONFIG] = $task[Task::CONFIG] ?? [];

        return [
            Task::NAME => (string)$task[Task::NAME],
            Task::COMMAND => $task[Task::COMMAND] ?? null,
            Task::ARGS => $task[Task::ARGS] ?? [],
            Task::RUN_AT => $task[Task::RUN_AT] ?? null,
            Task::USE_CLOSURE_AS_COMMAND => (bool)($task[Task::USE_CLOSURE_AS_COMMAND] ?? false),
            Task::BEFORE_CALL => $task[Task::BEFORE_CALL] ?? null,
            Task::RUN_IN_FOREGROUND => (bool)($task[Task::RUN_IN_FOREGROUND] ?? false),
            Task::WHEN_OVER_LAPPING_CALL => $task[Task::WHEN_OVER_LAPPING_CALL] ?? null,
            Task::CONFIG => $task[Task::CONFIG],
            Task::ENABLED => $task[Task::ENABLED],
        ];
    }
}
