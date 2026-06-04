<?php

declare(strict_types=1);

namespace App\Commands\Backend;

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
            ->addOption('restore-watch-progress', 'P', InputOption::VALUE_NONE, 'Restore the watch progress from backup as well.')
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
        $this->queue->reset();

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
                "Exporting to '{user}@{backend}' is disabled, However, the check was bypass due to [-i, --ignore] flag being used.",
                [
                    'backend' => $name,
                    'user' => $userContext->name,
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

        $this->logger->notice("Loading '{user}@{backend}' restore data.", [
            'backend' => $name,
            'user' => $userContext->name,
            'memory' => [
                'now' => get_memory_usage(),
                'peak' => get_peak_memory_usage(),
            ],
        ]);

        $start = microtime(true);
        $mapper->loadData();

        $this->logger->notice(
            "SYSTEM: Loading restore data of '{user}@{backend}' completed in '{duration}'s. Memory usage '{memory.now}'.",
            [
                'backend' => $name,
                'user' => $userContext->name,
                'memory' => [
                    'now' => get_memory_usage(),
                    'peak' => get_peak_memory_usage(),
                ],
                'duration' => round(microtime(true) - $start, 4),
            ],
        );

        if (false === $input->getOption('execute')) {
            $this->logger->notice(
                "No changes will be committed to backend. To execute the changes pass '--execute' flag option.",
            );
        }

        $opts = [
            Options::IGNORE_DATE => true,
            Options::REPLAY_PROGRESS => true === $input->getOption('restore-watch-progress'),
            Options::DEBUG_TRACE => true === $input->getOption('trace'),
            Options::DRY_RUN => false === $input->getOption('execute'),
        ];

        if ($input->getOption('timeout')) {
            $opts = ag_set($opts, 'client.timeout', $input->getOption('timeout'));
        }

        $backend['options'] = array_replace_recursive($backend['options'] ?? [], $opts);
        $backend = make_backend(backend: $backend, name: $name, options: [
            UserContext::class => $userContext,
        ]);

        $this->logger->notice("Starting '{user}@{backend}' restore process.", [
            'backend' => $name,
            'user' => $userContext->name,
        ]);

        if (false === ($syncRequests = $input->getOption('sync-requests'))) {
            $syncRequests = (bool) Config::get('http.default.sync_requests', false);
        }

        if (true === $input->getOption('async-requests')) {
            $syncRequests = false;
        }

        try {
            $requests = $backend->export($mapper, $this->queue, null);

            $stats = ['sent' => count($requests), 'failed' => 0];

            $start = microtime(true);
            $this->logger->notice("SYSTEM: Sending '{total}' play state comparison requests for '{user}@{backend}'.", [
                'backend' => $name,
                'total' => $stats['sent'],
                'user' => $userContext->name,
            ]);

            send_requests(
                requests: $requests,
                client: $this->http,
                sync: $syncRequests,
                logger: $this->logger,
                opts: [
                    'error' => static function () use (&$stats): array {
                        $stats['failed']++;
                        return [];
                    },
                ],
            );

            $this->logger->notice("SYSTEM: Completed '{total}' requests in '{duration}'s for '{user}@{backend}'.", [
                'backend' => $name,
                'total' => $stats['sent'],
                'user' => $userContext->name,
                'duration' => round(microtime(true) - $start, 4),
            ]);

            $total = count($this->queue->getQueue());
            $sendStats = ['sent' => $total, 'failed' => 0];

            if ($total >= 1) {
                $this->logger->notice("SYSTEM: Sending '{total}' change state requests for '{user}@{backend}'.", [
                    'backend' => $name,
                    'user' => $userContext->name,
                    'total' => $total,
                ]);
            }

            if ((int) Message::get("{$userContext->name}.{$name}.export", 0) < 1) {
                $this->logger->notice("SYSTEM: No difference detected between backup file and '{user}@{backend}'.", [
                    'backend' => $name,
                    'user' => $userContext->name,
                ]);
            }

            if ($total >= 1 && false !== $input->getOption('execute')) {
                send_requests(
                    requests: $this->queue->getQueue(),
                    client: $this->http,
                    sync: $syncRequests,
                    logger: $this->logger,
                    opts: [
                        'error' => static function () use (&$sendStats): array {
                            $sendStats['failed']++;
                            return [];
                        },
                    ],
                );

                $this->logger->notice(
                    "SYSTEM: Sent '{total}' change play state requests to '{client}: {user}@{backend}' in '{duration}'s.",
                    [
                        'total' => $total,
                        'backend' => $name,
                        'user' => $userContext->name,
                        'client' => $backend->getContext()->clientName,
                        'duration' => round(microtime(true) - $opStart, 4),
                    ],
                );
            }

            $progressStats = ['sent' => 0, 'failed' => 0];

            if (true === $input->getOption('restore-watch-progress')) {
                $this->queue->reset();
                $backend->progress($mapper->getObjects(), $this->queue, null);

                $progressStats['sent'] = count($this->queue->getQueue());

                if ($progressStats['sent'] >= 1) {
                    $this->logger->notice("SYSTEM: Sending '{total}' watch progress requests for '{user}@{backend}'.", [
                        'backend' => $name,
                        'user' => $userContext->name,
                        'total' => $progressStats['sent'],
                    ]);
                } else {
                    $this->logger->notice("SYSTEM: No watch progress changes detected between backup file and '{user}@{backend}'.", [
                        'backend' => $name,
                        'user' => $userContext->name,
                    ]);
                }

                if ($progressStats['sent'] >= 1 && false !== $input->getOption('execute')) {
                    send_requests(
                        requests: $this->queue->getQueue(),
                        client: $this->http,
                        sync: $syncRequests,
                        logger: $this->logger,
                        opts: [
                            'error' => static function () use (&$progressStats): array {
                                $progressStats['failed']++;
                                return [];
                            },
                        ],
                    );

                    $this->logger->notice(
                        "SYSTEM: Sent '{total}' watch progress requests to '{client}: {user}@{backend}' in '{duration}'s.",
                        [
                            'total' => $progressStats['sent'],
                            'backend' => $name,
                            'user' => $userContext->name,
                            'client' => $backend->getContext()->clientName,
                            'duration' => round(microtime(true) - $opStart, 4),
                        ],
                    );
                }
            }

            if ($stats['failed'] > 0 || $sendStats['failed'] > 0 || $progressStats['failed'] > 0) {
                $this->logger->warning(
                    "Restore completed with item-level request failures for '{user}@{backend}'.",
                    [
                        'user' => $userContext->name,
                        'backend' => $name,
                        'comparison' => $stats,
                        'apply' => $sendStats,
                        'progress' => $progressStats,
                    ],
                );
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->logger->error(
                "SYSTEM: Unhandled exception '{error.kind}' was thrown during '{user}@{backend}' restore operation. '{error.message}' at '{error.file}:{error.line}'.",
                [
                    'user' => $userContext->name,
                    'backend' => $name,
                    'error' => [
                        'kind' => $e::class,
                        'line' => $e->getLine(),
                        'message' => $e->getMessage(),
                        'file' => after($e->getFile(), ROOT_PATH),
                    ],
                ],
            );

            return self::FAILURE;
        } finally {
            $this->queue->reset();
        }
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
