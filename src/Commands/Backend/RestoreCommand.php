<?php

declare(strict_types=1);

namespace App\Commands\Backend;

use App\Backends\Common\ClientInterface as iClient;
use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\RetryableHttpClient;
use App\Libs\Extends\StreamLogHandler;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\RestoreMapper;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\Stream;
use App\Libs\UserContext;
use DirectoryIterator;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
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
    public function __construct(
        private readonly QueueRequests $queue,
        private readonly iLogger $logger,
        private LogSuppressor $suppressor,
        #[Inject(RetryableHttpClient::class)]
        private iHttp $http,
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Restore backend play state from backup file.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Commit the changes to backend.')
            ->addOption('assume-yes', null, InputOption::VALUE_NONE, 'Answer yes to understanding the risks.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select sub user.', 'main')
            ->addOption(
                'ignore',
                'i',
                InputOption::VALUE_NONE,
                'Bypass backend export.enabled check. Use with caution.',
            )
            ->addArgument('file', InputArgument::REQUIRED, 'Backup file to restore from')
            ->addOption(
                'sync-requests',
                null,
                InputOption::VALUE_NONE,
                'Send one request at a time instead of all at once. note: Slower but more reliable.',
            )
            ->addOption(
                'async-requests',
                null,
                InputOption::VALUE_NONE,
                'Send all requests at once. note: Faster but less reliable. Default.',
            )
            ->addOption('logfile', null, InputOption::VALUE_REQUIRED, 'Save console output to file.');
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
        if (null !== ($logfile = $input->getOption('logfile')) && true === $this->logger instanceof Logger) {
            $this->logger->setHandlers([
                $this->suppressor->withHandler(new StreamLogHandler(new Stream($logfile, 'w'), $output)),
            ]);
        }

        return $this->single(fn(): int => $this->process($input, $output), $output, [
            iLogger::class => $this->logger,
            Level::class => Level::Error,
        ]);
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
                    'file' => $file,
                ]));
                return self::FAILURE;
            }

            $file = $newFile;
        }

        $opStart = microtime(true);

        $mapper = new RestoreMapper($this->logger, $file);

        try {
            $userContext = get_user_context(user: $userName, mapper: $mapper, logger: $this->logger);
        } catch (RuntimeException $e) {
            $output->writeln(r('<error>{message}</error>', [
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

        if (false === (bool) ag($backend, 'export.enabled')) {
            if (false === $input->getOption('ignore')) {
                $output->writeln(r("<error>ERROR: Export to '{user}@{backend}' are disabled.</error>", [
                    'backend' => $name,
                    'user' => $userContext->name,
                ]));
                return self::FAILURE;
            }

            $this->logger->warning(
                "Restore target '{user}@{backend}' has export disabled; continuing because bypass was requested.",
                [
                    'event_name' => 'backend.restore.export_disabled_bypassed',
                    'subsystem' => 'backend.restore',
                    'operation' => 'validate_target',
                    'outcome' => 'warning',
                    'command' => self::ROUTE,
                    'backend' => $name,
                    'user' => $userContext->name,
                    'execute' => (bool) $input->getOption('execute'),
                    'reason' => 'bypass_requested',
                ],
            );
        }

        if (true === (bool) ag($backend, 'import.enabled')) {
            if (false === $input->getOption('assume-yes')) {
                $helper = $this->getHelper('question');
                $text = <<<TEXT
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
                        '<comment>Restore operation is cancelled, you answered no for risk assessment, or interaction is disabled.</comment>',
                    );
                    return self::SUCCESS;
                }
            } else {
                $this->logger->notice(
                    "The restore target '{user}@{backend}' has import enabled, which means the changes will propagate back to the other backends.",
                    [
                        'user' => $userContext->name,
                        'backend' => $name,
                    ],
                );
            }
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
        $backend = $this->makeBackend(backend: $backend, name: $name, userContext: $userContext);
        $client = $backend->getContext()->clientName;

        $this->logger->notice("Loading restore data for '{user}@{backend}' from '{path}'.", [
            'event_name' => 'backend.restore.data.loading',
            'subsystem' => 'backend.restore',
            'operation' => 'load_data',
            'outcome' => 'started',
            'command' => self::ROUTE,
            'backend' => $name,
            'user' => $userContext->name,
            'client' => $client,
            'path' => $file,
        ]);

        $start = microtime(true);
        $mapper->loadData();

        $this->logger->notice(
            "Loaded {item_count} restore items for '{user}@{backend}'.",
            [
                'event_name' => 'backend.restore.data.loaded',
                'subsystem' => 'backend.restore',
                'operation' => 'load_data',
                'outcome' => 'completed',
                'command' => self::ROUTE,
                'backend' => $name,
                'user' => $userContext->name,
                'client' => $client,
                'path' => $file,
                'item_count' => $mapper->getObjectsCount(),
                'duration_seconds' => round(microtime(true) - $start, 4),
            ],
        );

        if (false === ($syncRequests = $input->getOption('sync-requests'))) {
            $syncRequests = (bool) Config::get('http.default.sync_requests', false);
        }

        if (true === $input->getOption('async-requests')) {
            $syncRequests = false;
        }

        $this->logger->notice("Comparing restore data for '{user}@{backend}'.", [
            'event_name' => 'backend.restore.compare.started',
            'subsystem' => 'backend.restore',
            'operation' => 'compare',
            'outcome' => 'started',
            'command' => self::ROUTE,
            'backend' => $name,
            'user' => $userContext->name,
            'client' => $client,
            'local_count' => $mapper->getObjectsCount(),
        ]);

        $requests = $backend->export($mapper, $this->queue, null);

        $start = microtime(true);
        $this->sendRequests($requests, $syncRequests);

        $changeCount = (int) Message::get("{$userContext->name}.{$name}.export", 0);

        $this->logger->notice("Restore comparison for '{user}@{backend}' found {change_count} changes.", [
            'event_name' => 'backend.restore.compare.completed',
            'subsystem' => 'backend.restore',
            'operation' => 'compare',
            'outcome' => 'completed',
            'command' => self::ROUTE,
            'backend' => $name,
            'user' => $userContext->name,
            'client' => $client,
            'local_count' => $mapper->getObjectsCount(),
            'remote_count' => count($requests),
            'change_count' => $changeCount,
            'duration_seconds' => round(microtime(true) - $start, 4),
        ]);

        $total = count($this->queue->getQueue());

        if ($changeCount < 1) {
            $this->logger->notice("No restore differences found for '{user}@{backend}'.", [
                'event_name' => 'backend.restore.no_difference',
                'subsystem' => 'backend.restore',
                'operation' => 'compare',
                'outcome' => 'completed',
                'command' => self::ROUTE,
                'backend' => $name,
                'user' => $userContext->name,
                'client' => $client,
            ]);
        }

        if ($total < 1 || false === $input->getOption('execute')) {
            if (false === $input->getOption('execute')) {
                $this->logger->notice("Restore cancelled for '{user}@{backend}': dry-run mode is enabled.", [
                    'event_name' => 'backend.restore.cancelled',
                    'subsystem' => 'backend.restore',
                    'operation' => 'commit',
                    'outcome' => 'cancelled',
                    'command' => self::ROUTE,
                    'backend' => $name,
                    'user' => $userContext->name,
                    'reason' => 'dry_run',
                    'reason_label' => 'dry-run mode is enabled',
                ]);
            }

            return self::SUCCESS;
        }

        $this->sendRequests($this->queue->getQueue(), $syncRequests);

        $this->logger->notice(
            "Sent {request_count} restore play-state requests to '{user}@{backend}' via {client}.",
            [
                'event_name' => 'backend.restore.changes.sent',
                'subsystem' => 'backend.restore',
                'operation' => 'commit',
                'outcome' => 'completed',
                'command' => self::ROUTE,
                'request_count' => $total,
                'backend' => $name,
                'user' => $userContext->name,
                'client' => $client,
                'duration_seconds' => round(microtime(true) - $opStart, 4),
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Create backend client instance.
     *
     * @param array<string,mixed> $backend
     */
    protected function makeBackend(array $backend, string $name, UserContext $userContext): iClient
    {
        return make_backend(backend: $backend, name: $name, options: [
            UserContext::class => $userContext,
        ]);
    }

    /**
     * Send queued backend requests.
     *
     * @param array<array-key,mixed> $requests
     */
    protected function sendRequests(array $requests, bool $syncRequests): void
    {
        send_requests(requests: $requests, client: $this->http, sync: $syncRequests, logger: $this->logger);
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
