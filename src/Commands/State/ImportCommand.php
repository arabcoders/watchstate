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
            ->addOption(
                'force-replace-metadata',
                'F',
                InputOption::VALUE_NONE,
                'Replace existing metadata with data from backend even if there is no change.',
            )
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
                'Mapper option. Always update the locally stored metadata from backend.',
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
        $dryRun = true === (bool) $input->getOption('dry-run');
        $forceFullRequested = true === (bool) $input->getOption('force-full');

        if (true === $dryRun) {
            $this->logger->notice('Dry run enabled; no changes will be committed.', [
                'event_name' => 'state.import.dry_run.enabled',
                'subsystem' => 'state.import',
                'operation' => 'import',
                'outcome' => 'started',
                'command' => self::ROUTE,
                'dry_run' => true,
            ]);
            $mapperOpts[Options::DRY_RUN] = true;
        }

        if (true === (bool) Config::get('guid.disable.episode', false)) {
            $this->logger->notice('Episode GUID matching is disabled for this import run.', [
                'event_name' => 'state.import.mapper_guid_disabled',
                'subsystem' => 'state.import',
                'operation' => 'configure_mapper',
                'outcome' => 'completed',
                'command' => self::ROUTE,
                'mapper' => after_last($this->mapper::class, '\\'),
                'reason' => 'config_disabled',
            ]);
        }

        if ($input->getOption('trace')) {
            $mapperOpts[Options::DEBUG_TRACE] = true;
            $dbOpts[Options::DEBUG_TRACE] = true;
        }

        if ($input->getOption('always-update-metadata')) {
            $mapperOpts[Options::MAPPER_ALWAYS_UPDATE_META] = true;
        }

        if ($input->getOption('force-replace-metadata')) {
            $mapperOpts[Options::FORCE_REPLACE_METADATA] = true;
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
            $output->writeln(r('<error>{message}</error>', [
                'message' => $e->getMessage(),
            ]));

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
        $selection = [
            'mode' => $this->resolveSelectionMode((array) $selected, true === (bool) $input->getOption('exclude')),
            'backends' => array_values(array_filter(
                array_map(trim(...), array_map('strval', (array) $selected)),
                static fn(string $item): bool => '' !== $item,
            )),
        ];

        $totalStartTime = microtime(true);

        $this->logger->notice('Using WatchState {version.full}.', [
            'event_name' => 'app.version',
            'subsystem' => 'app',
            'operation' => 'version',
            'outcome' => 'resolved',
            'command' => self::ROUTE,
            'version' => [
                'full' => get_full_version(),
            ],
        ]);

        $this->logger->notice('Import started for {user_count} users.', [
            'event_name' => 'state.import.started',
            'subsystem' => 'state.import',
            'operation' => 'import',
            'outcome' => 'started',
            'command' => self::ROUTE,
            'user_count' => count($users),
            'dry_run' => $dryRun,
            'force_full' => $forceFullRequested,
            'selection' => $selection,
        ]);

        foreach ($users as $userContext) {
            $list = [];
            $userStart = microtime(true);

            $this->logger->notice("Importing play states for '{user}'.", [
                'event_name' => 'state.import.user.started',
                'subsystem' => 'state.import',
                'operation' => 'import',
                'outcome' => 'started',
                'command' => self::ROUTE,
                'user' => $userContext->name,
                'memory' => [
                    'now' => get_memory_usage(),
                    'peak' => get_peak_memory_usage(),
                ],
            ]);

            foreach ($userContext->config->getAll() as $backendName => $backend) {
                $type = strtolower(ag($backend, 'type', 'unknown'));
                $importEnabled = true === (bool) ag($backend, 'import.enabled');
                $metadata = false === $importEnabled;

                if ($isCustom && $input->getOption('exclude') === $this->in_array($selected, $backendName)) {
                    $this->logger->info("Skipping '{user}@{backend}': excluded by selection.", [
                        'event_name' => 'state.import.backend.skipped',
                        'subsystem' => 'state.import',
                        'operation' => 'select_backend',
                        'outcome' => 'skipped',
                        'command' => self::ROUTE,
                        'user' => $userContext->name,
                        'backend' => $backendName,
                        'reason' => 'selected_excluded',
                        'selection' => $selection,
                    ]);
                    continue;
                }

                if (true === $input->getOption('metadata-only')) {
                    $metadata = true;
                }

                if (!isset($supported[$type])) {
                    $this->logger->warning("Skipping '{user}@{backend}': backend type '{backend_type}' is unsupported.", [
                        'event_name' => 'state.import.backend.skipped',
                        'subsystem' => 'state.import',
                        'operation' => 'select_backend',
                        'outcome' => 'skipped',
                        'command' => self::ROUTE,
                        'user' => $userContext->name,
                        'backend_type' => $type,
                        'backend' => $backendName,
                        'types' => implode(', ', array_keys($supported)),
                        'reason' => 'unsupported_type',
                    ]);
                    continue;
                }

                if (null === ($url = ag($backend, 'url')) || false === is_valid_url($url)) {
                    $this->logger->warning("Skipping '{user}@{backend}': URL '{url}' is invalid.", [
                        'event_name' => 'state.import.backend.skipped',
                        'subsystem' => 'state.import',
                        'operation' => 'select_backend',
                        'outcome' => 'skipped',
                        'command' => self::ROUTE,
                        'user' => $userContext->name,
                        'url' => $url ?? 'None',
                        'backend' => $backendName,
                        'reason' => 'invalid_url',
                    ]);
                    continue;
                }

                $backend['name'] = $backendName;
                $list[$backendName] = $backend;
            }

            if (empty($list)) {
                $this->logger->warning(
                    $isCustom
                        ? "No selected backends matched for '{user}'."
                        : "No import backends were available for '{user}'.",
                    [
                        'event_name' => 'state.import.backend.none_selected',
                        'subsystem' => 'state.import',
                        'operation' => 'select_backend',
                        'outcome' => 'skipped',
                        'command' => self::ROUTE,
                        'user' => $userContext->name,
                        'reason' => true === $isCustom ? 'selection_no_match' : 'no_backends',
                        'selection' => $selection,
                    ],
                );
                continue;
            }

            $list = $this->sortBackends($list, true === $input->getOption('metadata-only'));

            /** @var array<array-key,Request> $queue */
            $queue = [];

            $this->logger->notice(
                message: "Preloading local state database for '{user}'.",
                context: [
                    'event_name' => 'state.import.preload.started',
                    'subsystem' => 'state.import',
                    'operation' => 'preload',
                    'outcome' => 'started',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
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
                message: "Preloaded local state database for '{user}' in {duration_seconds}s.",
                context: [
                    'event_name' => 'state.import.preload.completed',
                    'subsystem' => 'state.import',
                    'operation' => 'preload',
                    'outcome' => 'completed',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'mapper' => after_last($userContext->mapper::class, '\\'),
                    'duration_seconds' => round(microtime(true) - $time, 4),
                    'stats' => [
                        'pointers' => count($userContext->mapper->getPointersList()),
                        'objects' => $userContext->mapper->getObjectsCount(),
                    ],
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

                if (true === $input->getOption('force-replace-metadata')) {
                    $opts[Options::FORCE_REPLACE_METADATA] = true;
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

                $this->logger->notice("Importing {import_type} changes from '{user}@{backend}'.", [
                    'event_name' => 'state.import.backend.started',
                    'subsystem' => 'state.import',
                    'operation' => 'import_backend',
                    'outcome' => 'started',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'backend' => $name,
                    'import_type' => true === $metadata ? 'metadata' : 'metadata & play state',
                    'since' => null === $after ? 'Beginning' : (string) $after,
                    'dry_run' => $dryRun,
                ]);

                array_push($queue, ...$backend['class']->pull($userContext->mapper, $after));

                $inDryMode = $userContext->mapper->inDryRunMode() || ag($backend, 'options.' . Options::DRY_RUN);

                if (false === $inDryMode) {
                    if (true === (bool) Message::get("{$name}.has_errors")) {
                        $this->logger->warning(
                            message: "Skipping import cursor update for '{user}@{backend}': errors were recorded during import.",
                            context: [
                                'event_name' => 'state.import.cursor.skipped',
                                'subsystem' => 'state.import',
                                'operation' => 'update_cursor',
                                'outcome' => 'skipped',
                                'command' => self::ROUTE,
                                'user' => $userContext->name,
                                'backend' => $name,
                                'reason' => 'backend_errors_recorded',
                            ],
                        );
                    } else {
                        $userContext->config->set("{$name}.import.lastSync", time());
                    }
                }
            }

            unset($backend);

            $start = microtime(true);
            $this->logger->notice("Waiting for {request_count} import requests for '{user}'.", [
                'event_name' => 'state.import.requests.started',
                'subsystem' => 'state.import',
                'operation' => 'send_requests',
                'outcome' => 'started',
                'command' => self::ROUTE,
                'user' => $userContext->name,
                'request_count' => count($queue),
                'sync_requests' => $syncRequests,
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
                        message: "Import requests failed for '{user}'.",
                        context: [
                            'event_name' => 'state.import.requests.failed',
                            'subsystem' => 'state.import',
                            'operation' => 'send_requests',
                            'outcome' => 'failed',
                            'command' => self::ROUTE,
                            'user' => $userContext->name,
                            'request_count' => count($queue),
                            'sync_requests' => $syncRequests,
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );

                throw $e;
            }

            $this->logger->notice(
                "Completed {request_count} import requests for '{user}' in {duration_seconds}s.",
                [
                    'event_name' => 'state.import.requests.completed',
                    'subsystem' => 'state.import',
                    'operation' => 'send_requests',
                    'outcome' => 'completed',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'request_count' => count($queue),
                    'duration_seconds' => round(microtime(true) - $start, 4),
                    'failed_count' => 0,
                    'sync_requests' => $syncRequests,
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
                $this->logger->notice("Found {updated_count} updated items from '{user}' backends.", [
                    'event_name' => 'state.import.changes.collected',
                    'subsystem' => 'state.import',
                    'operation' => 'collect_changes',
                    'outcome' => 'completed',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'updated_count' => $total,
                    'backend_count' => count($list),
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
                "Importing play states for '{user}' completed in {duration_seconds}s.",
                [
                    'event_name' => 'state.import.user.completed',
                    'subsystem' => 'state.import',
                    'operation' => 'import',
                    'outcome' => 'completed',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'backends' => array_keys($list),
                    'duration_seconds' => round(microtime(true) - $userStart, 4),
                    'memory' => [
                        'now' => get_memory_usage(),
                        'peak' => get_peak_memory_usage(),
                    ],
                ],
            );

            foreach ($operations as $type => $ops) {
                $added = $ops['added'] ?? 0;
                $updated = $ops['updated'] ?? 0;
                $failed = $ops['failed'] ?? 0;

                if (($added + $updated + $failed) > 0) {
                    $this->logger->notice(
                        "Import status for '{user}' {item_type}: added {added_count}, updated {updated_count}, failed {failed_count}.",
                        [
                            'event_name' => 'state.import.status',
                            'subsystem' => 'state.import',
                            'operation' => 'commit',
                            'outcome' => 'completed',
                            'command' => self::ROUTE,
                            'user' => $userContext->name,
                            'item_type' => $type,
                            'added_count' => $added,
                            'updated_count' => $updated,
                            'failed_count' => $failed,
                        ],
                    );
                }
            }

            if (false === $dryRun) {
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

        $this->logger->notice('Import completed for {user_count} users in {duration_seconds}s.', [
            'event_name' => 'state.import.completed',
            'subsystem' => 'state.import',
            'operation' => 'import',
            'outcome' => 'completed',
            'command' => self::ROUTE,
            'user_count' => count($users),
            'duration_seconds' => round(microtime(true) - $totalStartTime, 4),
            'dry_run' => $dryRun,
            'force_full' => $forceFullRequested,
        ]);

        $this->logger->notice('Using WatchState {version.full}.', [
            'event_name' => 'app.version',
            'subsystem' => 'app',
            'operation' => 'version',
            'outcome' => 'resolved',
            'command' => self::ROUTE,
            'version' => [
                'full' => get_full_version(),
            ],
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
     * @param array<int|string,mixed> $selected
     */
    private function resolveSelectionMode(array $selected, bool $exclude): string
    {
        $selected = array_values(array_filter(
            array_map(trim(...), array_map('strval', $selected)),
            static fn(string $item): bool => '' !== $item,
        ));

        if ([] === $selected) {
            return 'all';
        }

        return true === $exclude ? 'exclude' : 'include';
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
