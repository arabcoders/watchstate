<?php

declare(strict_types=1);

namespace App\Commands\Backend;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\Import\RestoreMapper;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\UserContext;
use DirectoryIterator;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Throwable;

/**
 * Class RestoreCommand
 *
 * Command used to restore a backend's play state from a backup file.
 */
#[Cli(command: self::ROUTE)]
class RestoreCommand extends Command
{
    public const string ROUTE = 'backend:restore';

    /**
     * Class constructor.
     *
     * @param QueueRequests $queue The queue object.
     * @param iLogger $logger The logger object.
     *
     * @return void
     */
    public function __construct(private readonly QueueRequests $queue, private readonly iLogger $logger)
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
        $this->setName(self::ROUTE)
            ->setDescription('Restore backend play state from backup file.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Commit the changes to backend.')
            ->addOption('assume-yes', null, InputOption::VALUE_NONE, 'Answer yes to understanding the risks.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select sub user.', 'main')
            ->addArgument('file', InputArgument::REQUIRED, 'Backup file to restore from')
            ->setHelp(
                r(
                    <<<HELP

                    This command allow you restore specific backend play state from backup file
                    generated via [<cmd>state:backup</cmd>] command.

                    This restore process only works on backends that has export enabled.

                    The restore process is exactly the same as the [<cmd>state:export</cmd>] with [<flag>--ignore-date</flag>, <flag>--force-full</flag>]
                    flags enabled, the difference is instead of reading state from database we are reading it from backup file.

                    -------------------
                    <notice>[ Risk Assessment ]</notice>
                    -------------------

                    If you are trying to restore a backend that has import play state enabled, the changes from restoring from backup file
                    will propagate back to your other backends. If you don't intend for that to happen, then <fg=white;bg=red;options=bold,underscore>DISABLE</> import from the backend.

                    --------------------------------
                    <notice>[ Enable restore functionality ]</notice>
                    --------------------------------

                    If you understand the risks and what might happen if you do restore from a backup file,
                    then you can enable the command by adding [<flag>--execute</flag>] to the command.

                    For example,

                    {cmd} <cmd>{route}</cmd> <flag>--execute</flag> <flag>-vv -s</flag> <value>backend_name</value> -- <value>{backupDir}/backup_file.json</value>

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># Restore operation is cancelled.</question>

                    If you encounter this error, it means either you didn't answer with yes for risk assessment confirmation,
                    or the interaction is disabled, if you can't enable interaction, then you can add another flag [<flag>--assume-yes</flag>]
                    to bypass the check. This <notice>confirms</notice> that you understand the risks of restoring backend that has import enabled.

                    <question># Ignoring [backend_name] [item_title]. [Movie|Episode] Is not imported yet.</question>

                    This is normal, this is likely because the backup is already outdated and some items in remote does not exist in backup file,
                    or you are using backup from another source which likely does not have matching data.

                    <question># Where are the backups stored?</question>

                    By default, it should be at [<value>{backupDir}</value>].

                    <question># How to see what data will be changed?</question>

                    if you do not add [<flag>--execute</flag>] to the comment, it will run in dry mode by default,
                    To see what data will be changed run the command with [<info>-v</info>]</info> log level.

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'backupDir' => after(Config::get('path') . '/backup', ROOT_PATH),
                    ]
                )
            );
    }

    /**
     * Make sure the command is not running in parallel.
     *
     * @param iInput $input The input interface containing the command arguments and options.
     * @param iOutput $output The output interface for displaying command output.
     *
     * @return int The exit code of the command.
     * @throws \JsonMachine\Exception\InvalidArgumentException If the file is not readable.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output);
    }

    /**
     * Execute the command.
     *
     * @param iInput $input The input interface object.
     * @param iOutput $output The output interface object.
     *
     * @return int The exit code indicating the success or failure of the process.
     * @throws \JsonMachine\Exception\InvalidArgumentException If the file is not readable.
     */
    protected function process(iInput $input, iOutput $output): int
    {
        $userName = $input->getOption('user');
        if (empty($userName)) {
            $output->writeln(r('<error>ERROR: User not specified. Please use [-u, --user].</error>'));
            return self::FAILURE;
        }

        $name = $input->getOption('select-backend');
        if (empty($name)) {
            $output->writeln(r('<error>ERROR: Backend not specified. Please use [-s, --select-backend].</error>'));
            return self::FAILURE;
        }

        $file = $input->getArgument('file');

        if (false === file_exists($file) || false === is_readable($file)) {
            $newFile = Config::get('path') . '/backup/' . $file;

            if (false === file_exists($newFile) || false === is_readable($newFile)) {
                $output->writeln(r("<error>ERROR: Unable to read backup file '{file}'.</error>", [
                    'file' => $file
                ]));
                return self::FAILURE;
            }

            $file = $newFile;
        }

        $opStart = makeDate();

        $mapper = new RestoreMapper($this->logger, $file);

        try {
            $userContext = getUserContext(user: $userName, mapper: $mapper, logger: $this->logger);
        } catch (RuntimeException $e) {
            $output->writeln(r("<error>{message}</error>", [
                'message' => $e->getMessage(),
            ]));
            return self::FAILURE;
        }

        if (null === ($backend = $userContext->config->get($name, null))) {
            $output->writeln(r("<error>ERROR: Backend '{user}@{backend}' not found.</error>", [
                'backend' => $name,
                'user' => $userContext->name,
            ]));
            return self::FAILURE;
        }

        if (false === (bool)ag($backend, 'export.enabled')) {
            $output->writeln(r("<error>ERROR: Export to '{user}@{backend}' are disabled.</error>", [
                'backend' => $name,
                'user' => $userContext->name,
            ]));
            return self::FAILURE;
        }

        if (true === (bool)ag($backend, 'import.enabled') && false === $input->getOption('assume-yes')) {
            $helper = $this->getHelper('question');
            $text =
                <<<TEXT
            <options=bold,underscore>Are you sure?</> <comment>[Y|N] [Default: No]</comment>
            -----------------
            You are about to restore backend that has imports enabled.

            <fg=white;bg=red;options=bold>The changes will propagate back to your backends.</>

            <comment>If you understand the risks then answer with [<info>yes</info>]
            If you don't please run same command with <info>[--help]</info> flag.
            </comment>
            -----------------
            TEXT;

            $question = new ConfirmationQuestion($text . PHP_EOL . '> ', false);

            if (false === $helper->ask($input, $output, $question)) {
                $output->writeln(
                    '<comment>Restore operation is cancelled, you answered no for risk assessment, or interaction is disabled.</comment>'
                );
                return self::SUCCESS;
            }
        }

        $this->logger->notice("Loading '{user}@{backend}' restore data.", [
            'backend' => $name,
            'user' => $userContext->name,
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        $start = makeDate();
        $mapper->loadData();
        $end = makeDate();

        $this->logger->notice(
            "SYSTEM: Loading restore data of '{user}@{backend}' completed in '{time.duration}'s. Memory usage '{memory.now}'.",
            [
                'backend' => $name,
                'user' => $userContext->name,
                'memory' => [
                    'now' => getMemoryUsage(),
                    'peak' => getPeakMemoryUsage(),
                ],
                'time' => [
                    'start' => $start,
                    'end' => $end,
                    'duration' => $end->getTimestamp() - $start->getTimestamp(),
                ],
            ]
        );

        if (false === $input->getOption('execute')) {
            $output->writeln('<info>No changes will be committed to backend.</info>');
        }

        $opts = [
            Options::IGNORE_DATE => true,
            Options::DEBUG_TRACE => true === $input->getOption('trace'),
            Options::DRY_RUN => false === $input->getOption('execute'),
        ];

        if ($input->getOption('timeout')) {
            $opts = ag_set($opts, 'client.timeout', $input->getOption('timeout'));
        }

        $backend['options'] = array_replace_recursive($backend['options'] ?? [], $opts);
        $backend = makeBackend(backend: $backend, name: $name, options: [
            UserContext::class => $userContext,
        ]);

        $this->logger->notice("Starting '{user}@{backend}' restore process.", [
            'backend' => $name,
            'user' => $userContext->name,
        ]);

        $requests = $backend->export($mapper, $this->queue, null);

        $start = makeDate();
        $this->logger->notice("SYSTEM: Sending '{total}' play state comparison requests for '{user}@{backend}'.", [
            'backend' => $name,
            'total' => count($requests),
            'user' => $userContext->name,
        ]);

        foreach ($requests as $response) {
            $requestData = $response->getInfo('user_data');
            try {
                $requestData['ok']($response);
            } catch (Throwable $e) {
                $requestData['error']($e);
            }
        }

        $end = makeDate();
        $this->logger->notice("SYSTEM: Completed '{total}' requests in '{time.duration}'s for '{user}@{backend}'.", [
            'backend' => $name,
            'total' => count($requests),
            'user' => $userContext->name,
            'time' => [
                'start' => $start,
                'end' => $end,
                'duration' => $end->getTimestamp() - $start->getTimestamp(),
            ],
        ]);

        $total = count($this->queue->getQueue());

        if ($total >= 1) {
            $this->logger->notice("SYSTEM: Sending '{total}' change state requests for '{user}@{backend}'.", [
                'backend' => $name,
                'user' => $userContext->name,
                'total' => $total
            ]);
        }

        if ((int)Message::get("{$userContext->name}.{$name}.export", 0) < 1) {
            $this->logger->notice("SYSTEM: No difference detected between backup file and '{user}@{backend}'.", [
                'backend' => $name,
                'user' => $userContext->name,
            ]);
        }
        
        if ($total < 1 || false === $input->getOption('execute')) {
            return self::SUCCESS;
        }

        foreach ($this->queue->getQueue() as $response) {
            $context = ag($response->getInfo('user_data'), 'context', []);
            $context['backend'] = $name;
            $context['user'] = $userContext->name;
            $context['client'] = $backend->getContext()->clientName;

            try {
                if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
                    $this->logger->error(
                        "Failed to change '{client}: {user}@{backend}' - '{item.title}' play state. Invalid HTTP '{status_code}' status code returned.",
                        $context
                    );
                    continue;
                }

                $this->logger->notice(
                    "Changed '{client}: {user}@{backend}' - '{{item.title}}' play state to '{play_state}'.",
                    $context
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    message: "Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' restore play state of {item.type} '{item.title}'. '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'backend' => $name,
                        'client' => $backend->getContext()->clientName,
                        'user' => $userContext->name,
                        ...$context,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                        ],
                    ]
                );
            }
        }

        $opEnd = makeDate();
        $this->logger->notice(
            "SYSTEM: Sent '{total}' change play state requests to '{client}: {user}@{backend}' in '{time.duration}'s.",
            [
                'total' => $total,
                'backend' => $name,
                'user' => $userContext->name,
                'client' => $backend->getContext()->clientName,
                'time' => [
                    'start' => $opStart,
                    'end' => $opEnd,
                    'duration' => $opEnd->getTimestamp() - $opStart->getTimestamp(),
                ],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Completes the input with suggestions for the 'file' argument.
     *
     * @param CompletionInput $input The completion input object.
     * @param CompletionSuggestions $suggestions The completion suggestions object.
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestArgumentValuesFor('file')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (new DirectoryIterator(Config::get('path') . '/backup/') as $name) {
                if (!$name->isFile()) {
                    continue;
                }

                if (empty($currentValue) || str_starts_with($name->getFilename(), $currentValue)) {
                    $suggest[] = $name->getFilename();
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
