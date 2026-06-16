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
            $this->logger->error($e->getMessage(), ['exception' => $e]);

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
            $this->logger->notice('Dry run mode. No playlist changes will be committed.');
        }

        $totalStart = microtime(true);

        $this->logger->notice('SYSTEM: Using WatchState {full_version}', [
            'full_version' => get_full_version(),
        ]);

        $this->logger->notice("SYSTEM: Starting playlist sync process for '{total}' users.", [
            'total' => count($users),
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

            $this->logger->notice("SYSTEM: Syncing '{user}' playlists.", [
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
                $this->logger->warning(
                    $selectedBackends === []
                        ? r("SYSTEM: No playlist backends were prepared for '{user}'.", [
                            'user' => $userContext->name,
                        ])
                        : r("SYSTEM: [-s, --select-backend] flag did not match any playlist backend for '{user}'.", [
                            'user' => $userContext->name,
                        ]),
                );

                continue;
            }

            $sourceBackends = $this->getSourceBackends($userContext, array_keys($clients));
            $targetBackends = $this->getTargetBackends($userContext, array_keys($clients));

            $this->logger->notice("SYSTEM: Prepared '{total}' playlist backends for '{user}'.", [
                'user' => $userContext->name,
                'total' => count($clients),
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
                        message: "SYSTEM: Playlist sync for '{user}' failed. '{error.kind}' with message '{error.message}' at '{error.file}:{error.line}'.",
                        context: [
                            'user' => $userContext->name,
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );

                throw $e;
            }

            foreach ($results as $backend => $stats) {
                $rows[] = $backend;
                $this->logger->notice(
                    "SYSTEM: Synced '{user}@{backend}' playlists: {playlists} playlists, {items} items, {added} added, {updated} updated, {removed} removed.",
                    [
                        'user' => $userContext->name,
                        'backend' => $backend,
                        'playlists' => $stats['playlists'],
                        'items' => $stats['items'],
                        'added' => true === $dryRun ? 0 : $stats['added'],
                        'updated' => true === $dryRun ? 0 : $stats['updated'],
                        'removed' => true === $dryRun ? 0 : $stats['removed'],
                    ],
                );
            }

            if (false === $dryRun) {
                $persistStart = microtime(true);
                $userContext->config->persist();

                $this->logger->notice("SYSTEM: Persisted playlist sync state for '{user}' in '{duration}'s.", [
                    'user' => $userContext->name,
                    'duration' => round(microtime(true) - $persistStart, 4),
                ]);
            }

            $this->logger->info("SYSTEM: Syncing '{user}' playlists completed in '{duration}'s. Memory usage '{memory.now}'.", [
                'user' => $userContext->name,
                'backends' => implode(', ', array_keys($clients)),
                'duration' => round(microtime(true) - $userStart, 4),
                'memory' => [
                    'now' => get_memory_usage(),
                    'peak' => get_peak_memory_usage(),
                ],
            ]);
        }

        if ([] === $rows) {
            $this->logger->warning('SYSTEM: Playlist sync completed without any syncable playlist results.');
            $this->logger->notice("SYSTEM: Playlist sync process completed in '{duration}'s for all users.", [
                'duration' => round(microtime(true) - $totalStart, 4),
            ]);
            $this->logger->notice('SYSTEM: Using WatchState {full_version}', [
                'full_version' => get_full_version(),
            ]);
            $this->logger->notice('SYSTEM: No matching backends produced syncable playlists.');
            return self::SUCCESS;
        }

        $this->logger->notice("SYSTEM: Playlist sync process completed in '{duration}'s for all users.", [
            'duration' => round(microtime(true) - $totalStart, 4),
        ]);

        $this->logger->notice('SYSTEM: Using WatchState {full_version}', [
            'full_version' => get_full_version(),
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
                $this->logger->info("PLAYLIST: Ignoring '{user}@{backend}'. As requested.", [
                    'user' => $userContext->name,
                    'backend' => $backendName,
                ]);
                continue;
            }

            $importEnabled = true === (bool) ag($backend, 'import.enabled', false);
            $exportEnabled = true === (bool) ag($backend, 'export.enabled', false);

            if (false === $importEnabled && false === $exportEnabled) {
                $stats['disabled']++;

                if ($selected !== []) {
                    $this->logger->warning(
                        "PLAYLIST: Syncing disabled '{user}@{backend}' as requested.",
                        [
                            'user' => $userContext->name,
                            'backend' => $backendName,
                        ],
                    );
                } else {
                    $this->logger->info(
                        "PLAYLIST: Ignoring '{user}@{backend}'. Playlist sync disabled.",
                        [
                            'user' => $userContext->name,
                            'backend' => $backendName,
                        ],
                    );
                    continue;
                }
            }

            $backendType = strtolower((string) ag($backend, 'type', ''));
            if (null === Config::get("supported.{$backendType}")) {
                $stats['unsupported']++;
                $this->logger->warning(
                    "PLAYLIST: Ignoring '{user}@{backend}'. Unsupported backend type '{type}'.",
                    [
                        'user' => $userContext->name,
                        'backend' => $backendName,
                        'type' => $backendType,
                    ],
                );
                continue;
            }

            $url = (string) ag($backend, 'url', '');
            if (false === filter_var($url, FILTER_VALIDATE_URL)) {
                $stats['invalid_url']++;
                $this->logger->warning(
                    "PLAYLIST: Ignoring '{user}@{backend}'. Invalid URL '{url}'.",
                    [
                        'user' => $userContext->name,
                        'backend' => $backendName,
                        'url' => $url,
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
                    "PLAYLIST: Failed to initialize '{user}@{backend}' client. '{error.message}' at '{error.file}:{error.line}'.",
                    [
                        'user' => $userContext->name,
                        'backend' => $backendName,
                        'error' => [
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                            'line' => $e->getLine(),
                            'kind' => $e::class,
                        ],
                    ],
                );
            }
        }

        $this->logger->info("SYSTEM: Prepared playlist clients for '{user}'.", [
            'user' => $userContext->name,
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
