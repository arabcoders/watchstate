<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Config;
use App\Libs\Extends\ConsoleOutput;
use App\Libs\Routable;
use Cron\CronExpression;
use Exception;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Component\Process\Process;

#[Routable(command: self::ROUTE)]
final class TasksCommand extends Command
{
    public const ROUTE = 'system:tasks';

    private array $logs = [];
    private array $taskOutput = [];

    public function __construct()
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

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
     * @throws Exception
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        if ($input->getOption('run')) {
            return $this->runTasks($input, $output);
        }

        $this->listTasks($input, $output);
        return self::SUCCESS;
    }

    private function runTasks(iInput $input, iOutput $output): int
    {
        $run = [];
        $tasks = $this->getTasks();

        if (null !== ($task = $input->getOption('task'))) {
            $task = strtolower($task);

            if (false === ag_exists($tasks, $task)) {
                $output->writeln(
                    r('<error>There are no task named [{task}].</error>', [
                        'task' => $task
                    ])
                );

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
            $output->writeln(
                r('<info>[{datetime}] No task scheduled to run at this time.</info>', [
                    'datetime' => makeDate(),
                ]),
                iOutput::VERBOSITY_VERBOSE
            );
        }

        foreach ($run as $task) {
            $cmd = [];

            $cmd[] = ROOT_PATH . '/bin/console';
            $cmd[] = ag($task, 'command');

            if (null !== ($args = ag($task, 'args'))) {
                $cmd[] = $args;
            }

            $process = Process::fromShellCommandline(implode(' ', $cmd), timeout: null);

            $started = makeDate()->format('D, H:i:s T');

            $process->start(function ($std, $out) use ($input, $output) {
                assert($output instanceof ConsoleOutputInterface);

                if (empty($out)) {
                    return;
                }

                $this->taskOutput[] = trim($out);

                if (!$input->getOption('live')) {
                    return;
                }

                ('err' === $std ? $output->getErrorOutput() : $output)->writeln(trim($out));
            });

            if ($process->isRunning()) {
                $process->wait();
            }

            if (count($this->taskOutput) < 1) {
                continue;
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
        }

        if ($input->getOption('save-log') && count($this->logs) >= 1) {
            if (false !== ($fp = @fopen(Config::get('tasks.logfile'), 'a'))) {
                fwrite($fp, preg_replace('#\R+#', PHP_EOL, implode(PHP_EOL, $this->logs)) . PHP_EOL . PHP_EOL);
                fclose($fp);
            }
        }

        return self::SUCCESS;
    }

    private function write(string $text, iInput $input, iOutput $output, int $level = iOutput::OUTPUT_NORMAL): void
    {
        assert($output instanceof ConsoleOutput);
        $output->writeln($text, $level);

        if ($input->getOption('save-log')) {
            $this->logs[] = $output->getLastMessage();
        }
    }

    /**
     * @throws Exception
     */
    private function listTasks(iInput $input, iOutput $output): void
    {
        $list = [];

        $mode = $input->getOption('output');

        foreach ($this->getTasks() as $task) {
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
    }

    private function getTasks(): array
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
            ];

            try {
                $list[$task['name']]['next'] = $task['enabled'] ? $timer->getNextRunDate('now')->format(
                    'Y-m-d H:i:s T'
                ) : 'Disabled';
            } catch (Exception $e) {
                $list[$task['name']]['next'] = $e->getMessage();
            }
        }

        return $list;
    }

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
}
