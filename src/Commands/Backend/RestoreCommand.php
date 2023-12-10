<?php

declare(strict_types=1);

namespace App\Commands\Backend;

use App\Command;
use App\Libs\Config;
use App\Libs\Mappers\Import\RestoreMapper;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\Routable;
use DirectoryIterator;
use Psr\Log\LoggerInterface as iLogger;
use RuntimeException;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;
use Throwable;

#[Routable(command: self::ROUTE)]
class RestoreCommand extends Command
{
    public const ROUTE = 'backend:restore';

    public const TASK_NAME = 'export';

    public function __construct(private QueueRequests $queue, private iLogger $logger)
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Restore backend play state from backup file.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Commit the changes to backend.')
            ->addOption('assume-yes', null, InputOption::VALUE_NONE, 'Answer yes to understanding the risks.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name to restore.')
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

                    {cmd} <cmd>{route}</cmd> <flag>--execute</flag> <flag>-vv</flag> -- <value>backend_name</value> <value>{backupDir}/backup_file.json</value>

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
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \JsonMachine\Exception\InvalidArgumentException
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output);
    }

    /**
     * @throws \JsonMachine\Exception\InvalidArgumentException
     */
    protected function process(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('backend');
        $file = $input->getArgument('file');

        if (false === file_exists($file) || false === is_readable($file)) {
            $newFile = Config::get('path') . '/backup/' . $file;

            if (false === file_exists($newFile) || false === is_readable($newFile)) {
                $output->writeln(sprintf('<error>ERROR: Unable to find or read backup file \'%s\'.</error>', $file));
                return self::FAILURE;
            }

            $file = $newFile;
        }

        if (($config = $input->getOption('config'))) {
            try {
                Config::save('servers', Yaml::parseFile($this->checkCustomBackendsFile($config)));
            } catch (RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        }

        if (null === ($backend = ag(Config::get('servers', []), $name, null))) {
            $output->writeln(sprintf('<error>ERROR: Backend \'%s\' not found.</error>', $name));
            return self::FAILURE;
        }

        if (false === (bool)ag($backend, 'export.enabled')) {
            $output->writeln(sprintf('<error>ERROR: Export to \'%s\' are disabled.</error>', $name));
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

            <comment>If you understand the risks then answer with <info>[yes]</info>
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


        $this->logger->notice('SYSTEM: Loading restore data.', [
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        $mapper = (new RestoreMapper($this->logger, $file))->loadData();

        $this->logger->notice('SYSTEM: Loading restore data is complete.', [
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        if (false === $input->getOption('execute')) {
            $output->writeln('<info>No changes will be committed to backend.</info>');
        }

        $opts = [
            'options' => [
                Options::IGNORE_DATE => true,
                Options::DEBUG_TRACE => true === $input->getOption('trace'),
                Options::DRY_RUN => false === $input->getOption('execute'),
            ],
        ];

        if ($input->getOption('timeout')) {
            $opts = ag_set($opts, 'options.client.timeout', $input->getOption('timeout'));
        }

        $backend = $this->getBackend($name, $opts);

        $this->logger->notice('Starting Restore process');

        $requests = $backend->export($mapper, $this->queue, null);

        $this->logger->notice('SYSTEM: Sending [{total}] play state comparison requests.', [
            'total' => count($requests),
        ]);

        foreach ($requests as $response) {
            $requestData = $response->getInfo('user_data');
            try {
                $requestData['ok']($response);
            } catch (Throwable $e) {
                $requestData['error']($e);
            }
        }

        $this->logger->notice('SYSTEM: Sent [{total}] play state comparison requests.', [
            'total' => count($requests),
        ]);

        $total = count($this->queue->getQueue());

        if ($total >= 1) {
            $this->logger->notice('SYSTEM: Sending [{total}] change play state requests.', [
                'total' => $total
            ]);
        } else {
            $this->logger->notice('SYSTEM: No difference detected between backup file and backend.');
        }

        if ($total < 1 || false === $input->getOption('execute')) {
            return self::SUCCESS;
        }

        foreach ($this->queue->getQueue() as $response) {
            $context = ag($response->getInfo('user_data'), 'context', []);

            try {
                if (200 !== $response->getStatusCode()) {
                    $this->logger->error(
                        'Request to change [{backend}] [{item.title}] play state returned with unexpected [{status_code}] status code.',
                        $context
                    );
                    continue;
                }

                $this->logger->notice('Marked [{backend}] [{item.title}] as [{play_state}].', $context);
            } catch (Throwable $e) {
                $this->logger->error(
                    message: 'Exception [{error.kind}] was thrown unhandled during [{backend}] restore play state of {item.type} [{item.title}]. Error [{error.message} @ {error.file}:{error.line}].',
                    context: [
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

        $this->logger->notice('SYSTEM: Sent [{total}] change play state requests.', [
            'total' => $total
        ]);

        return self::SUCCESS;
    }

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
