<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Commands\Events\DispatchCommand;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Events\DataEvent;
use App\Libs\Extends\CaptureHandler;
use App\Libs\Extends\JsonlFormatter;
use App\Libs\LogSuppressor;
use App\Libs\Stream;
use App\Model\Events\Event as EventModel;
use App\Model\Events\EventListener;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventStatus;
use Cron\CronExpression;
use DateInterval;
use Exception;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
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
    public const string CNAME = 'run_console';
    public const string ROUTE = 'system:tasks';

    public const array NO_EVENTS = [DispatchCommand::TASK_NAME, PruneCommand::TASK_NAME];

    public const string CACHE_NAME = 'tasks.running';
    public const string CACHE_TIME = 'PT6H';

    private bool $viaEvent = false;
    private ?DataEvent $dispatchEvent = null;
    private readonly JsonlFormatter $jsonlFormatter;

    /**
     * Class Constructor.
     */
    public function __construct(
        private readonly EventsRepository $eventsRepo,
        private readonly LogSuppressor $suppressor,
        private readonly iCache $cache,
        private readonly iLogger $logger,
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        $this->jsonlFormatter = new JsonlFormatter();

        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $tasksName = implode(
            ', ',
            array_map(
                static fn($val) => '<comment>' . strtoupper($val) . '</comment>',
                array_keys(Config::get('tasks.list', [])),
            ),
        );

        $this
            ->setName(self::ROUTE)
            ->addOption('run', null, InputOption::VALUE_NONE, 'Run scheduled tasks.')
            ->addOption('task', 't', InputOption::VALUE_REQUIRED, 'Run the specified task only.')
            ->addOption('save-log', null, InputOption::VALUE_NONE, 'Save tasks output to file.')
            ->addOption(
                'args',
                'a',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Extra arguments for the task.',
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
                        'cmd' => trim(command_context()),
                        'route' => self::ROUTE,
                        'tasksList' => $tasksName,
                    ],
                ),
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
    #[EventListener(self::CNAME)]
    public function runEvent(DataEvent $event): DataEvent
    {
        $event->stopPropagation();
        $eventName = $event->getEvent()->event;

        switch ($eventName) {
            case self::NAME:
                if (null === ($name = ag($event->getData(), 'name'))) {
                    $event->addLog(Level::Error, 'No task name was specified.');
                    return $event;
                }

                $task = self::getTasks($name);
                if (empty($task)) {
                    $event->addLog(Level::Error, "Invalid task '{task_id}'. There are no task with that name registered.", [
                        'task_id' => $name,
                    ]);
                    return $event;
                }
                break;
            case self::CNAME:
                if (null === ag($event->getData(), 'command')) {
                    $event->addLog(Level::Error, 'No command name was specified.');
                    return $event;
                }
                break;
        }

        try {
            $this->viaEvent = true;
            $this->dispatchEvent = $event;

            $input = new ArrayInput([], $this->getDefinition());
            $input->setOption('run', null);
            $input->setOption('task', null);
            $input->setOption('save-log', true);

            if (self::CNAME === $eventName) {
                $event->addLog(Level::Info, "Task '{task_id}' started: {command}.", [
                    'task_id' => $eventName,
                    'command' => ag($event->getData(), 'command'),
                ]);
                $exitCode = $this->run_command(
                    ag($event->getData(), 'command'),
                    ag($event->getData(), 'args', []),
                    $input,
                    Container::get(iOutput::class),
                );
                $event->addLog(
                    0 === $exitCode ? Level::Info : Level::Error,
                    "Task '{task_id}' {status} with exit code {exit_code}.",
                    [
                        'task_id' => $eventName,
                        'exit_code' => $exitCode,
                        'status' => 0 === $exitCode ? 'completed' : 'failed',
                        'command' => ag($event->getData(), 'command'),
                    ],
                );
            }

            if (self::NAME === $eventName && !empty($task)) {
                $event->addLog(Level::Info, "Task '{task_id}' started: {command}.", [
                    'task_id' => ag($task, 'name'),
                    'command' => ag($task, 'command'),
                ]);
                $exitCode = $this->runTask($task, $input, Container::get(iOutput::class));
                $event->addLog(
                    0 === $exitCode ? Level::Info : Level::Error,
                    "Task '{task_id}' {status} with exit code {exit_code}.",
                    [
                        'task_id' => ag($task, 'name'),
                        'exit_code' => $exitCode,
                        'status' => 0 === $exitCode ? 'completed' : 'failed',
                        'command' => ag($task, 'command'),
                    ],
                );
            }
        } finally {
            $this->dispatchEvent = null;
            $this->viaEvent = false;
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
        $cacheTTL = new DateInterval(self::CACHE_TIME);
        $run = [];
        $tasks = self::getTasks();

        if (null !== ($task = $input->getOption('task'))) {
            $task = strtolower($task);

            if (false === ag_exists($tasks, $task)) {
                $this->logger->error("Unknown task '{task}'. No task with that name registered.", [
                    'task' => $task,
                ]);
                return self::FAILURE;
            }

            $run[] = ag($tasks, $task);
        } else {
            foreach ($tasks as $task) {
                if (false === (bool) ag($task, 'enabled')) {
                    continue;
                }

                assert($task['timer'] instanceof CronExpression, 'Expected CronExpression for task timer.');
                if ($task['timer']->isDue('now')) {
                    $run[] = $task;
                }
            }
        }

        if (count($run) < 1) {
            $this->logger->debug("No task scheduled at '{datetime}'.", [
                'datetime' => make_date(),
            ]);

            return self::SUCCESS;
        }

        $this->cache->set(self::CACHE_NAME, true, $cacheTTL);

        try {
            foreach ($run as $task) {
                $this->runTask($task, $input, $output);
            }
        } finally {
            $this->cache->delete(self::CACHE_NAME);
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

        return $this->runProcess($cmd, $input, $output, $task);
    }

    private function run_command(string $command, array $args, iInput $input, iOutput $output): int
    {
        $cmd = [];

        $cmd[] = ROOT_PATH . '/bin/console';
        $cmd[] = $command;

        if (count($args) > 0) {
            foreach ($args as $v) {
                if (empty($v)) {
                    continue;
                }
                $cmd[] = $v;
            }
        }

        return $this->runProcess($cmd, $input, $output, null);
    }

    /**
     * Run a subprocess, routing all output through the logger via CaptureHandler.
     *
     * @param array<string> $cmd
     * @param array{name: string, command: string, args?: string}|null $task
     *
     * @return int Exit code.
     */
    private function runProcess(array $cmd, iInput $input, iOutput $output, ?array $task = null): int
    {
        if (false === str_contains(implode(' ', $cmd), '--jsonl')) {
            $cmd[] = '--jsonl';
        }

        $capture = new CaptureHandler();
        assert($this->logger instanceof Logger, 'Expected Monolog Logger instance.');
        $this->logger->pushHandler($capture);

        try {
            $process = Process::fromShellCommandline(implode(' ', $cmd), timeout: null);
            $started = make_date();
            $hadChildOutput = false;

            $process->start(function ($type, $out) use ($output, &$hadChildOutput) {
                $out = trim((string) $out);

                if ('' === $out) {
                    return;
                }

                if ('err' === $type) {
                    foreach (explode(PHP_EOL, $out) as $line) {
                        $line = trim($line);

                        if ('' === $line) {
                            continue;
                        }

                        $this->logger->info($line);
                        $hadChildOutput = true;
                    }
                } else {
                    $output->writeln($out);
                }
            });

            if ($process->isRunning()) {
                try {
                    $process->wait();
                } catch (ProcessException $e) {
                    $process->stop();

                    if (null !== $task) {
                        $this->logger->error("Task '{name}' failed: {error}", [
                            'name' => $task['name'],
                            'error' => $e->getMessage(),
                            'exception' => $e,
                        ]);
                    } else {
                        $this->logger->error('Command failed: {error}', [
                            'command' => implode(' ', $cmd),
                            'error' => $e->getMessage(),
                            'exception' => $e,
                        ]);
                    }
                }
            }

            $ended = make_date();
            $exitCode = $process->getExitCode() ?? self::INVALID;

            // --- Populate event-log ---

            if (null !== $this->dispatchEvent && true === $this->viaEvent) {
                foreach ($capture->getRecords() as $record) {
                    $this->dispatchEvent->addRawLog($this->resolveJsonl($record));
                }
            }

            if (null !== $task && false === $this->viaEvent) {
                $records = $capture->getRecords();
                $shouldCreateEvent = !in_array($task['name'], self::NO_EVENTS, true) || count($records) > 0;

                if ($shouldCreateEvent) {
                    $event = $this->eventsRepo->getObject([]);
                    $event->status = 0 === $exitCode ? EventStatus::SUCCESS : EventStatus::FAILED;
                    $event->event = self::NAME . '.' . $task['name'];
                    $event->created_at = $started;
                    $event->updated_at = $ended;

                    foreach ($records as $record) {
                        $event->addRawLog($this->resolveJsonl($record));
                    }

                    $this->eventsRepo->save($event);
                }
            }

            // --- Console summary (direct CLI only) ---

            if (false === $this->viaEvent && $hadChildOutput) {
                $name = null !== $task ? $task['name'] : implode(' ', $cmd);

                $this->logger->log(
                    0 === $exitCode ? Level::Info : Level::Error,
                    "Task '{name}' completed. ({status}) - Took {duration}s",
                    [
                        'name' => $name,
                        'command' => $process->getCommandLine(),
                        'exit_code' => $exitCode,
                        'status' => 0 === $exitCode ? 'Success' : 'Failed',
                        'started_at' => $started->format('D, H:i:s T'),
                        'ended_at' => $ended->format('D, H:i:s T'),
                        'duration' => $ended->getTimestamp() - $started->getTimestamp(),
                    ],
                );
            }

            if ($input->hasOption('save-log') && $input->getOption('save-log') && $hadChildOutput) {
                $lines = [];

                if ($this->viaEvent) {
                    $name = null !== $task ? $task['name'] : implode(' ', $cmd);
                    $lines[] = $this->jsonlFormatter->formatValues(
                        channel: $this->logger instanceof \Monolog\Logger ? $this->logger->getName() : 'task',
                        level: 0 === $exitCode ? Level::Info : Level::Error,
                        message: r("Task '{name}' completed. ({status}) - Took {duration}s", [
                            'name' => $name,
                            'status' => 0 === $exitCode ? 'Success' : 'Failed',
                            'duration' => $ended->getTimestamp() - $started->getTimestamp(),
                        ]),
                    );
                }

                foreach ($capture->getRecords() as $record) {
                    $lines[] = $this->resolveJsonl($record);
                }

                try {
                    $stream = new Stream(Config::get('tasks.logfile'), 'a');
                    $stream->write(implode('', $lines));
                    $stream->close();
                } catch (Throwable $e) {
                    $this->logger->error("Failed to write to logfile '{file}': {error}", [
                        'file' => Config::get('tasks.logfile'),
                        'error' => $e->getMessage(),
                        'exception' => $e,
                    ]);
                }
            }

            return $exitCode;
        } finally {
            $this->logger->popHandler();
        }
    }

    /**
     * Format a captured LogRecord for event-log or save-log.
     *
     * Passes through JSONL lines from child processes, formats everything else.
     */
    private function resolveJsonl(\Monolog\LogRecord $record): string
    {
        if (JsonlFormatter::isJsonlRecord($record->message)) {
            return $record->message . \PHP_EOL;
        }

        return $this->jsonlFormatter->format($record);
    }

    /**
     * Get the list of tasks.
     *
     * @param string|null $name The name of the task to get.
     *
     * @return array<string, array{name: string, command: string, args: string, description: string, enabled: bool, timer: CronExpression, next: string }> The list of tasks.
     */
    public static function getTasks(?string $name = null): array
    {
        $list = [];

        foreach (Config::get('tasks.list', []) as $task) {
            $timer = new CronExpression($task['timer'] ?? '5 * * * *');

            $list[$task['name']] = [
                'name' => $task['name'],
                'command' => $task['command'],
                'args' => $task['args'] ?? '',
                'description' => $task['info'] ?? '',
                'enabled' => (bool) $task['enabled'],
                'timer' => $timer,
                'hide' => (bool) ($task['hide'] ?? false),
            ];

            try {
                $list[$task['name']]['next'] = $task['enabled']
                    ? $timer
                        ->getNextRunDate('now')
                        ->format(
                            'Y-m-d H:i:s T',
                        )
                    : 'Disabled';
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
                if (!(empty($currentValue) || str_starts_with($name, $currentValue))) {
                    continue;
                }

                $suggest[] = $name;
            }

            $suggestions->suggestValues($suggest);
        }
    }

    private function parseExtraArgs(array $args): string
    {
        $cmd = [];

        // args passed as the following arg_name=arg_value parse and return key value pairs
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
