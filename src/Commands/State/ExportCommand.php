<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Backends\Common\ClientInterface;
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
use App\Libs\QueueRequests;
use App\Libs\Stream;
use App\Libs\UserContext;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

/**
 * Class ExportCommand
 *
 * Command for exporting play state to backends.
 *
 * @package App\Console\Commands\State
 */
#[Cli(command: self::ROUTE)]
class ExportCommand extends Command
{
    public const string ROUTE = 'state:export';

    public const string TASK_NAME = 'export';

    /**
     * Class Constructor.
     *
     * @param DirectMapper $mapper The instance of the DirectMapper class.
     * @param QueueRequests $queue The instance of the QueueRequests class.
     * @param iLogger $logger The instance of the iLogger class.
     */
    public function __construct(
        #[Inject(DirectMapper::class)]
        private readonly iImport $mapper,
        private readonly QueueRequests $queue,
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
     * Configure the command.
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Export play state to backends.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full export. Ignore last export date.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit changes to backends.')
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
            ->addOption('ignore-date', 'i', InputOption::VALUE_NONE, 'Ignore date comparison.')
            ->addOption('logfile', null, InputOption::VALUE_REQUIRED, 'Save console output to file.');
    }

    /**
     * Make sure the command is not running in parallel.
     *
     * @param iInput $input The input object containing the command data.
     * @param iOutput $output The output object for displaying command output.
     *
     * @return int The exit code of the command execution.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output, [
            iLogger::class => $this->logger,
            Level::class => Level::Error,
        ]);
    }

    /**
     * Process the command by pulling and comparing status and then pushing.
     *
     * @param iInput $input
     * @param iOutput $output
     * @return int
     */
    protected function process(iInput $input, iOutput $output): int
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
            $mapperOpts[Options::DRY_RUN] = true;

