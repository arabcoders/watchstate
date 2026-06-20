<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Request;
use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\RetryableHttpClient;
use App\Libs\Extends\StreamLogHandler;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\Stream;
use App\Libs\UserContext;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

/**
 * Class ImportCommand
 *
 * This command imports metadata and play state of items from backends and updates the local database.
 */
#[Cli(command: self::ROUTE)]
class ImportCommand extends Command
{
    public const string ROUTE = 'state:import';

    public const string TASK_NAME = 'import';

    /**
     * Class Constructor.
     *
     * @param iImport $mapper The import interface object.
     * @param iLogger $logger The logger interface object.
     * @param LogSuppressor $suppressor The log suppressor object.
     *
     */
    public function __construct(
        #[Inject(DirectMapper::class)]
        private readonly iImport $mapper,
        private readonly iLogger $logger,
        private readonly LogSuppressor $suppressor,
        #[Inject(RetryableHttpClient::class)]
        private readonly iHttp $http,
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    /**
     * Configure the method.
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Import play state and metadata from backends.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full import. Ignore last sync date.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit any changes.')
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
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user. Default is all users.')
            ->addOption(
                'select-backend',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Select backend.',
            )
            ->addOption(
                'exclude',
                'e',
                InputOption::VALUE_NONE,
                'Inverse --select-backend logic. Exclude selected backends.',
            )
            ->addOption(
                'select-library',
                'S',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Select library ids.',
            )
            ->addOption(
                'exclude-library',
                'I',
                InputOption::VALUE_NONE,
                'Inverse --select-library logic. Exclude selected libraries.',
            )
            ->addOption(
                'metadata-only',
                null,
                InputOption::VALUE_NONE,
                'import metadata changes only. Works when there are records in database.',
            )
            ->addOption(
                'always-update-metadata',
                null,
                InputOption::VALUE_NONE,
                'Replace existing metadata with data from backend even if there is no change.',
            )
            ->addOption('show-messages', null, InputOption::VALUE_NONE, 'Show internal messages.')
            ->addOption('logfile', null, InputOption::VALUE_REQUIRED, 'Save console output to file.');
    }

    /**
     * Make sure the command is not running in parallel.
     *
     * @param InputInterface $input The input interface object.
     * @param OutputInterface $output The output interface object.
     *
     * @return int The status code of the command execution.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output, [
            iLogger::class => $this->logger,
            Level::class => Level::Error,
        ]);
    }

    /**
     * Import the state from the backends.
     *
     * @param InputInterface $input The input interface object.
     * @param OutputInterface $output The output interface object.
     *
     * @return int The return status code.
     */
    protected function process(InputInterface $input, OutputInterface $output): int
    {
        if (null !== ($logfile = $input->getOption('logfile')) && true === $this->logger instanceof Logger) {
            $this->logger->setHandlers([
                $this->suppressor->withHandler(new StreamLogHandler(new Stream($logfile, 'w'), $output)),
            ]);
        }

        $mapperOpts = $dbOpts = [];

        if ($input->getOption('dry-run')) {
            $this->logger->notice('Dry run mode. No changes will be committed.');
            $mapperOpts[Options::DRY_RUN] = true;
        }

        if (true === (bool) Config::get('guid.disable.episode', false)) {
            $this->logger->info('Matching episodes via GUID is disabled.');
        }

        if ($input->getOption('trace')) {
            $mapperOpts[Options::DEBUG_TRACE] = true;
            $dbOpts[Options::DEBUG_TRACE] = true;
        }

        if ($input->getOption('always-update-metadata')) {
            $mapperOpts[Options::MAPPER_ALWAYS_UPDATE_META] = true;
        }

        if (false === ($syncRequests = $input->getOption('sync-requests'))) {
            $syncRequests = (bool) Config::get('http.default.sync_requests', false);
        }

        if (true === $input->getOption('async-requests')) {
            $syncRequests = false;
        }

        $this->mapper->setOptions($mapperOpts);

        try {
            $selectedUsers = select_users($input->getOption('user'));
        } catch (RuntimeException $e) {
            $this->logger->error(
                'Failed to resolve import users. {exception.message}',
                [
                    'operation' => 'import.resolve_users',
                    ...exception_log($e),
                ],
            );

            return self::FAILURE;
        }

        $users = array_map(
            fn(string $user): UserContext => get_user_context($user, $this->mapper, $this->logger),
            $selectedUsers,
        );

        foreach ($users as $userContext) {
            if ([] === $dbOpts) {
                continue;
            }

            $userContext->db->setOptions($dbOpts);
        }

        $selected = $input->getOption('select-backend');
        $isCustom = !empty($selected) && count($selected) > 0;
        $selectLibrary = $this->parseLibraryIds($input->getOption('select-library'));
        $hasLibrarySelect = count($selectLibrary) > 0;
        $inverseLibrarySelect = true === $input->getOption('exclude-library');
        $supported = Config::get('supported', []);

        $totalStartTime = microtime(true);

        $this->logger->notice('Using WatchState {full_version}', [
            'full_version' => get_full_version(),
        ]);

        $this->logger->notice("Starting import process for '{total}' users", ['total' => count($users)]);

        foreach ($users as $userContext) {
            $list = [];
            $userStart = microtime(true);

            $this->logger->notice("Importing user '{identity.user}' play states.", [
                'identity' => [
                    'user' => $userContext->name,
                ],
                'backends' => implode(', ', array_keys($list)),
            ]);

            foreach ($userContext->config->getAll() as $backendName => $backend) {
                $type = strtolower(ag($backend, 'type', 'unknown'));
                $importEnabled = true === (bool) ag($backend, 'import.enabled');
                $metadata = false === $importEnabled;

                if ($isCustom && $input->getOption('exclude') === $this->in_array($selected, $backendName)) {
                    $this->logger->info("Ignoring '{identity.user}@{identity.backend}' as requested.", [
                        'identity' => [
                            'user' => $userContext->name,
                            'backend' => $backendName,
                        ],
                    ]);
                    continue;
                }

                if (true === $input->getOption('metadata-only')) {
                    $metadata = true;
                }

                if (!isset($supported[$type])) {
                    $this->logger->error("Ignoring '{identity.user}@{identity.backend}'. Unexpected type '{type}'.", [
                        'operation' => 'command.backend_config',
                        'error' => 'unexpected_backend_type',
                        'identity' => [
                            'user' => $userContext->name,
                            'backend' => $backendName,
                        ],
                        'type' => $type,
                        'types' => implode(', ', array_keys($supported)),
                    ]);
                    continue;
                }

                if (null === ($url = ag($backend, 'url')) || false === is_valid_url($url)) {
                    $this->logger->error("Ignoring '{identity.user}@{identity.backend}'. Invalid URL '{url}'.", [
                        'operation' => 'command.backend_config',
                        'error' => 'invalid_url',
                        'identity' => [
                            'user' => $userContext->name,
                            'backend' => $backendName,
                        ],
                        'url' => $url ?? 'None',
                    ]);
                    continue;
                }

                $backend['name'] = $backendName;
                $list[$backendName] = $backend;
            }

            if (empty($list)) {
                $this->logger->warning(
                    $isCustom
                        ? r("[-s, --select-backend] flag did not match any backend for '{user}'.", [
                            'user' => $userContext->name,
                        ]) : 'No backends were found for import.',
                );
                continue;
            }

            $list = $this->sortBackends($list, true === $input->getOption('metadata-only'));

            /** @var array<array-key,Request> $queue */
            $queue = [];

            $this->logger->notice(
                message: "Preloading user '{identity.user}: {mapper}' data. Memory usage '{memory.now}'.",
                context: [
                    'identity' => [
                        'user' => $userContext->name,
                    ],
                    'mapper' => after_last($userContext->mapper::class, '\\'),
                    'memory' => [
                        'now' => get_memory_usage(),
                        'peak' => get_peak_memory_usage(),
                    ],
                ],
            );

            $time = microtime(true);
            $userContext->mapper->reset()->loadData();

            $this->logger->notice(
                message: "Preloading user '{identity.user}: {mapper}' data completed in '{duration}s'. Memory usage '{memory.now}'.",
                context: [
                    'identity' => [
                        'user' => $userContext->name,
                    ],
                    'mapper' => after_last($userContext->mapper::class, '\\'),
                    'duration' => round(microtime(true) - $time, 4),
                    'memory' => [
                        'now' => get_memory_usage(),
                        'peak' => get_peak_memory_usage(),
                    ],
                ],
            );

            foreach ($list as $name => &$backend) {
                $metadata = true !== (bool) ag($backend, 'import.enabled');
                $opts = ag($backend, 'options', []);

                if (true === $hasLibrarySelect) {
                    $opts[Options::LIBRARY_SELECT] = $selectLibrary;
                    $opts[Options::LIBRARY_INVERSE] = $inverseLibrarySelect;
                }

                if (true === $input->getOption('metadata-only')) {
                    $opts[Options::IMPORT_METADATA_ONLY] = true;
                    $metadata = true;
                }

                if (true === $metadata) {
                    $opts[Options::IMPORT_METADATA_ONLY] = true;
                }

                if ($input->getOption('trace')) {
                    $opts[Options::DEBUG_TRACE] = true;
                }

                if ($input->getOption('timeout')) {
                    $opts['client']['timeout'] = (float) $input->getOption('timeout');
                }

                $after = ag($backend, 'import.lastSync', null);

                $forceFull = true === (bool) ag($opts, Options::FORCE_FULL, false) || true === $input->getOption('force-full');

                if (true === $forceFull) {
                    $after = null;
                    $opts[Options::FORCE_FULL] = true;
                }

                $backend['options'] = $opts;
                $backend['class'] = $this->makeBackend($backend, $name, $userContext);

                if (null !== $after) {
                    $after = make_date($after);
                }

                $this->logger->notice("Importing '{identity.user}@{identity.backend}' {import_type} changes.", [
                    'identity' => [
                        'user' => $userContext->name,
                        'backend' => $name,
                    ],
                    'import_type' => true === $metadata ? 'metadata' : 'metadata & play state',
                    'since' => null === $after ? 'Beginning' : (string) $after,
                ]);

                array_push($queue, ...$backend['class']->pull($userContext->mapper, $after));

                $inDryMode = $userContext->mapper->inDryRunMode() || ag($backend, 'options.' . Options::DRY_RUN);

                if (false === $inDryMode) {
                    if (true === (bool) Message::get("{$name}.has_errors")) {
                        $this->logger->warning(
                            message: "Not updating '{identity.user}@{identity.backend}' import last sync date due to errors recorded during the operation.",
                            context: [
                                'operation' => 'import.sync_date',
                                'error' => 'skipped_due_to_errors',
                                'identity' => [
                                    'user' => $userContext->name,
                                    'backend' => $name,
                                ],
                            ],
                        );
                    } else {
                        $userContext->config->set("{$name}.import.lastSync", time());
                    }
                }
            }

            unset($backend);

            $start = microtime(true);
            $this->logger->notice("Waiting on '{total}' {sync}requests for '{identity.user}' backends.", [
                'identity' => [
                    'user' => $userContext->name,
                ],
                'total' => number_format(count($queue)),
                'sync' => $syncRequests ? 'sync ' : '',
                'memory' => [
                    'now' => get_memory_usage(),
                    'peak' => get_peak_memory_usage(),
                ],
            ]);

            try {
                $userContext->db->transactional(fn() => $this->sendRequests($queue, $syncRequests));
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "Import requests for '{identity.user}' backends failed. {exception.message}",
                        context: [
                            'identity' => [
                                'user' => $userContext->name,
                            ],
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );

                throw $e;
            }

            $this->logger->notice(
                "Completed '{total}' requests in '{duration}'s for '{identity.user}' backends. Parsed '{responses.size}' of data.",
                [
                    'identity' => [
                        'user' => $userContext->name,
                    ],
                    'total' => number_format(count($queue)),
                    'duration' => round(microtime(true) - $start, 4),
                    'memory' => [
                        'now' => get_memory_usage(),
                        'peak' => get_peak_memory_usage(),
                    ],
                    'responses' => [
                        'size' => fsize((int) Message::get('response.size', 0)),
                    ],
                ],
            );

            $queue = null;

            $total = count($userContext->mapper);

            if ($total >= 1) {
                $this->logger->notice("Found '{total}' updated items from '{identity.user}' backends.", [
                    'identity' => [
                        'user' => $userContext->name,
                    ],
                    'total' => $total,
                    'memory' => [
                        'now' => get_memory_usage(),
                        'peak' => get_peak_memory_usage(),
                    ],
                ]);
            }

            $operations = $userContext->mapper->commit();

            Message::reset();
            $userContext->mapper->reset();

            $this->logger->info(
                "Importing '{identity.user}' play states completed in '{duration}'s. Memory usage '{memory.now}'.",
                [
                    'identity' => [
                        'user' => $userContext->name,
                    ],
                    'backends' => implode(', ', array_keys($list)),
                    'duration' => round(microtime(true) - $userStart, 4),
                    'memory' => [
                        'now' => get_memory_usage(),
                        'peak' => get_peak_memory_usage(),
                    ],
                ],
            );

            $this->logger->notice(
                "Imported '{identity.user}' play states: {movie.added} added, {movie.updated} updated, {movie.failed} failed (movies); {episode.added} added, {episode.updated} updated, {episode.failed} failed (episodes).",
                [
                    'operation' => 'import.summary',
                    'identity' => [
                        'user' => $userContext->name,
                    ],
                    'movie' => [
                        'added' => $operations[iState::TYPE_MOVIE]['added'] ?? 0,
                        'updated' => $operations[iState::TYPE_MOVIE]['updated'] ?? 0,
                        'failed' => $operations[iState::TYPE_MOVIE]['failed'] ?? 0,
                    ],
                    'episode' => [
                        'added' => $operations[iState::TYPE_EPISODE]['added'] ?? 0,
                        'updated' => $operations[iState::TYPE_EPISODE]['updated'] ?? 0,
                        'failed' => $operations[iState::TYPE_EPISODE]['failed'] ?? 0,
                    ],
                ],
            );

            if (false === $input->getOption('dry-run')) {
                $userContext->config->persist();
            }

            if ($input->getOption('show-messages')) {
                $this->displayContent(
                    Message::getAll(),
                    $output,
                    $input->getOption('output') === 'json' ? 'json' : 'yaml',
                );
            }
        }

        $this->logger->notice("Import process completed in '{duration}'s for all users.", [
            'duration' => round(microtime(true) - $totalStartTime, 4),
        ]);

        $this->logger->notice('Using WatchState {full_version}', [
            'full_version' => get_full_version(),
        ]);

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $backend
     */
    protected function makeBackend(array $backend, string $name, UserContext $userContext): iClient
    {
        return make_backend(backend: $backend, name: $name, options: [
            UserContext::class => $userContext,
        ]);
    }

    /**
     * @param array<int,Request> $queue
     */
    protected function sendRequests(array $queue, bool $syncRequests): void
    {
        send_requests(requests: $queue, client: $this->http, sync: $syncRequests, logger: $this->logger);
    }

    private function in_array(array $list, string $search): bool
    {
        return array_any($list, static fn($item) => str_starts_with($search, $item));
    }

    /**
     * @param array|string|null $value
     *
     * @return array<string>
     */
    private function parseLibraryIds(array|string|null $value): array
    {
        if (null === $value) {
            return [];
        }

        if (true === is_string($value)) {
            $value = explode(',', $value);
        }

        $ids = array_filter(array_map(trim(...), $value), static fn($item) => '' !== $item);

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string,array<string,mixed>> $backends
     *
     * @return array<string,array<string,mixed>>
     */
    private function sortBackends(array $backends, bool $forceMetadataOnly = false): array
    {
        $full = [];
        $metadata = [];

        foreach ($backends as $name => $backend) {
            $isMetadataOnly = true === $forceMetadataOnly || true !== (bool) ag($backend, 'import.enabled');

            if (true === $isMetadataOnly) {
                $metadata[$name] = $backend;
                continue;
            }

            $full[$name] = $backend;
        }

        return [...$full, ...$metadata];
    }
}
