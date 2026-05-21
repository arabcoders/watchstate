<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Backends\Common\ClientInterface as iClient;
use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\StreamLogHandler;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\Playlists\PlaylistSyncService;
use App\Libs\Stream;
use App\Libs\UserContext;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[Cli(command: self::ROUTE)]
class PlaylistCommand extends Command
{
    public const string ROUTE = 'state:playlist';

    public const string TASK_NAME = 'playlist';

    public function __construct(
        protected PlaylistSyncService $service,
        #[Inject(DirectMapper::class)]
        protected iImport $mapper,
        protected iLogger $logger,
        protected LogSuppressor $suppressor,
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Sync playlists cross backends.')
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit any playlist changes.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Ignore playlist last sync dates and fetch all selected backends.')
            ->addOption('logfile', null, InputOption::VALUE_REQUIRED, 'Save console output to file.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output, [
            iLogger::class => $this->logger,
            Level::class => Level::Error,
        ]);
    }

    protected function process(InputInterface $input, OutputInterface $output): int
    {
        if (null !== ($logfile = $input->getOption('logfile')) && true === $this->logger instanceof Logger) {
            $this->logger->setHandlers([
                $this->suppressor->withHandler(new StreamLogHandler(new Stream($logfile, 'w'), $output)),
            ]);
        }

        $dbOpts = [];
        if (true === (bool) $input->getOption('trace')) {
            $dbOpts[Options::DEBUG_TRACE] = true;
        }

        $dryRun = true === (bool) $input->getOption('dry-run');
        $forceFull = true === (bool) $input->getOption('force-full');
        $selectedBackends = array_values(array_filter(
            array_map(trim(...), array_values((array) $input->getOption('select-backend'))),
            static fn(string $item): bool => '' !== $item,
        ));
        $exclude = true === (bool) $input->getOption('exclude');

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

        if (true === $dryRun) {
            $this->logger->notice('Dry run enabled; no playlist changes will be committed.', [
                'event_name' => 'playlist.dry_run.enabled',
                'subsystem' => 'playlist',
                'operation' => 'sync',
                'outcome' => 'started',
                'command' => self::ROUTE,
                'dry_run' => true,
            ]);
        }

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

        $this->logger->notice('Playlist sync started for {user_count} users.', [
            'event_name' => 'playlist.sync.started',
            'subsystem' => 'playlist',
            'operation' => 'sync',
            'outcome' => 'started',
            'command' => self::ROUTE,
            'user_count' => count($users),
            'dry_run' => $dryRun,
            'force_full' => $forceFull,
            'selection' => [
                'mode' => $this->resolveSelectionMode($selectedBackends, $exclude),
                'backends' => $selectedBackends,
            ],
        ]);

        $rows = [];

        foreach ($users as $userContext) {
            $userStart = microtime(true);

            $this->logger->notice("Syncing playlists for '{user}'.", [
                'event_name' => 'playlist.sync.user.started',
                'subsystem' => 'playlist',
                'operation' => 'sync',
                'outcome' => 'started',
                'command' => self::ROUTE,
                'user' => $userContext->name,
                'memory' => [
                    'now' => get_memory_usage(),
                    'peak' => get_peak_memory_usage(),
                ],
            ]);

            $clients = $this->getClients(
                userContext: $userContext,
                selected: $selectedBackends,
                exclude: $exclude,
                trace: true === (bool) $input->getOption('trace'),
            );

            if ([] === $clients) {
                $reason = [] === $selectedBackends ? 'no_backends' : 'selection_no_match';
                $message = [] === $selectedBackends
                    ? "No playlist backends were prepared for '{user}'."
                    : "No playlist backends matched selection for '{user}'.";

                $this->logger->warning($message, [
                    'event_name' => 'playlist.backend.none_prepared',
                    'subsystem' => 'playlist',
                    'operation' => 'prepare_backend',
                    'outcome' => 'skipped',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'reason' => $reason,
                    'selection' => [
                        'mode' => $this->resolveSelectionMode($selectedBackends, $exclude),
                        'backends' => $selectedBackends,
                    ],
                ]);

                continue;
            }

            $sourceBackends = $this->getSourceBackends($userContext, array_keys($clients));
            $targetBackends = $this->getTargetBackends($userContext, array_keys($clients));

            $this->logger->notice("Prepared {backend_count} playlist backends for '{user}'.", [
                'event_name' => 'playlist.clients.prepared',
                'subsystem' => 'playlist',
                'operation' => 'prepare_backend',
                'outcome' => 'completed',
                'command' => self::ROUTE,
                'user' => $userContext->name,
                'backend_count' => count($clients),
                'backends' => array_keys($clients),
                'source_backends' => $sourceBackends,
                'target_backends' => $targetBackends,
                'memory' => [
                    'now' => get_memory_usage(),
                    'peak' => get_peak_memory_usage(),
                ],
            ]);

            try {
                $results = $this->service->sync($userContext, $clients, [
                    Options::DRY_RUN => $dryRun,
                    Options::FORCE_FULL => $forceFull,
                    'source_backends' => $sourceBackends,
                    'target_backends' => $targetBackends,
                ]);
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "Playlist sync failed for '{user}'.",
                        context: [
                            'event_name' => 'playlist.sync.failed',
                            'subsystem' => 'playlist',
                            'operation' => 'sync',
                            'outcome' => 'failed',
                            'command' => self::ROUTE,
                            'user' => $userContext->name,
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );

                throw $e;
            }

            foreach ($results as $backend => $stats) {
                $rows[] = [
                    'user' => $userContext->name,
                    'backend' => $backend,
                    'playlists' => $stats['playlists'],
                    'items' => $stats['items'],
                    'added' => true === $dryRun ? '-' : $stats['added'],
                    'updated' => true === $dryRun ? '-' : $stats['updated'],
                    'removed' => true === $dryRun ? '-' : $stats['removed'],
                ];
            }

            if (false === $dryRun) {
                $persistStart = microtime(true);
                $userContext->config->persist();

                $this->logger->notice("Persisted playlist sync state for '{user}' in {duration_seconds}s.", [
                    'event_name' => 'playlist.sync.persisted',
                    'subsystem' => 'playlist',
                    'operation' => 'persist',
                    'outcome' => 'completed',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'duration_seconds' => round(microtime(true) - $persistStart, 4),
                ]);
            }

            $this->logger->info("Playlist sync for '{user}' completed in {duration_seconds}s.", [
                'event_name' => 'playlist.sync.user.completed',
                'subsystem' => 'playlist',
                'operation' => 'sync',
                'outcome' => 'completed',
                'command' => self::ROUTE,
                'user' => $userContext->name,
                'backends' => array_keys($clients),
                'duration_seconds' => round(microtime(true) - $userStart, 4),
                'memory' => [
                    'now' => get_memory_usage(),
                    'peak' => get_peak_memory_usage(),
                ],
            ]);
        }

        if ([] === $rows) {
            $this->logger->warning('Playlist sync completed without syncable results across {user_count} users.', [
                'event_name' => 'playlist.sync.no_results',
                'subsystem' => 'playlist',
                'operation' => 'sync',
                'outcome' => 'completed',
                'command' => self::ROUTE,
                'user_count' => count($users),
                'reason' => 'no_syncable_results',
            ]);
        } else {
            foreach ($rows as $row) {
                $this->logger->notice(
                    message: "Playlist summary for '{user}@{backend}': {playlist_count} playlists, {item_count} items, added {added_count}, updated {updated_count}, removed {removed_count}.",
                    context: [
                        'event_name' => 'playlist.sync.summary',
                        'subsystem' => 'playlist',
                        'operation' => 'sync',
                        'outcome' => 'completed',
                        'command' => self::ROUTE,
                        'user' => $row['user'],
                        'backend' => $row['backend'],
                        'playlist_count' => $row['playlists'],
                        'item_count' => $row['items'],
                        'added_count' => $row['added'],
                        'updated_count' => $row['updated'],
                        'removed_count' => $row['removed'],
                        'dry_run' => $dryRun,
                    ],
                );
            }
        }

        $this->logger->notice('Playlist sync completed for {user_count} users in {duration_seconds}s.', [
            'event_name' => 'playlist.sync.completed',
            'subsystem' => 'playlist',
            'operation' => 'sync',
            'outcome' => 'completed',
            'command' => self::ROUTE,
            'user_count' => count($users),
            'duration_seconds' => round(microtime(true) - $totalStart, 4),
        ]);

        return self::SUCCESS;
    }

    /**
     * @param UserContext $userContext
     * @param array<int,string> $selected
     *
     * @return array<string,iClient>
     */
    protected function getClients(UserContext $userContext, array $selected = [], bool $exclude = false, bool $trace = false): array
    {
        $clients = [];
        $stats = [
            'configured' => 0,
            'filtered' => 0,
            'disabled' => 0,
            'unsupported' => 0,
            'invalid_url' => 0,
            'failed' => 0,
        ];
        $selected = array_values(array_filter(array_map(trim(...), $selected), static fn($item) => '' !== $item));

        foreach ($userContext->config->getAll() as $backendName => $backend) {
            $stats['configured']++;

            if ($selected !== [] && $exclude === $this->matchesSelection($selected, $backendName)) {
                $stats['filtered']++;
                $this->logger->info("Skipping '{user}@{backend}': excluded by selection.", [
                    'event_name' => 'playlist.backend.skipped',
                    'subsystem' => 'playlist',
                    'operation' => 'prepare_backend',
                    'outcome' => 'skipped',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'backend' => $backendName,
                    'reason' => 'selected_excluded',
                    'selection' => [
                        'mode' => $this->resolveSelectionMode($selected, $exclude),
                        'backends' => $selected,
                    ],
                ]);
                continue;
            }

            $importEnabled = true === (bool) ag($backend, 'import.enabled', false);
            $exportEnabled = true === (bool) ag($backend, 'export.enabled', false);

            if (false === $importEnabled && false === $exportEnabled) {
                $stats['disabled']++;

                if ($selected !== []) {
                    $this->logger->warning(
                        "Including disabled playlist backend '{user}@{backend}' because it was explicitly selected.",
                        [
                            'event_name' => 'playlist.backend.forced',
                            'subsystem' => 'playlist',
                            'operation' => 'prepare_backend',
                            'outcome' => 'forced',
                            'command' => self::ROUTE,
                            'user' => $userContext->name,
                            'backend' => $backendName,
                            'reason' => 'explicitly_selected',
                            'import_enabled' => $importEnabled,
                            'export_enabled' => $exportEnabled,
                        ],
                    );
                } else {
                    $this->logger->info(
                        "Skipping '{user}@{backend}': playlist sync is disabled.",
                        [
                            'event_name' => 'playlist.backend.skipped',
                            'subsystem' => 'playlist',
                            'operation' => 'prepare_backend',
                            'outcome' => 'skipped',
                            'command' => self::ROUTE,
                            'user' => $userContext->name,
                            'backend' => $backendName,
                            'reason' => 'sync_disabled',
                            'import_enabled' => $importEnabled,
                            'export_enabled' => $exportEnabled,
                        ],
                    );
                    continue;
                }
            }

            $backendType = strtolower((string) ag($backend, 'type', ''));
            if (null === Config::get("supported.{$backendType}")) {
                $stats['unsupported']++;
                $this->logger->warning(
                    "Skipping '{user}@{backend}': backend type '{backend_type}' is unsupported.",
                    [
                        'event_name' => 'playlist.backend.skipped',
                        'subsystem' => 'playlist',
                        'operation' => 'prepare_backend',
                        'outcome' => 'skipped',
                        'command' => self::ROUTE,
                        'user' => $userContext->name,
                        'backend' => $backendName,
                        'backend_type' => $backendType,
                        'reason' => 'unsupported_type',
                    ],
                );
                continue;
            }

            $url = (string) ag($backend, 'url', '');
            if (false === filter_var($url, FILTER_VALIDATE_URL)) {
                $stats['invalid_url']++;
                $this->logger->warning(
                    "Skipping '{user}@{backend}': URL '{url}' is invalid.",
                    [
                        'event_name' => 'playlist.backend.skipped',
                        'subsystem' => 'playlist',
                        'operation' => 'prepare_backend',
                        'outcome' => 'skipped',
                        'command' => self::ROUTE,
                        'user' => $userContext->name,
                        'backend' => $backendName,
                        'url' => $url,
                        'reason' => 'invalid_url',
                    ],
                );
                continue;
            }

            $opts = ag($backend, 'options', []);
            if (true === $trace) {
                $opts[Options::DEBUG_TRACE] = true;
            }

            $backend['options'] = $opts;
            $backend['name'] = $backendName;

            try {
                $clients[$backendName] = make_backend($backend, $backendName, [
                    UserContext::class => $userContext,
                    iLogger::class => $this->logger,
                ]);
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->logger->error(
                    ...lw(
                        message: "Failed to initialize playlist client for '{user}@{backend}'.",
                        context: [
                            'event_name' => 'playlist.client.initialize.failed',
                            'subsystem' => 'playlist',
                            'operation' => 'prepare_backend',
                            'outcome' => 'failed',
                            'command' => self::ROUTE,
                            'user' => $userContext->name,
                            'backend' => $backendName,
                            'backend_type' => $backendType,
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );
            }
        }

        $this->logger->info("Prepared {accepted_count} playlist clients for '{user}'.", [
            'event_name' => 'playlist.clients.selection.completed',
            'subsystem' => 'playlist',
            'operation' => 'prepare_backend',
            'outcome' => 'completed',
            'command' => self::ROUTE,
            'user' => $userContext->name,
            'accepted_count' => count($clients),
            'selection' => [
                'mode' => $this->resolveSelectionMode($selected, $exclude),
                'backends' => $selected,
            ],
            'stats' => array_replace($stats, ['accepted' => count($clients)]),
            'backends' => array_keys($clients),
        ]);

        return $clients;
    }

    /**
     * @param UserContext $userContext
     * @param array<int,string> $backends
     *
     * @return array<int,string>
     */
    protected function getSourceBackends(UserContext $userContext, array $backends): array
    {
        return array_values(array_filter(
            $backends,
            static fn(string $backendName): bool => true === (bool) $userContext->config->get("{$backendName}.import.enabled", false),
        ));
    }

    /**
     * @param UserContext $userContext
     * @param array<int,string> $backends
     *
     * @return array<int,string>
     */
    protected function getTargetBackends(UserContext $userContext, array $backends): array
    {
        return array_values(array_filter(
            $backends,
            static fn(string $backendName): bool => true === (bool) $userContext->config->get("{$backendName}.export.enabled", false),
        ));
    }

    /**
     * @param array<int,string> $selected
     */
    private function matchesSelection(array $selected, string $backendName): bool
    {
        foreach ($selected as $value) {
            if (true === str_starts_with($backendName, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,string> $selected
     */
    private function resolveSelectionMode(array $selected, bool $exclude): string
    {
        if ([] === $selected) {
            return 'all';
        }

        return true === $exclude ? 'exclude' : 'include';
    }
}