            $this->logger->notice('Dry run enabled; no changes will be committed to backends.', [
                'event_name' => 'state.export.dry_run.enabled',
                'subsystem' => 'state.export',
                'operation' => 'export',
                'outcome' => 'started',
                'command' => self::ROUTE,
                'dry_run' => true,
            ]);
        }

        if ($input->getOption('trace')) {
            $mapperOpts[Options::DEBUG_TRACE] = true;
            $dbOpts[Options::DEBUG_TRACE] = true;
        }

        if (!empty($mapperOpts)) {
            $this->mapper->setOptions(options: $mapperOpts);
        }

        try {
            $users = array_map(
                fn(string $user): UserContext => get_user_context($user, $this->mapper, $this->logger),
                select_users($input->getOption('user')),
            );
        } catch (RuntimeException $e) {
            $output->writeln(r('<error>{message}</error>', [
                'message' => $e->getMessage(),
            ]));

            return self::FAILURE;
        }

        foreach ($users as $userContext) {
            if ([] === $dbOpts) {
                continue;
            }

            $userContext->db->setOptions($dbOpts);
        }

        if (false === ($syncRequests = $input->getOption('sync-requests'))) {
            $syncRequests = (bool) Config::get('http.default.sync_requests', false);
        }

        if (true === $input->getOption('async-requests')) {
            $syncRequests = false;
        }

        $selected = $input->getOption('select-backend');
        $excludeSelected = true === (bool) $input->getOption('exclude');
        $isCustom = !empty($selected) && count($selected) > 0;
        $supported = Config::get('supported', []);
        $selection = [
            'mode' => $this->resolveSelectionMode((array) $selected, $excludeSelected),
            'backends' => array_values(array_filter(
                array_map(trim(...), array_map('strval', (array) $selected)),
                static fn(string $item): bool => '' !== $item,
            )),
        ];
        $totalStart = microtime(true);

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

        $this->logger->notice('Export started for {user_count} users.', [
            'event_name' => 'state.export.started',
            'subsystem' => 'state.export',
            'operation' => 'export',
            'outcome' => 'started',
            'command' => self::ROUTE,
            'user_count' => count($users),
            'dry_run' => $dryRun,
            'force_full' => $forceFullRequested,
            'selection' => $selection,
        ]);

        foreach ($users as $userContext) {
            $userStart = microtime(true);

            $this->logger->notice("Exporting play states for '{user}'.", [
                'event_name' => 'state.export.user.started',
                'subsystem' => 'state.export',
                'operation' => 'export',
                'outcome' => 'started',
                'command' => self::ROUTE,
                'user' => $userContext->name,
                'memory' => [
                    'now' => get_memory_usage(),
                    'peak' => get_peak_memory_usage(),
                ],
            ]);

            try {
                $backends = $export = $push = $entities = [];

                foreach ($userContext->config->getAll() as $backendName => $backend) {
                    $type = strtolower(ag($backend, 'type', 'unknown'));

                    if ($isCustom && $excludeSelected === $this->in_array($selected, $backendName)) {
                        $this->logger->info("Skipping '{user}@{backend}': excluded by selection.", [
                            'event_name' => 'state.export.backend.skipped',
                            'subsystem' => 'state.export',
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

                    if (true !== (bool) ag($backend, 'export.enabled')) {
                        if ($isCustom) {
                            $this->logger->warning(
                                "Exporting to disabled backend '{user}@{backend}' because it was explicitly selected.",
                                [
                                    'event_name' => 'state.export.backend.forced',
                                    'subsystem' => 'state.export',
                                    'operation' => 'select_backend',
                                    'outcome' => 'forced',
                                    'command' => self::ROUTE,
                                    'user' => $userContext->name,
                                    'backend' => $backendName,
                                    'reason' => 'explicitly_selected',
                                    'export_enabled' => false,
                                ],
                            );
                        } else {
                            $this->logger->info("Skipping '{user}@{backend}': export is disabled.", [
                                'event_name' => 'state.export.backend.skipped',
                                'subsystem' => 'state.export',
                                'operation' => 'select_backend',
                                'outcome' => 'skipped',
                                'command' => self::ROUTE,
                                'user' => $userContext->name,
                                'backend' => $backendName,
                                'reason' => 'export_disabled',
                            ]);
                            continue;
                        }
                    }

                    if (!isset($supported[$type])) {
                        $this->logger->warning(
                            "Skipping '{user}@{backend}': backend type '{backend_type}' is unsupported.",
                            [
                                'event_name' => 'state.export.backend.skipped',
                                'subsystem' => 'state.export',
                                'operation' => 'select_backend',
                                'outcome' => 'skipped',
                                'command' => self::ROUTE,
                                'backend_type' => $type,
                                'backend' => $backendName,
                                'user' => $userContext->name,
                                'types' => implode(', ', array_keys($supported)),
                                'reason' => 'unsupported_type',
                            ],
                        );
                        continue;
                    }

                    if (null === ($url = ag($backend, 'url')) || false === is_valid_url($url)) {
                        $this->logger->warning("Skipping '{user}@{backend}': URL '{url}' is invalid.", [
                            'event_name' => 'state.export.backend.skipped',
                            'subsystem' => 'state.export',
                            'operation' => 'select_backend',
                            'outcome' => 'skipped',
                            'command' => self::ROUTE,
                            'url' => $url ?? 'None',
                            'backend' => $backendName,
                            'user' => $userContext->name,
                            'reason' => 'invalid_url',
                        ]);
                        continue;
                    }

                    $backend['name'] = $backendName;
                    $backends[$backendName] = $backend;
                }

                if (empty($backends)) {
                    $this->logger->warning(
                        true === $isCustom
                            ? "No selected backends matched for '{user}'."
                            : "No export backends were available for '{user}'.",
                        [
                            'event_name' => 'state.export.backend.none_selected',
                            'subsystem' => 'state.export',
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

                foreach ($backends as &$backend) {
                    if (null === ($name = ag($backend, 'name'))) {
                        continue;
                    }

                    $opts = ag($backend, 'options', []);

                    if ($input->getOption('ignore-date')) {
                        $opts[Options::IGNORE_DATE] = true;
                    }

                    if ($input->getOption('trace')) {
                        $opts[Options::DEBUG_TRACE] = true;
                    }

                    if ($input->getOption('dry-run')) {
                        $opts[Options::DRY_RUN] = true;
                    }

                    if ($input->getOption('timeout')) {
                        $opts['client']['timeout'] = $input->getOption('timeout');
                    }

                    $backend['options'] = $opts;
                    $backend['class'] = make_backend(backend: $backend, name: $name, options: [
                        UserContext::class => $userContext,
                    ])->setLogger($this->logger);
                }

                unset($backend);

                if (false === $input->getOption('force-full')) {
                    $minDate = time();

                    foreach ($backends as $backend) {
                        if (null === ($lastSync = ag($backend, 'export.lastSync', null))) {
                            $this->logger->info(
                                "Using export mode for '{user}@{backend}': no export cursor was found.",
                                [
                                    'event_name' => 'state.export.backend.mode_selected',
                                    'subsystem' => 'state.export',
                                    'operation' => 'select_mode',
                                    'outcome' => 'completed',
                                    'command' => self::ROUTE,
                                    'user' => $userContext->name,
                                    'backend' => ag($backend, 'name'),
                                    'mode' => 'export',
                                    'reason' => 'missing_export_cursor',
                                ],
                            );

                            $export[ag($backend, 'name')] = $backend;
                            continue;
                        }

                        if (null === ag($backend, 'import.lastSync', null)) {
                            $this->logger->warning(
                                "Using export mode for '{user}@{backend}': import metadata has not been populated yet.",
                                [
                                    'event_name' => 'state.export.backend.mode_selected',
                                    'subsystem' => 'state.export',
                                    'operation' => 'select_mode',
                                    'outcome' => 'completed',
                                    'command' => self::ROUTE,
                                    'user' => $userContext->name,
                                    'backend' => ag($backend, 'name'),
                                    'mode' => 'export',
                                    'reason' => 'missing_import_cursor',
                                ],
                            );

                            $export[ag($backend, 'name')] = $backend;
                            continue;
                        }

                        if ($minDate > $lastSync) {
                            $minDate = $lastSync;
                        }
                    }

                    $lastSync = make_date($minDate);

                    $this->logger->notice("Loading local changes for '{user}' since {since}.", [
                        'event_name' => 'state.export.changes.loading',
                        'subsystem' => 'state.export',
                        'operation' => 'load_changes',
                        'outcome' => 'started',
                        'command' => self::ROUTE,
                        'since' => (string) $lastSync,
                        'user' => $userContext->name,
                    ]);

                    $entities = $userContext->db->getAll($lastSync);

                    if (count($entities) < 1 && count($export) < 1) {
                        $this->logger->notice("No play-state changes detected for '{user}' since {since}.", [
                            'event_name' => 'state.export.no_changes',
                            'subsystem' => 'state.export',
                            'operation' => 'load_changes',
                            'outcome' => 'completed',
                            'command' => self::ROUTE,
                            'since' => (string) $lastSync,
                            'user' => $userContext->name,
                            'change_count' => 0,
                        ]);
                        continue;
                    }

                    if (count($entities) >= 1) {
                        $this->logger->info(
                            "Checking {item_count} media items for push mode compatibility for '{user}'.",
                            (static function () use ($entities, $input, $userContext): array {
                                $context = [
                                    'event_name' => 'state.export.push.compatibility.started',
                                    'subsystem' => 'state.export',
                                    'operation' => 'check_push_compatibility',
                                    'outcome' => 'started',
                                    'command' => self::ROUTE,
                                    'item_count' => count($entities),
                                    'user' => $userContext->name,
                                ];

                                if ($input->getOption('trace')) {
                                    foreach ($entities as $entity) {
                                        $context['items'][$entity->id] = $entity->getName();
                                    }
                                }

                                return $context;
                            })(),
                        );

                        foreach ($entities as $entity) {
                            foreach ($backends as $backend) {
                                $name = ag($backend, 'name');

                                if (null === ($lastSync = ag($backend, 'export.lastSync', null))) {
                                    continue;
                                }

                                if (false === ag_exists($entity->getMetadata(), $name)) {
                                    $addedDate = ag(
                                        $entity->getMetadata($entity->via),
                                        iState::COLUMN_META_DATA_ADDED_AT,
                                    );
                                    $extraMargin = (int) Config::get('export.not_found');

                                    if (null === $addedDate || false === ctype_digit($addedDate)) {
                                        $this->logger->info(
                                            "Skipping push compatibility for '{user}@{backend}' item '#{item.state_id}: {item.title}': added_at '{added_at}' is invalid.",
                                            [
                                                'event_name' => 'state.export.push.item.skipped',
                                                'subsystem' => 'state.export',
                                                'operation' => 'check_push_compatibility',
                                                'outcome' => 'skipped',
                                                'command' => self::ROUTE,
                                                'user' => $userContext->name,
                                                'backend' => $name,
                                                'item' => [
                                                    'state_id' => null === $entity->id ? null : (string) $entity->id,
                                                    'title' => $entity->getName(),
                                                ],
                                                'added_at' => $addedDate,
                                                'added_at_type' => get_debug_type($addedDate),
                                                'reason' => 'invalid_added_at',
                                                'data' => $input->getOption('trace') ? $entity->getAll() : [],
                                            ],
                                        );
                                        continue;
                                    }

                                    if ($lastSync > ($addedDate + $extraMargin)) {
                                        $this->logger->info(
                                            "Skipping push compatibility for '{user}@{backend}' item '#{item.state_id}: {item.title}': metadata wait period expired.",
                                            [
                                                'event_name' => 'state.export.push.item.skipped',
                                                'subsystem' => 'state.export',
                                                'operation' => 'check_push_compatibility',
                                                'outcome' => 'skipped',
                                                'command' => self::ROUTE,
                                                'user' => $userContext->name,
                                                'backend' => $name,
                                                'item' => [
                                                    'state_id' => null === $entity->id ? null : (string) $entity->id,
                                                    'title' => $entity->getName(),
                                                ],
                                                'wait_period' => [
                                                    'added_at' => make_date($addedDate),
                                                    'extra_margin' => $extraMargin,
                                                    'last_sync_at' => make_date($lastSync),
                                                    'diff' => $lastSync - ($addedDate + $extraMargin),
                                                ],
                                                'reason' => 'metadata_wait_expired',
                                            ],
                                        );

                                        continue;
                                    }

                                    if (true === ag_exists($push, $name)) {
                                        unset($push[$name]);
                                    }

                                    $this->logger->info(
                                        "Using export mode for '{user}@{backend}': local state for '#{item.state_id}: {item.title}' is missing backend metadata.",
                                        [
                                            'event_name' => 'state.export.backend.mode_selected',
                                            'subsystem' => 'state.export',
                                            'operation' => 'select_mode',
                                            'outcome' => 'completed',
                                            'command' => self::ROUTE,
                                            'user' => $userContext->name,
                                            'backend' => $name,
                                            'mode' => 'export',
                                            'item' => [
                                                'state_id' => null === $entity->id ? null : (string) $entity->id,
                                                'title' => $entity->getName(),
                                            ],
                                            'reason' => 'missing_backend_metadata',
                                        ],
                                    );

                                    $export[$name] = $backend;
                                }

                                if (false === ag_exists($export, $name)) {
                                    $push[ag($backend, 'name')] = $backend;
                                }
                            }
                        }
                    }
                } else {
                    $export = $backends;
                    $this->logger->notice(
                        "Push mode is unavailable when '--force-full' is used.",
                        [
                            'event_name' => 'state.export.push.unavailable',
                            'subsystem' => 'state.export',
                            'operation' => 'select_mode',
                            'outcome' => 'skipped',
                            'command' => self::ROUTE,
                            'user' => $userContext->name,
                            'reason' => 'force_full_requested',
                        ],
                    );
                }

                $this->logger->notice(
                    "Using push mode for {push_count} backends and export mode for {export_count} backends for '{user}'.",
                    [
                        'event_name' => 'state.export.mode.selected',
                        'subsystem' => 'state.export',
                        'operation' => 'select_mode',
                        'outcome' => 'completed',
                        'command' => self::ROUTE,
                        'user' => $userContext->name,
                        'push_count' => count($push),
                        'export_count' => count($export),
                        'push' => [
                            'total' => count($push),
                            'list' => array_keys($push),
                        ],
                        'export' => [
                            'total' => count($export),
                            'list' => array_keys($export),
                        ],
                    ],
                );

                if (count($push) >= 1) {
                    $this->push($userContext, $push, $entities);
                }

                if (count($export) >= 1) {
                    $this->export(
                        $userContext,
                        $export,
                        $input->getOption('dry-run'),
                        $input->getOption('force-full'),
                        $syncRequests,
                    );
                }

                $total = count($this->queue->getQueue());

                if ($total >= 1) {
                    $requestStart = microtime(true);

                    $this->logger->notice("Waiting for {request_count} export requests for '{user}'.", [
                        'event_name' => 'state.export.requests.started',
                        'subsystem' => 'state.export',
                        'operation' => 'send_requests',
                        'outcome' => 'started',
                        'command' => self::ROUTE,
                        'phase' => 'write',
                        'request_count' => $total,
                        'user' => $userContext->name,
                        'sync_requests' => $syncRequests,
                    ]);

                    send_requests(
                        requests: $this->queue->getQueue(),
                        client: $this->http,
                        sync: $syncRequests,
                        logger: $this->logger,
                    );

                    $this->logger->notice("Completed {request_count} export requests for '{user}' in {duration_seconds}s.", [
                        'event_name' => 'state.export.requests.completed',
                        'subsystem' => 'state.export',
                        'operation' => 'send_requests',
                        'outcome' => 'completed',
                        'command' => self::ROUTE,
                        'phase' => 'write',
                        'request_count' => $total,
                        'user' => $userContext->name,
                        'sync_requests' => $syncRequests,
                        'duration_seconds' => round(microtime(true) - $requestStart, 4),
                    ]);
                } else {
                    $this->logger->notice("No play-state changes detected for '{user}'.", [
                        'event_name' => 'state.export.no_changes',
                        'subsystem' => 'state.export',
                        'operation' => 'send_requests',
                        'outcome' => 'completed',
                        'command' => self::ROUTE,
                        'user' => $userContext->name,
                        'change_count' => 0,
                    ]);
                }

                if (false === $dryRun) {
                    foreach ($backends as $backend) {
                        if (null === ($name = ag($backend, 'name'))) {
                            continue;
                        }

                        if (false === (bool) Message::get("{$name}.has_errors", false)) {
                            $lastSyncAt = time();
                            $userContext->config->set("{$name}.export.lastSync", $lastSyncAt);
                            $this->logger->notice("Updated export cursor for '{user}@{backend}' to {last_sync_at}.", [
                                'event_name' => 'state.export.cursor.updated',
                                'subsystem' => 'state.export',
                                'operation' => 'update_cursor',
                                'outcome' => 'completed',
                                'command' => self::ROUTE,
                                'backend' => $name,
                                'user' => $userContext->name,
                                'last_sync_at' => (string) make_date($lastSyncAt),
                                'mode' => 'mixed',
                            ]);
                        } else {
                            $this->logger->warning(
                                "Skipping export cursor update for '{user}@{backend}': errors were recorded during export.",
                                [
                                    'event_name' => 'state.export.cursor.skipped',
                                    'subsystem' => 'state.export',
                                    'operation' => 'update_cursor',
                                    'outcome' => 'skipped',
                                    'command' => self::ROUTE,
                                    'backend' => $name,
                                    'user' => $userContext->name,
                                    'mode' => 'mixed',
                                    'reason' => 'backend_errors_recorded',
                                ],
                            );
                        }
                    }

                    $userContext->config->persist();
                }

                $this->logger->info("Exporting play states for '{user}' completed in {duration_seconds}s.", [
                    'event_name' => 'state.export.user.completed',
                    'subsystem' => 'state.export',
                    'operation' => 'export',
                    'outcome' => 'completed',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'duration_seconds' => round(microtime(true) - $userStart, 4),
                    'backends' => array_keys($backends),
                    'memory' => [
                        'now' => get_memory_usage(),
                        'peak' => get_peak_memory_usage(),
                    ],
                ]);
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "Export failed for '{user}'.",
                        context: [
                            'event_name' => 'state.export.failed',
                            'subsystem' => 'state.export',
                            'operation' => 'export',
                            'outcome' => 'failed',
                            'command' => self::ROUTE,
                            'user' => $userContext->name,
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );
            } finally {
                $this->queue->reset();
                $userContext->mapper->reset();
            }
        }

        $this->logger->notice('Export completed for {user_count} users in {duration_seconds}s.', [
            'event_name' => 'state.export.completed',
            'subsystem' => 'state.export',
            'operation' => 'export',
            'outcome' => 'completed',
            'command' => self::ROUTE,
            'user_count' => count($users),
            'duration_seconds' => round(microtime(true) - $totalStart, 4),
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
     * Push entities to backends if applicable.
     *
     * @param UserContext $userContext The user context object.
     * @param array $backends An array of backends.
     * @param array $entities An array of entities to be pushed.
     *
     * @return int The success status code.
     */
    protected function push(UserContext $userContext, array $backends, array $entities): int
    {
        $start = microtime(true);
        $backendNames = array_keys($backends);

        $this->logger->notice('Pushing {item_count} local changes to {backend_count} backends for \'{user}\'.', [
            'event_name' => 'state.export.push.started',
            'subsystem' => 'state.export',
            'operation' => 'push',
            'outcome' => 'started',
            'command' => self::ROUTE,
            'user' => $userContext->name,
            'item_count' => count($entities),
            'backend_count' => count($backendNames),
            'backends' => $backendNames,
        ]);

        foreach ($backends as $backend) {
            assert($backend['class'] instanceof ClientInterface, 'Backend class must implement ClientInterface.');
            $backend['class']->push(
                entities: $entities,
                queue: $this->queue,
                after: make_date(ag($backend, 'export.lastSync')),
            );
        }

        $this->logger->notice(
            'Pushed {item_count} local changes to {backend_count} backends for \'{user}\' in {duration_seconds}s.',
            [
                'event_name' => 'state.export.push.completed',
                'subsystem' => 'state.export',
                'operation' => 'push',
                'outcome' => 'completed',
                'command' => self::ROUTE,
                'user' => $userContext->name,
                'item_count' => count($entities),
                'backend_count' => count($backendNames),
                'backends' => $backendNames,
                'duration_seconds' => round(microtime(true) - $start, 4),
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Fallback to export mode if push mode is not supported for the backend.
     *
     * @param UserContext $userContext The user context object.
     * @param array $backends An array of backends to export data to.
     * @param bool $inDryMode Whether the command is in dry mode.
     * @param bool $isFull Whether the command is in full mode.
     */
    protected function export(
        UserContext $userContext,
        array $backends,
        bool $inDryMode,
        bool $isFull,
        bool $syncRequests = false,
    ): void {
        $start = microtime(true);
        $backendNames = array_keys($backends);

        $this->logger->notice('Running export mode for {backend_count} backends for \'{user}\'.', [
            'event_name' => 'state.export.export_mode.started',
            'subsystem' => 'state.export',
            'operation' => 'export_mode',
            'outcome' => 'started',
            'command' => self::ROUTE,
            'user' => $userContext->name,
            'backend_count' => count($backendNames),
            'backends' => $backendNames,
            'dry_run' => $inDryMode,
            'force_full' => $isFull,
            'memory' => [
                'now' => get_memory_usage(),
                'peak' => get_peak_memory_usage(),
            ],
        ]);

        $this->logger->notice(
            message: "Preloading local state database for '{user}'.",
            context: [
                'event_name' => 'state.export.preload.started',
                'subsystem' => 'state.export',
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
                'event_name' => 'state.export.preload.completed',
                'subsystem' => 'state.export',
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

        $requests = [];

        foreach ($backends as $backend) {
            if (null === ($name = ag($backend, 'name'))) {
                continue;
            }

            $after = true === $isFull ? null : ag($backend, 'export.lastSync', null);

            if (null === $after) {
                $this->logger->notice("Exporting full play-state snapshot to '{user}@{backend}'.", [
                    'event_name' => 'state.export.backend.started',
                    'subsystem' => 'state.export',
                    'operation' => 'export_backend',
                    'outcome' => 'started',
                    'command' => self::ROUTE,
                    'backend' => $name,
                    'user' => $userContext->name,
                    'since' => 'Beginning',
                    'mode' => 'export',
                    'dry_run' => $inDryMode,
                ]);
            } else {
                $after = make_date($after);
                $this->logger->notice("Exporting play-state changes since {since} to '{user}@{backend}'.", [
                    'event_name' => 'state.export.backend.started',
                    'subsystem' => 'state.export',
                    'operation' => 'export_backend',
                    'outcome' => 'started',
                    'command' => self::ROUTE,
                    'backend' => $name,
                    'user' => $userContext->name,
                    'since' => (string) $after,
                    'mode' => 'export',
                    'dry_run' => $inDryMode,
                ]);
            }

            assert($backend['class'] instanceof ClientInterface, 'Backend class must implement ClientInterface.');
            array_push($requests, ...$backend['class']->export($userContext->mapper, $this->queue, $after));

            if (false === $inDryMode) {
                if (false === (bool) Message::get("{$name}.has_errors")) {
                    $userContext->config->set("{$name}.export.lastSync", time());
                }
            }
        }

        if (count($requests) < 1) {
            $this->logger->notice("No export comparison requests were needed for '{user}'.", [
                'event_name' => 'state.export.requests.completed',
                'subsystem' => 'state.export',
                'operation' => 'send_requests',
                'outcome' => 'completed',
                'command' => self::ROUTE,
                'phase' => 'compare',
                'request_count' => 0,
                'user' => $userContext->name,
                'sync_requests' => $syncRequests,
            ]);
        } else {
            $requestStart = microtime(true);

            $this->logger->notice("Waiting for {request_count} export comparison requests for '{user}'.", [
                'event_name' => 'state.export.requests.started',
                'subsystem' => 'state.export',
                'operation' => 'send_requests',
                'outcome' => 'started',
                'command' => self::ROUTE,
                'phase' => 'compare',
                'request_count' => count($requests),
                'user' => $userContext->name,
                'sync_requests' => $syncRequests,
            ]);

            send_requests(requests: $requests, client: $this->http, sync: $syncRequests, logger: $this->logger);

            $this->logger->notice(
                "Completed {request_count} export comparison requests for '{user}' in {duration_seconds}s.",
                [
                    'event_name' => 'state.export.requests.completed',
                    'subsystem' => 'state.export',
                    'operation' => 'send_requests',
                    'outcome' => 'completed',
                    'command' => self::ROUTE,
                    'phase' => 'compare',
                    'request_count' => count($requests),
                    'user' => $userContext->name,
                    'sync_requests' => $syncRequests,
                    'duration_seconds' => round(microtime(true) - $requestStart, 4),
                ],
            );
        }

        $this->logger->notice(
            "Export mode completed for {backend_count} backends for '{user}' in {duration_seconds}s.",
            [
                'event_name' => 'state.export.export_mode.completed',
                'subsystem' => 'state.export',
                'operation' => 'export_mode',
                'outcome' => 'completed',
                'command' => self::ROUTE,
                'user' => $userContext->name,
                'backend_count' => count($backendNames),
                'backends' => $backendNames,
                'duration_seconds' => round(microtime(true) - $start, 4),
                'dry_run' => $inDryMode,
                'force_full' => $isFull,
                'memory' => [
                    'now' => get_memory_usage(),
                    'peak' => get_peak_memory_usage(),
                ],
            ],
        );
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
}
