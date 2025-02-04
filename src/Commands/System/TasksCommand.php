<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Events\DataEvent;
use App\Libs\Extends\ConsoleOutput;
use App\Libs\Stream;
use App\Model\Events\EventListener;
use App\Model\Events\EventsRepository;
use Closure;
use Cron\CronExpression;
use Exception;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Class TasksCommand
 *
 * Automates the runs of scheduled tasks.
 */
#[Cli(command: self::ROUTE)]
final class TasksCommand extends Command
{
    public const string NAME = 'run_task';
    public const string ROUTE = 'system:tasks';

    private array $logs = [];
    private array $taskOutput = [];

    private Closure|null $writer = null;

    /**
     * Class Constructor.
     */
    public function __construct(private EventsRepository $eventsRepo)
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $tasksName = implode(
            ', ',
            array_map(fn($val) => '<comment>' . strtoupper($val) . '</comment>',
                array_keys(Config::get('tasks.list', [])))
        );

        $this->setName(self::ROUTE)
            ->addOption('run', null, InputOption::VALUE_NONE, 'Run scheduled tasks.')
            ->addOption('task', 't', InputOption::VALUE_REQUIRED, 'Run the specified task only.')
            ->addOption('save-log', null, InputOption::VALUE_NONE, 'Save tasks output to file.')
            ->addOption('live', null, InputOption::VALUE_NONE, 'See output in real time.')
            ->addOption(
                'args',
                'a',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Extra arguments for the task.'
            )
            ->setDescription('List & Run scheduled tasks.')
            ->setHelp(
                r(
                    <<<HELP

                    This command automates the runs of scheduled tasks.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How run scheduled tasks?</question>

                    To run scheduled tasks, Do the following

                    {cmd} <cmd>{route}</cmd> <flag>--run</flag>

                    <question># How to force run specific task?</question>

                    You have to combine both <flag>[--run]</flag> and [<flag>--task</flag> <value>task_name</value>], For example:

                    {cmd} <cmd>{route}</cmd> <flag>--task</flag> <value>import</value> <flag>--run</flag>

                    Running task in force mode, <notice>bypass</notice> the task enabled check.

                    <question># How to configure tasks?</question>

                    All Prebuilt tasks have 3 environment variables associated with them.

                    ## <flag>WS_CRON_<value>{TASK}</value>:</flag>

                    This environment variable control whether the task is enabled or not, it auto cast the value to bool. For example,
                    to enable <value>import</value> task simply add new environment variable called [<flag>WS_CRON_</flag><value>IMPORT</value>] with value of [<value>true</value>] or [<value>1</value>].

                    ## <info>WS_CRON_<value>{TASK}</value>_AT:</info>

                    This environment variable control when the task should run, it accepts valid cron expression timer. For example,
                    to run <value>import</value> every two hours add new environment variable called [<info>WS_CRON_<value>IMPORT</value>_AT</info>] with value of [<info>0 */2 * * *</info>].


                    ## <info>WS_CRON_<value>{TASK}</value>_ARGS</info>:

                    This environment variable control the options passed to the executed command, For example to expand the information
                    logged during <value>import</value> run, add new environment variable called [<info>WS_CRON_<value>IMPORT</value>_ARGS</info>] with value of [<info>-vvv --context</info>].
                    Simply put, run help on the associated command, and you can use any <value>Options</value> listed there in this variable.

                    ## <value>{TASK}</value>

                    Replace <value>{TASK}</value> tag in environment variables which one of the following [ {tasksList} ]
                    environment variables are in <value>ALL CAPITAL LETTERS</value>.

                    <question># How to pass extra arguments to a run task?</question>

                    You can pass extra arguments to a task by using the <flag>--args</flag> option, For example:

                    {cmd} <cmd>{route}</cmd> <flag>--task</flag> <value>import</value> <flag>--run</flag> <flag>--args</flag>=<value>arg1=arg_value</value> <flag>--args</flag>=<value>arg2=arg_value</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'tasksList' => $tasksName,
                    ]
                )
            );
    }

    /**
     * If the run option is set, run the tasks, otherwise list available tasks.
     *
     * @param iInput $input The input instance.
     * @param iOutput $output The output instance.
     *
     * @return int Returns the exit code of the command.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        if ($input->hasOption('run') && $input->getOption('run')) {
            return $this->runTasks($input, $output);
        }

        $list = [];

        $mode = $input->getOption('output');

        foreach (self::getTasks() as $task) {
            $list[] = [
                'name' => $task['name'],
                'command' => $task['command'],
                'options' => $task['args'] ?? '',
                'timer' => $task['timer']->getExpression(),
                'description' => $task['description'] ?? '',
                'NextRun' => $task['next'],
            ];
        }

        $this->displayContent($list, $output, $mode);

        return self::SUCCESS;
    }

    #[EventListener(self::NAME)]
    public function runEventTask(DataEvent $event): DataEvent
    {
        $event->stopPropagation();

        if (null === ($name = ag($event->getData(), 'name'))) {
            $event->addLog(r('No task name was specified.'));
            return $event;
        }

        $task = self::getTasks($name);
        if (empty($task)) {
            $event->addLog(r("Invalid task '{name}'. There are no task with that name registered.", ['name' => $name]));
            return $event;
        }

        try {
            $input = new ArrayInput([], $this->getDefinition());
            $input->setOption('run', null);
            $input->setOption('task', null);
            $input->setOption('save-log', true);
            $input->setOption('live', false);

            $this->writer = function ($msg) use (&$event) {
                static $lastSave = null;

                $timeNow = hrtime(as_number: true);

                if (null === $lastSave) {
                    $lastSave = $timeNow;
                }

                $event->addLog($msg);

                if ($timeNow > $lastSave) {
                    $this->eventsRepo->save($event->getEvent());
                    $lastSave = $timeNow + (10 * 1_000_000_000);
                }
            };

            $event->addLog(r("Task: Run '{command}'.", ['command' => ag($task, 'command')]));
            $exitCode = $this->runTask($task, $input, Container::get(iOutput::class));
            $event->addLog(r("Task: End '{command}' (Exit Code: {code})", [
                'command' => ag($task, 'command'),
                'code' => $exitCode,
            ]));
        } finally {
            $this->writer = null;
        }

        return $event;
    }

    /**
     * Runs the tasks.
     *
     * @param iInput $input The input object.
     * @param iOutput $output The output object.
     *
     * @return int The exit code of the command.
     */
    private function runTasks(iInput $input, iOutput $output): int
    {
        $run = [];
        $tasks = self::getTasks();

        if (null !== ($task = $input->getOption('task'))) {
            $task = strtolower($task);

            if (false === ag_exists($tasks, $task)) {
                $output->writeln(r('<error>There are no task named [{task}].</error>', [
                    'task' => $task
                ]));
                return self::FAILURE;
            }

            $run[] = ag($tasks, $task);
        } else {
            foreach ($tasks as $task) {
                if (false === (bool)ag($task, 'enabled')) {
                    continue;
                }

                assert($task['timer'] instanceof CronExpression);
                if ($task['timer']->isDue('now')) {
                    $run[] = $task;
                }
            }
        }

        if (count($run) < 1) {
            $output->writeln(r('<info>[{datetime}] No task scheduled to run at this time.</info>', [
                'datetime' => makeDate(),
            ]), iOutput::VERBOSITY_VERBOSE);
        }

        foreach ($run as $task) {
            $this->runTask($task, $input, $output);
        }

        if ($input->getOption('save-log') && count($this->logs) >= 1) {
            try {
                $stream = new Stream(Config::get('tasks.logfile'), 'a');
                $stream->write(preg_replace('#\R+#', PHP_EOL, implode(PHP_EOL, $this->logs)) . PHP_EOL . PHP_EOL);
                $stream->close();
            } catch (Throwable $e) {
                $this->write(r("<error>Failed to open/write to logfile '{file}'. Error '{message}'.</error>", [
                    'file' => Config::get('tasks.logfile'),
                    'message' => $e->getMessage(),
                ]), $input, $output);

                return self::INVALID;
            }
        }

        return self::SUCCESS;
    }

    private function runTask(array $task, iInput $input, iOutput $output): int
    {
        $cmd = [];

        $cmd[] = ROOT_PATH . '/bin/console';
        $cmd[] = ag($task, 'command');

        if (null !== ($args = ag($task, 'args'))) {
            $cmd[] = $args;
        }

        if (count($input->getOption('args')) >= 1) {
            $cmd[] = $this->parseExtraArgs($input->getOption('args'));
        }

        $process = Process::fromShellCommandline(implode(' ', $cmd), timeout: null);

        $started = makeDate()->format('D, H:i:s T');

        $process->start(function ($std, $out) use ($input, $output) {
            assert($output instanceof ConsoleOutputInterface);

            if (empty($out)) {
                return;
            }

            $this->taskOutput[] = trim($out);

            if (!$input->hasOption('live') && $input->getOption('live')) {
                return;
            }

            ('err' === $std ? $output->getErrorOutput() : $output)->writeln(trim($out));
        });

        if ($process->isRunning()) {
            $process->wait();
        }

        if (count($this->taskOutput) < 1) {
            return $process->getExitCode();
        }

        $ended = makeDate()->format('D, H:i:s T');

        $this->write('--------------------------', $input, $output);
        $this->write(
            r('Task: {name} (Started: {startDate})', [
                'name' => $task['name'],
                'startDate' => $started,
            ]),
            $input,
            $output
        );
        $this->write(r('Command: {cmd}', ['cmd' => $process->getCommandLine()]), $input, $output);
        $this->write(
            r('Exit Code: {code} (Ended: {endDate})', [
                'code' => $process->getExitCode(),
                'endDate' => $ended,
            ]),
            $input,
            $output
        );
        $this->write('--------------------------', $input, $output);
        $this->write(' ' . PHP_EOL, $input, $output);

        foreach ($this->taskOutput as $line) {
            $this->write($line, $input, $output);
        }

        $this->taskOutput = [];

        return $process->getExitCode();
    }

    /**
     * Write method.
     *
     * Writes a given text to the output with the specified level.
     * Optionally if the 'save-log' option is set to true, the output will be saved to the logs array.
     * The logs array will be saved to the log file at the end of the command execution.
     *
     * @param string $text The text to write to output.
     * @param iInput $input The input object.
     * @param iOutput $output The output object.
     * @param int $level The level of the output (default: iOutput::OUTPUT_NORMAL).
     */
    private function write(string $text, iInput $input, iOutput $output, int $level = iOutput::OUTPUT_NORMAL): void
    {
        assert($output instanceof ConsoleOutput);
        $output->writeln($text, $level);

        if (null !== $this->writer) {
            ($this->writer)($output->getLastMessage());
        }

        if ($input->hasOption('save-log') && $input->getOption('save-log')) {
            $this->logs[] = $output->getLastMessage();
        }
    }

    /**
     * Get the list of tasks.
     *
     * @param string|null $name The name of the task to get.
     *
     * @return array<string, array{name: string, command: string, args: string, description: string, enabled: bool, timer: CronExpression, next: string }> The list of tasks.
     */
    public static function getTasks(string|null $name = null): array
    {
        $list = [];

        foreach (Config::get('tasks.list', []) as $task) {
            $timer = new CronExpression($task['timer'] ?? '5 * * * *');

            $list[$task['name']] = [
                'name' => $task['name'],
                'command' => $task['command'],
                'args' => $task['args'] ?? '',
                'description' => $task['info'] ?? '',
                'enabled' => (bool)$task['enabled'],
                'timer' => $timer,
                'hide' => (bool)($task['hide'] ?? false),
            ];

            try {
                $list[$task['name']]['next'] = $task['enabled'] ? $timer->getNextRunDate('now')->format(
                    'Y-m-d H:i:s T'
                ) : 'Disabled';
            } catch (Exception $e) {
                $list[$task['name']]['next'] = $e->getMessage();
            }
        }

        if (null !== $name) {
            return ag($list, $name, []);
        }

        return $list;
    }

    /**
     * Complete the input with suggestions if necessary.
     *
     * @param CompletionInput $input The completion input object.
     * @param CompletionSuggestions $suggestions The completion suggestions object.
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestOptionValuesFor('task')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (array_keys(Config::get('tasks.list', [])) as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }

    private function parseExtraArgs(array $args): string
    {
        $cmd = [];

        # args passed as the following arg_name=arg_value parse and return key value pairs
        foreach ($args as $arg) {
            if (false === str_contains($arg, '=')) {
                $cmd[] = $arg;
                continue;
            }

            [$flag, $value] = explode('=', $arg, 2);

            $cmd[] = $flag;

            if (!empty($value)) {
                $cmd[] = escapeshellarg($value);
            }
        }

        return implode(' ', $cmd);
    }
}
