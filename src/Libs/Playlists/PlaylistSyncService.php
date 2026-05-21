<?php

declare(strict_types=1);

namespace App\Libs\Playlists;

use App\Backends\Common\ClientInterface as iClient;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Options;
use App\Libs\UserContext;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

class PlaylistSyncService
{
    public function __construct(
        protected iLogger $logger,
    ) {}

    /**
     * Sync and reconcile editable video playlists for one user.
     *
     * @param UserContext $userContext
     * @param array<string,iClient> $clients
     * @param array{DRY_RUN?:bool,FORCE_FULL?:bool,source_backends?:array<int,string>,target_backends?:array<int,string>} $opts
     *
     * @return array<string,array{playlists:int,items:int,added:int,updated:int,removed:int}>
     */
    public function sync(UserContext $userContext, array $clients, array $opts = []): array
    {
        $syncStart = microtime(true);
        $store = new PlaylistStore($userContext->db->getDBLayer(), $this->logger);
        $planner = new PlaylistSyncPlanner();
        $dryRun = true === (bool) ($opts[Options::DRY_RUN] ?? false);
        $forceFull = true === (bool) ($opts[Options::FORCE_FULL] ?? false);

        $results = array_fill_keys(array_keys($clients), $this->makeSummary([]));
        $sourceBackends = array_values(array_unique(array_map('strval', $opts['source_backends'] ?? [])));
        $targetBackends = array_values(array_unique(array_map('strval', $opts['target_backends'] ?? [])));
        $syncStats = [];
        $fetchedBackends = [];
        $fetchTotals = $this->makeFetchStats();

        $this->logger->notice("Reconciling playlists for '{user}' across {backend_count} backends.", [
            'event_name' => 'playlist.sync.started',
            'subsystem' => 'playlist',
            'operation' => 'sync',
            'outcome' => 'started',
            'user' => $userContext->name,
            'backend_count' => count($clients),
            'backends' => array_keys($clients),
            'dry_run' => $dryRun,
            'force_full' => $forceFull,
            'source_backends' => $sourceBackends,
            'target_backends' => $targetBackends,
            'memory' => $this->getMemoryContext(),
        ]);

        $fetchStart = microtime(true);

        foreach ($clients as $backendName => $client) {
            $snapshot = $this->fetchBackendPlaylists(
                client: $client,
                userContext: $userContext,
                store: $store,
                opts: [
                    Options::FORCE_FULL => true === $dryRun || true === $forceFull,
                    'last_sync' => $this->getPlaylistImportLastSync($userContext, $backendName),
                    'phase' => 'import',
                    'direction' => $this->resolveBackendDirection($backendName, $sourceBackends, $targetBackends),
                ],
            );
            $fetchTotals = $this->mergeFetchStats($fetchTotals, $snapshot['stats']);
            $results[$backendName] = $this->makeSummary($snapshot['playlists']);

            if (false === $snapshot['ok']) {
                continue;
            }

            $fetchedBackends[$backendName] = true;

            $syncStats[$backendName] = [
                'added' => 0,
                'updated' => 0,
                'removed' => 0,
            ];

            if (true === $dryRun) {
                continue;
            }

            $store->replaceBackendPlaylists($backendName, $snapshot['playlists'], $snapshot['seen_backend_ids']);
            $this->setPlaylistImportLastSync($userContext, $backendName, time());
        }

        $sourceBackends = array_values(array_filter(
            $sourceBackends,
            static fn(string $backendName): bool => true === isset($fetchedBackends[$backendName]),
        ));
        $targetBackends = array_values(array_filter(
            $targetBackends,
            static fn(string $backendName): bool => true === isset($fetchedBackends[$backendName]),
        ));

        $this->logger->notice("Fetched playlist snapshots for '{user}' in {duration_seconds}s.", [
            'event_name' => 'playlist.fetch.completed',
            'subsystem' => 'playlist',
            'operation' => 'fetch',
            'outcome' => 'completed',
            'user' => $userContext->name,
            'duration_seconds' => round(microtime(true) - $fetchStart, 4),
            'fetched_backends' => array_keys($fetchedBackends),
            'source_backends' => $sourceBackends,
            'target_backends' => $targetBackends,
            'stats' => $fetchTotals,
            'memory' => $this->getMemoryContext(),
        ]);

        if (true === $dryRun || [] === $targetBackends || [] === $sourceBackends) {
            $summary = $this->summarizeBackends($clients, $store, $results, $syncStats, $dryRun);

            $this->logger->notice("Playlist reconciliation for '{user}' stopped after the fetch phase.", [
                'event_name' => 'playlist.sync.stopped',
                'subsystem' => 'playlist',
                'operation' => 'sync',
                'outcome' => 'completed',
                'user' => $userContext->name,
                'reason' => $this->resolveEarlyStopReason($dryRun, $sourceBackends, $targetBackends),
                'results' => $this->summarizeResultTotals($summary),
                'duration_seconds' => round(microtime(true) - $syncStart, 4),
                'memory' => $this->getMemoryContext(),
            ]);

            return $summary;
        }

        $operations = $this->planSyncOperations(
            userContext: $userContext,
            store: $store,
            planner: $planner,
            sourceBackends: $sourceBackends,
            targetBackends: $targetBackends,
        );

        $operationSummary = $this->summarizeOperations($operations);
        $applyStart = microtime(true);

        $this->logger->notice("Applying {operation_count} planned playlist operations for '{user}'.", [
            'event_name' => 'playlist.operation.apply.started',
            'subsystem' => 'playlist',
            'operation' => 'apply',
            'outcome' => 'started',
            'user' => $userContext->name,
            'operation_count' => count($operations),
            'actions' => $operationSummary['actions'],
            'backends' => $operationSummary['backends'],
            'memory' => $this->getMemoryContext(),
        ]);

        $applied = $this->applySyncOperations($clients, $store, $operations);

        foreach ($applied['stats'] as $backendName => $stats) {
            $syncStats[$backendName] = array_replace($syncStats[$backendName] ?? [], $stats);
        }

        $exportSyncedBackends = [];
        foreach ($targetBackends as $backendName) {
            if (true === isset($applied['failed_backends'][$backendName])) {
                continue;
            }

            $this->setPlaylistExportLastSync($userContext, $backendName, time());
            $exportSyncedBackends[] = $backendName;
        }

        $this->logger->notice("Applied playlist operations for '{user}' in {duration_seconds}s.", [
            'event_name' => 'playlist.operation.apply.completed',
            'subsystem' => 'playlist',
            'operation' => 'apply',
            'outcome' => 'completed',
            'user' => $userContext->name,
            'duration_seconds' => round(microtime(true) - $applyStart, 4),
            'stats' => $this->summarizeSyncStats($applied['stats']),
            'touched_backends' => $applied['touched_backends'],
            'failed_backends' => array_keys($applied['failed_backends']),
            'export_synced_backends' => $exportSyncedBackends,
            'memory' => $this->getMemoryContext(),
        ]);

        $refreshTotals = $this->makeFetchStats();
        $refreshStart = microtime(true);

        if ([] !== $applied['touched_backends']) {
            $this->logger->notice("Refreshing {backend_count} touched playlist backends for '{user}'.", [
                'event_name' => 'playlist.refresh.started',
                'subsystem' => 'playlist',
                'operation' => 'refresh',
                'outcome' => 'started',
                'user' => $userContext->name,
                'backend_count' => count($applied['touched_backends']),
                'backends' => $applied['touched_backends'],
                'memory' => $this->getMemoryContext(),
            ]);
        }

        foreach ($applied['touched_backends'] as $backendName) {
            $refresh = $this->fetchBackendPlaylists(
                client: $clients[$backendName],
                userContext: $userContext,
                store: $store,
                opts: [
                    Options::FORCE_FULL => true,
                    'force_ids' => array_keys($applied['refresh_sync_ids'][$backendName] ?? []),
                    'phase' => 'refresh',
                    'direction' => $this->resolveBackendDirection($backendName, $sourceBackends, $targetBackends),
                    'sync_id_overrides' => $applied['refresh_sync_ids'][$backendName] ?? [],
                ],
            );
            $refreshTotals = $this->mergeFetchStats($refreshTotals, $refresh['stats']);

            if (false === $refresh['ok']) {
                continue;
            }

            $store->replaceBackendPlaylists($backendName, $refresh['playlists'], $refresh['seen_backend_ids']);
            $this->setPlaylistImportLastSync($userContext, $backendName, time());
        }

        if ([] !== $applied['touched_backends']) {
            $this->logger->notice("Refreshed touched playlist backends for '{user}' in {duration_seconds}s.", [
                'event_name' => 'playlist.refresh.completed',
                'subsystem' => 'playlist',
                'operation' => 'refresh',
                'outcome' => 'completed',
                'user' => $userContext->name,
                'duration_seconds' => round(microtime(true) - $refreshStart, 4),
                'backends' => $applied['touched_backends'],
                'stats' => $refreshTotals,
                'memory' => $this->getMemoryContext(),
            ]);
        }

        $summary = $this->summarizeBackends($clients, $store, $results, $syncStats, false);

        $this->logger->notice("Playlist reconciliation for '{user}' completed in {duration_seconds}s.", [
            'event_name' => 'playlist.sync.completed',
            'subsystem' => 'playlist',
            'operation' => 'sync',
            'outcome' => 'completed',
            'user' => $userContext->name,
            'duration_seconds' => round(microtime(true) - $syncStart, 4),
            'results' => $this->summarizeResultTotals($summary),
            'memory' => $this->getMemoryContext(),
        ]);

        return $summary;
    }

    /**
     * @param array{
     *   FORCE_FULL?:bool,
     *   force_ids?:array<int,string>,
     *   last_sync?:int|null,
     *   phase?:string,
     *   direction?:string,
     *   sync_id_overrides?:array<string,string>
     * } $opts
     *
     * @return array{
     *   ok:bool,
     *   playlists:array<int,array<string,mixed>>,
     *   seen_backend_ids:array<int,string>,
     *   stats:array{
     *     summaries:int,
     *     details:int,
     *     snapshots:int,
     *     items:int,
     *     eligible:int,
     *     ineligible:int,
     *     skipped_unchanged:int,
     *     detail_failures:int,
     *     list_failures:int,
     *     forced_ids:int
     *   }
     * }
     */
    private function fetchBackendPlaylists(
        iClient $client,
        UserContext $userContext,
        PlaylistStore $store,
        array $opts = [],
    ): array {
        $backendName = $client->getContext()->backendName;
        $phase = (string) ($opts['phase'] ?? 'import');
        $direction = (string) ($opts['direction'] ?? 'unknown');
        $forceFull = true === (bool) ($opts[Options::FORCE_FULL] ?? false);
        $lastSync = is_numeric($opts['last_sync'] ?? null) ? (int) $opts['last_sync'] : null;
        $syncIdOverrides = $opts['sync_id_overrides'] ?? [];
        $forceIds = [];
        $stats = $this->makeFetchStats();
        $start = microtime(true);

        foreach ($opts['force_ids'] ?? [] as $playlistId) {
            $forceIds[(string) $playlistId] = true;
        }

        $stats['forced_ids'] = count($forceIds);

        $this->logger->notice("Fetching {mode} playlist snapshot from '{user}@{backend}' ({direction}, {phase}).", [
            'event_name' => 'playlist.fetch.started',
            'subsystem' => 'playlist',
            'operation' => 'fetch',
            'outcome' => 'started',
            'phase' => $phase,
            'user' => $userContext->name,
            'backend' => $backendName,
            'mode' => true === $forceFull ? 'full' : 'incremental',
            'direction' => $direction,
            'last_sync' => null === $lastSync ? 'Beginning' : (string) make_date($lastSync),
            'forced_ids' => array_keys($forceIds),
            'memory' => $this->getMemoryContext(),
        ]);

        try {
            $playlistSummaries = $client->getPlaylistsList([Options::RAW_RESPONSE => true]);
        } catch (Throwable $e) {
            $stats['list_failures'] = 1;
            $this->logBackendThrowable(
                e: $e,
                backendName: $backendName,
                user: $userContext->name,
                operation: 'fetch_playlists_list',
                eventName: 'playlist.fetch.failed',
                operationLabel: 'fetch the playlist list',
            );

            return [
                'ok' => false,
                'playlists' => [],
                'seen_backend_ids' => [],
                'stats' => $stats,
            ];
        }

        $playlists = [];
        $seenBackendIds = [];
        $stats['summaries'] = count($playlistSummaries);

        foreach ($playlistSummaries as $playlistSummary) {
            $playlistId = trim((string) ag($playlistSummary, 'id'));
            if ('' === $playlistId) {
                continue;
            }

            if ('' !== $playlistId) {
                $seenBackendIds[] = $playlistId;
            }

            if (
                false === $this->shouldFetchPlaylistDetails(
                    store: $store,
                    backendName: $client->getContext()->backendName,
                    playlistSummary: $playlistSummary,
                    forceFull: $forceFull,
                    lastSync: $lastSync,
                    forceFetch: true === isset($forceIds[$playlistId]),
                )
            ) {
                $stats['skipped_unchanged']++;
                continue;
            }

            $stats['details']++;

            try {
                $playlist = $client->getPlaylist($playlistId, [Options::RAW_RESPONSE => true]);
            } catch (Throwable $e) {
                $stats['detail_failures']++;
                $this->logBackendThrowable(
                    e: $e,
                    backendName: $backendName,
                    user: $userContext->name,
                    operation: 'fetch_playlist',
                    playlistId: $playlistId,
                    playlistTitle: (string) ag($playlistSummary, 'title', 'Untitled playlist'),
                    eventName: 'playlist.fetch.failed',
                    operationLabel: 'fetch',
                );
                continue;
            }

            $snapshot = $this->buildPlaylistSnapshot(
                client: $client,
                userContext: $userContext,
                playlist: $playlist,
                syncId: $syncIdOverrides[$playlistId] ?? null,
            );

            if (null !== $snapshot) {
                $snapshot = $this->preserveGeneratedPartialState(
                    store: $store,
                    backendName: $backendName,
                    snapshot: $snapshot,
                );

                $stats['snapshots']++;
                $stats['items'] += count($snapshot['items'] ?? []);
                if (true === (bool) ag($snapshot, 'metadata.sync.eligible', true)) {
                    $stats['eligible']++;
                } else {
                    $stats['ineligible']++;
                }

                $playlists[] = $snapshot;
            }
        }

        $this->logger->info("Fetched {phase} playlist snapshot from '{user}@{backend}' in {duration_seconds}s.", [
            'event_name' => 'playlist.fetch.backend.completed',
            'subsystem' => 'playlist',
            'operation' => 'fetch',
            'outcome' => 'completed',
            'phase' => $phase,
            'user' => $userContext->name,
            'backend' => $backendName,
            'duration_seconds' => round(microtime(true) - $start, 4),
            'direction' => $direction,
            'mode' => true === $forceFull ? 'full' : 'incremental',
            'stats' => $stats,
            'memory' => $this->getMemoryContext(),
        ]);

        return [
            'ok' => true,
            'playlists' => $playlists,
            'seen_backend_ids' => array_values(array_unique($seenBackendIds)),
            'stats' => $stats,
        ];
    }

    /**
     * @param array<string,mixed> $playlist
     * @param string|null $syncId
     *
     * @return array<string,mixed>|null
     */
    private function buildPlaylistSnapshot(
        iClient $client,
        UserContext $userContext,
        array $playlist,
        ?string $syncId = null,
    ): ?array {
        $eligibility = $this->getPlaylistEligibility($playlist);
        $remoteItems = array_values($playlist['items'] ?? []);
        $resolvedItems = [];

        if (true === $eligibility['eligible']) {
            foreach ($remoteItems as $position => $item) {
                $resolved = $this->resolvePlaylistItem($client, $userContext, $item, $position);
                if (null === $resolved) {
                    continue;
                }

                $resolvedItems[] = $resolved;
            }

            if (count($remoteItems) > 0 && count($resolvedItems) !== count($remoteItems)) {
                $eligibility = [
                    'eligible' => false,
                    'reason' => 'unresolved',
                ];
                $resolvedItems = [];

                $this->logger->info(
                    "Skipping playlist '{playlist_title}' from '{user}@{backend}': some items did not resolve to local state.",
                    [
                        'event_name' => 'playlist.snapshot.skipped',
                        'subsystem' => 'playlist',
                        'operation' => 'snapshot',
                        'outcome' => 'skipped',
                        'user' => $userContext->name,
                        'backend' => $client->getContext()->backendName,
                        'playlist_id' => (string) ag($playlist, 'id', ''),
                        'playlist_title' => (string) ag($playlist, 'title', 'Untitled playlist'),
                        'reason' => 'unresolved_items',
                    ],
                );
            }
        }

        $builder = [
            'id' => (string) ag($playlist, 'id'),
            'title' => (string) ag($playlist, 'title', 'Untitled playlist'),
            'type' => (string) ag($playlist, 'type', 'video'),
            'summary' => ag($playlist, 'summary'),
            'editable' => true === (bool) ag($playlist, 'editable', true),
            'smart' => true === (bool) ag($playlist, 'smart', false),
            'public' => true === (bool) ag($playlist, 'public', false),
            'remote_updated_at' => $this->resolveRemoteUpdatedAt($playlist),
            'metadata' => [
                'backend' => $client->getContext()->backendName,
                'remote_item_count' => (int) ag($playlist, 'itemCount', count($remoteItems)),
                'raw' => ag($playlist, 'raw', []),
                'sync' => [
                    'eligible' => $eligibility['eligible'],
                    'reason' => $eligibility['reason'],
                ],
            ],
            'items' => $resolvedItems,
        ];

        if (null !== $syncId && '' !== trim($syncId)) {
            $builder['sync_id'] = trim($syncId);
        }

        return $builder;
    }

    /**
     * @param array<string,mixed> $playlist
     *
     * @return array{eligible:bool,reason:?string}
     */
    private function getPlaylistEligibility(array $playlist): array
    {
        if ('video' !== strtolower((string) ag($playlist, 'type', 'video'))) {
            return ['eligible' => false, 'reason' => 'type'];
        }

        if (true === (bool) ag($playlist, 'smart', false)) {
            return ['eligible' => false, 'reason' => 'smart'];
        }

        if (false === (bool) ag($playlist, 'editable', true)) {
            return ['eligible' => false, 'reason' => 'readonly'];
        }

        return ['eligible' => true, 'reason' => null];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolvePlaylistItem(iClient $client, UserContext $userContext, array $item, int $position): ?array
    {
        $type = strtolower((string) ag($item, ['type', 'Type'], ''));
        if (false === in_array($type, [iState::TYPE_MOVIE, iState::TYPE_EPISODE], true)) {
            return null;
        }

        try {
            $entity = $client->toEntity($item, [Options::NO_CACHE => true]);
        } catch (Throwable $e) {
            $this->logger->warning(
                "Failed to map playlist item '{item_title}' on backend '{backend}'.",
                [
                    'event_name' => 'playlist.item.map.failed',
                    'subsystem' => 'playlist',
                    'operation' => 'map_item',
                    'outcome' => 'failed',
                    'user' => $userContext->name,
                    'backend' => $client->getName(),
                    'item_title' => (string) ag($item, ['title', 'Name', 'OriginalTitle'], 'Unknown item'),
                    ...exception_log($e),
                ],
            );

            return null;
        }

        $local = $userContext->db->get($entity);
        if (null === $local) {
            return null;
        }

        return [
            'position' => $position,
            'state_id' => $local->id,
            'backend_item_id' => (string) ag($item, ['ratingKey', 'Id'], ''),
            'backend_entry_id' => ag($item, ['playlistItemID', 'PlaylistItemId']),
            'item_type' => $type,
            'title' => (string) ag($item, ['title', 'Name', 'OriginalTitle'], 'Unknown item'),
            'metadata' => ['raw' => $item],
        ];
    }

    /**
     * @param PlaylistStore $store
     * @param PlaylistSyncPlanner $planner
     * @param array<int,string> $sourceBackends
     * @param array<int,string> $targetBackends
     *
     * @return array<int,array<string,mixed>>
     */
    private function planSyncOperations(
        UserContext $userContext,
        PlaylistStore $store,
        PlaylistSyncPlanner $planner,
        array $sourceBackends,
        array $targetBackends,
    ): array {
        $start = microtime(true);
        $operations = [];
        $groups = $store->getSyncGroups();
        $planned = [
            'create' => 0,
            'replace' => 0,
            'delete' => 0,
        ];
        $perBackend = [];
        $skipped = [
            'no_source' => 0,
            'no_winner' => 0,
            'up_to_date' => 0,
            'unresolved_items' => 0,
            'partial_waiting' => 0,
            'zero_available' => 0,
        ];

        if ([] === $targetBackends) {
            return $operations;
        }

        $this->logger->notice("Planning playlist operations for '{user}' from {snapshot_count} sync groups.", [
            'event_name' => 'playlist.plan.started',
            'subsystem' => 'playlist',
            'operation' => 'plan',
            'outcome' => 'started',
            'user' => $userContext->name,
            'snapshot_count' => count($groups),
            'backend_count' => count($targetBackends),
            'source_backends' => $sourceBackends,
            'target_backends' => $targetBackends,
            'memory' => $this->getMemoryContext(),
        ]);

        foreach ($groups as $syncId => $group) {
            $collapsed = $planner->collapseGroup($group);
            $sourceCandidates = array_filter(
                $collapsed,
                static fn(array $playlist): bool => true === in_array((string) ($playlist['backend'] ?? ''), $sourceBackends, true),
            );

            if ([] === $sourceCandidates) {
                $skipped['no_source']++;
                continue;
            }

            $winner = $planner->selectWinner($sourceCandidates);

            if (null === $winner) {
                $skipped['no_winner']++;

                $sample = $collapsed[array_key_first($collapsed)] ?? $group[array_key_first($group)] ?? [];
                $this->logger->info("No source available for playlist '{playlist_title}' on '{target_backend}'.", [
                    'event_name' => 'playlist.plan.winner_unavailable',
                    'subsystem' => 'playlist',
                    'operation' => 'plan',
                    'outcome' => 'skipped',
                    'user' => $userContext->name,
                    'playlist_id' => (string) ag($sample, 'sync_id', ag($sample, 'backend_id', '')),
                    'playlist_title' => (string) ag($sample, 'title', 'Untitled playlist'),
                    'target_backend' => implode(',', $targetBackends),
                    'reason' => 'no_winner',
                ]);
                continue;
            }

            $this->logger->info("Selected '{source_backend}' as source for playlist '{playlist_title}'.", [
                'event_name' => 'playlist.plan.winner_selected',
                'subsystem' => 'playlist',
                'operation' => 'plan',
                'outcome' => 'completed',
                'user' => $userContext->name,
                'playlist_id' => (string) ag($winner, 'sync_id', ag($winner, 'backend_id', '')),
                'playlist_title' => (string) ag($winner, 'title', 'Untitled playlist'),
                'source_backend' => (string) ag($winner, 'backend', 'unknown'),
            ]);

            foreach ($targetBackends as $backendName) {
                if ($backendName === (string) ($winner['backend'] ?? '')) {
                    continue;
                }

                $target = $collapsed[$backendName] ?? null;
                if (null !== $target) {
                    $target = $this->clearSatisfiedPartialTargetState($store, $target, $winner);
                }

                if (false === $planner->shouldSync($target, $winner)) {
                    $skipped['up_to_date']++;
                    continue;
                }

                if (null !== ($winner['deleted_at'] ?? null)) {
                    $planned['delete']++;
                    $perBackend[$backendName]['delete'] = ($perBackend[$backendName]['delete'] ?? 0) + 1;
                    $operations[] = [
                        'action' => 'delete',
                        'backend' => $backendName,
                        'sync_id' => $syncId,
                        'winner' => $winner,
                        'target' => $target,
                    ];
                    continue;
                }

                $resolution = $this->resolveTargetItemsForBackend($userContext, $backendName, $winner);
                if (0 === $resolution['available_count']) {
                    $skipped['unresolved_items']++;
                    $skipped['zero_available']++;

                    $this->logger->info(
                        "Waiting to sync playlist '{playlist_title}' for '{user}@{backend}': no source items are available on the target backend yet.",
                        [
                            'event_name' => 'playlist.sync.waiting',
                            'subsystem' => 'playlist',
                            'operation' => 'sync',
                            'outcome' => 'waiting',
                            'user' => $userContext->name,
                            'backend' => $backendName,
                            'playlist_id' => (string) ag($winner, 'sync_id', ag($winner, 'backend_id', '')),
                            'playlist_title' => (string) ag($winner, 'title', 'Untitled playlist'),
                            'winner_backend' => ag($winner, 'backend', 'unknown'),
                            'available_count' => $resolution['available_count'],
                            'total_count' => $resolution['total_count'],
                            'missing_titles' => $resolution['missing_titles'],
                            'reason' => 'no_winner_items',
                        ],
                    );

                    continue;
                }

                if (
                    null !== $target
                    && true === $this->isGeneratedPartialPlaylist($target)
                    && true === $this->shouldWaitForPartialTarget($target, $winner, $resolution)
                ) {
                    $skipped['partial_waiting']++;

                    $this->logger->info(
                        "Waiting to complete playlist '{playlist_title}' for '{user}@{backend}': {available_count} of {total_count} items are available on the target backend.",
                        [
                            'event_name' => 'playlist.sync.waiting',
                            'subsystem' => 'playlist',
                            'operation' => 'sync',
                            'outcome' => 'waiting',
                            'user' => $userContext->name,
                            'backend' => $backendName,
                            'playlist_id' => (string) ag($winner, 'sync_id', ag($winner, 'backend_id', '')),
                            'playlist_title' => (string) ag($winner, 'title', 'Untitled playlist'),
                            'available_count' => $resolution['available_count'],
                            'total_count' => $resolution['total_count'],
                            'missing_titles' => $resolution['missing_titles'],
                            'reason' => 'partial_target_waiting',
                        ],
                    );

                    continue;
                }

                $action = null === $target || null !== ($target['deleted_at'] ?? null) ? 'create' : 'replace';
                $planned[$action]++;
                $perBackend[$backendName][$action] = ($perBackend[$backendName][$action] ?? 0) + 1;

                if ($resolution['missing_count'] > 0) {
                    $perBackend[$backendName]['partial'] = ($perBackend[$backendName]['partial'] ?? 0) + 1;

                    $this->logger->info(
                        "Planning partial {action} for playlist '{playlist_title}' on '{user}@{backend}': {available_count} of {total_count} items are available.",
                        [
                            'event_name' => 'playlist.operation.planned',
                            'subsystem' => 'playlist',
                            'operation' => 'plan',
                            'outcome' => 'completed',
                            'user' => $userContext->name,
                            'action' => $action,
                            'backend' => $backendName,
                            'playlist_id' => (string) ag($winner, 'sync_id', ag($winner, 'backend_id', '')),
                            'playlist_title' => (string) ag($winner, 'title', 'Untitled playlist'),
                            'available_count' => $resolution['available_count'],
                            'total_count' => $resolution['total_count'],
                            'missing_titles' => $resolution['missing_titles'],
                            'reason' => 'partial_target_items',
                        ],
                    );
                }

                $this->logger->info("Planned {action} for playlist '{playlist_title}' on '{user}@{backend}' ({item_count} items).", [
                    'event_name' => 'playlist.operation.planned',
                    'subsystem' => 'playlist',
                    'operation' => 'plan',
                    'outcome' => 'completed',
                    'action' => $action,
                    'user' => $userContext->name,
                    'backend' => $backendName,
                    'playlist_id' => (string) ag($winner, 'sync_id', ag($winner, 'backend_id', '')),
                    'playlist_title' => (string) ag($winner, 'title', 'Untitled playlist'),
                    'item_count' => count($resolution['items']),
                ]);

                $operations[] = [
                    'action' => $action,
                    'backend' => $backendName,
                    'sync_id' => $syncId,
                    'winner' => $winner,
                    'target' => $target,
                    'items' => $resolution['items'],
                    'sync_metadata' => $this->makeTargetSyncMetadata($winner, $resolution),
                ];
            }
        }

        $this->logger->info("Planned {operation_count} playlist operations for '{user}' in {duration_seconds}s.", [
            'event_name' => 'playlist.plan.completed',
            'subsystem' => 'playlist',
            'operation' => 'plan',
            'outcome' => 'completed',
            'user' => $userContext->name,
            'operation_count' => count($operations),
            'duration_seconds' => round(microtime(true) - $start, 4),
            'actions' => $planned,
            'backends' => $perBackend,
            'skipped' => $skipped,
            'memory' => $this->getMemoryContext(),
        ]);

        return $operations;
    }

    /**
     * @param array<string,iClient> $clients
     * @param array<int,array<string,mixed>> $operations
     *
     * @return array{
     *   touched_backends:array<int,string>,
     *   refresh_sync_ids:array<string,array<string,string>>,
     *   failed_backends:array<string,bool>,
     *   stats:array<string,array{added:int,updated:int,removed:int}>
     * }
     */
    private function applySyncOperations(array $clients, PlaylistStore $store, array $operations): array
    {
        $touchedBackends = [];
        $refreshSyncIds = [];
        $failedBackends = [];
        $stats = [];

        foreach ($operations as $operation) {
            $backendName = (string) $operation['backend'];
            $client = $clients[$backendName] ?? null;
            if (null === $client) {
                $failedBackends[$backendName] = true;
                continue;
            }

            $target = $operation['target'] ?? null;
            $winner = $operation['winner'] ?? [];

            if ('delete' === $operation['action']) {
                if (null === $target) {
                    continue;
                }

                try {
                    $client->deletePlaylist((string) ($target['backend_id'] ?? ''));
                } catch (Throwable $e) {
                    $this->logBackendThrowable(
                        e: $e,
                        backendName: $backendName,
                        user: $client->getContext()->userContext->name,
                        operation: 'delete',
                        playlistId: (string) ($target['backend_id'] ?? ''),
                        playlistTitle: (string) ($target['title'] ?? 'Untitled playlist'),
                    );
                    $failedBackends[$backendName] = true;
                    continue;
                }

                $store->markDeleted((int) $target['id']);
                $touchedBackends[$backendName] = true;
                $stats[$backendName]['removed'] = ($stats[$backendName]['removed'] ?? 0) + 1;
                $this->logger->notice("Applied {operation} to playlist '{playlist_title}' on '{user}@{backend}'.", [
                    'event_name' => 'playlist.operation.applied',
                    'subsystem' => 'playlist',
                    'operation' => 'delete',
                    'outcome' => 'completed',
                    'user' => $client->getContext()->userContext->name,
                    'backend' => $backendName,
                    'playlist_id' => (string) ($target['backend_id'] ?? $target['sync_id'] ?? ''),
                    'playlist_title' => (string) ($target['title'] ?? 'Untitled playlist'),
                    'item_count' => count($target['items'] ?? []),
                ]);
                continue;
            }

            if ('replace' === $operation['action'] && null !== $target) {
                try {
                    $client->deletePlaylist((string) ($target['backend_id'] ?? ''));
                } catch (Throwable $e) {
                    $this->logBackendThrowable(
                        e: $e,
                        backendName: $backendName,
                        user: $client->getContext()->userContext->name,
                        operation: 'delete_before_replace',
                        playlistId: (string) ($target['backend_id'] ?? ''),
                        playlistTitle: (string) ($target['title'] ?? 'Untitled playlist'),
                        operationLabel: 'delete before replace',
                    );
                    $failedBackends[$backendName] = true;
                    continue;
                }

                $store->markDeleted((int) $target['id']);
                $touchedBackends[$backendName] = true;
            }

            try {
                $createResult = $client->createPlaylist(
                    title: (string) ($winner['title'] ?? 'Untitled playlist'),
                    itemIds: array_map(static fn(array $item) => (string) ($item['backend_item_id'] ?? ''), $operation['items'] ?? []),
                );
            } catch (Throwable $e) {
                $this->logBackendThrowable(
                    e: $e,
                    backendName: $backendName,
                    user: $client->getContext()->userContext->name,
                    operation: 'create',
                    playlistId: '',
                    playlistTitle: (string) ($winner['title'] ?? 'Untitled playlist'),
                );
                $failedBackends[$backendName] = true;
                continue;
            }

            $touchedBackends[$backendName] = true;
            $createdId = trim((string) ag($createResult, 'id', ''));
            if ('' === $createdId) {
                $this->logger->warning(
                    "Create playlist request for '{user}@{backend}' returned no playlist id for '{playlist_title}'.",
                    [
                        'event_name' => 'playlist.operation.response_invalid',
                        'subsystem' => 'playlist',
                        'operation' => 'create',
                        'outcome' => 'failed',
                        'user' => $client->getContext()->userContext->name,
                        'backend' => $backendName,
                        'playlist_title' => (string) ($winner['title'] ?? 'Untitled playlist'),
                        'reason' => 'missing_playlist_id',
                    ],
                );
                $failedBackends[$backendName] = true;
                continue;
            }

            $refreshSyncIds[$backendName][$createdId] = (string) $operation['sync_id'];
            $stats[$backendName]['replace' === $operation['action'] ? 'updated' : 'added'] =
                ($stats[$backendName]['replace' === $operation['action'] ? 'updated' : 'added'] ?? 0) + 1;

            $store->upsertBackendPlaylist($backendName, $this->makeOptimisticPlaylistSnapshot(
                backend: $backendName,
                backendId: $createdId,
                syncId: (string) $operation['sync_id'],
                winner: $winner,
                items: $operation['items'] ?? [],
                syncMetadata: $operation['sync_metadata'] ?? [],
            ));

            $this->logger->notice("Applied {operation} to playlist '{playlist_title}' on '{user}@{backend}'.", [
                'event_name' => 'playlist.operation.applied',
                'subsystem' => 'playlist',
                'operation' => (string) $operation['action'],
                'outcome' => 'completed',
                'user' => $client->getContext()->userContext->name,
                'backend' => $backendName,
                'playlist_id' => $createdId,
                'playlist_title' => (string) ($winner['title'] ?? 'Untitled playlist'),
                'item_count' => count($operation['items'] ?? []),
            ]);
        }

        return [
            'touched_backends' => array_keys($touchedBackends),
            'refresh_sync_ids' => $refreshSyncIds,
            'failed_backends' => $failedBackends,
            'stats' => $stats,
        ];
    }

    /**
     * @param array<string,mixed> $playlistSummary
     */
    private function shouldFetchPlaylistDetails(
        PlaylistStore $store,
        string $backendName,
        array $playlistSummary,
        bool $forceFull,
        ?int $lastSync,
        bool $forceFetch = false,
    ): bool {
        if (true === $forceFull || true === $forceFetch) {
            return true;
        }

        $playlistId = trim((string) ag($playlistSummary, 'id', ''));
        if ('' === $playlistId) {
            return false;
        }

        $stored = $store->getByBackendId($backendName, $playlistId);
        if (
            null === $stored
            || null !== ($stored['deleted_at'] ?? null)
            || true !== (bool) ag($stored, 'metadata.sync.eligible', true)
            || null === $lastSync
        ) {
            return true;
        }

        $summaryUpdatedAt = $this->resolveRemoteUpdatedAt($playlistSummary);
        if ($summaryUpdatedAt < 1) {
            return true;
        }

        return $summaryUpdatedAt > max($lastSync, (int) ($stored['remote_updated_at'] ?? 0));
    }

    /**
     * @param array<string,mixed> $winner
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed> $syncMetadata
     *
     * @return array<string,mixed>
     */
    private function makeOptimisticPlaylistSnapshot(
        string $backend,
        string $backendId,
        string $syncId,
        array $winner,
        array $items,
        array $syncMetadata = [],
    ): array {
        return [
            'id' => $backendId,
            'sync_id' => $syncId,
            'title' => (string) ($winner['title'] ?? 'Untitled playlist'),
            'type' => (string) ($winner['type'] ?? 'video'),
            'summary' => $winner['summary'] ?? null,
            'editable' => true,
            'smart' => false,
            'public' => true === (bool) ($winner['public'] ?? $winner['is_public'] ?? false),
            'remote_updated_at' => time(),
            'metadata' => [
                'backend' => $backend,
                'remote_item_count' => count($items),
                'sync' => array_replace([
                    'eligible' => true,
                    'reason' => null,
                ], $syncMetadata),
            ],
            'items' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $winner
     *
     * @return array{
     *   items:array<int,array<string,mixed>>,
     *   total_count:int,
     *   available_count:int,
     *   missing_count:int,
     *   missing_state_ids:array<int,int>,
     *   missing_titles:array<int,string>,
     *   content_hash:string
     * }
     */
    private function resolveTargetItemsForBackend(UserContext $userContext, string $backend, array $winner): array
    {
        $items = [];
        $missingStateIds = [];
        $missingTitles = [];

        foreach (array_values($winner['items'] ?? []) as $position => $item) {
            $stateId = $item['state_id'] ?? null;
            if (null === $stateId) {
                $missingTitles[] = (string) ($item['title'] ?? 'Unknown item');
                continue;
            }

            $entity = $this->getLocalStateById($userContext, (int) $stateId);
            if (null === $entity) {
                $this->logger->info(
                    "Skipping playlist '{playlist_title}' on '{backend}': local state '{state_id}' no longer exists.",
                    [
                        'event_name' => 'playlist.target.item.skipped',
                        'subsystem' => 'playlist',
                        'operation' => 'resolve_target_item',
                        'outcome' => 'skipped',
                        'backend' => $backend,
                        'playlist_id' => (string) ag($winner, 'sync_id', ag($winner, 'backend_id', '')),
                        'playlist_title' => (string) ag($winner, 'title', 'Untitled playlist'),
                        'state_id' => $stateId,
                        'reason' => 'missing_local_state',
                    ],
                );
                $missingStateIds[] = (int) $stateId;
                $missingTitles[] = (string) ($item['title'] ?? 'Unknown item');
                continue;
            }

            $backendId = trim((string) ag($entity->getMetadata($backend), iState::COLUMN_ID, ''));
            if ('' === $backendId) {
                $this->logger->info(
                    "Skipping item '{item_title}' for playlist '{playlist_title}' on '{backend}': it is not available on the target backend.",
                    [
                        'event_name' => 'playlist.target.item.skipped',
                        'subsystem' => 'playlist',
                        'operation' => 'resolve_target_item',
                        'outcome' => 'skipped',
                        'backend' => $backend,
                        'playlist_id' => (string) ag($winner, 'sync_id', ag($winner, 'backend_id', '')),
                        'playlist_title' => (string) ag($winner, 'title', 'Untitled playlist'),
                        'item_title' => $entity->getName(),
                        'state_id' => $stateId,
                        'reason' => 'missing_target_item',
                    ],
                );
                $missingStateIds[] = (int) $stateId;
                $missingTitles[] = $entity->getName();
                continue;
            }

            $items[] = [
                'position' => (int) ($item['position'] ?? $position),
                'state_id' => (int) $stateId,
                'backend_item_id' => $backendId,
                'backend_entry_id' => null,
                'item_type' => (string) ($item['item_type'] ?? $entity->type),
                'title' => (string) ($item['title'] ?? $entity->getName()),
                'metadata' => [],
            ];
        }

        return [
            'items' => $items,
            'total_count' => count($winner['items'] ?? []),
            'available_count' => count($items),
            'missing_count' => count($missingTitles),
            'missing_state_ids' => array_values(array_unique($missingStateIds)),
            'missing_titles' => array_values(array_unique($missingTitles)),
            'content_hash' => $this->makePlaylistContentHash([
                'title' => (string) ($winner['title'] ?? ''),
                'type' => (string) ($winner['type'] ?? 'video'),
                'items' => $items,
            ]),
        ];
    }

    private function getLocalStateById(UserContext $userContext, int $stateId): ?iState
    {
        return $userContext->db->get(Container::get(iState::class)::fromArray([
            iState::COLUMN_ID => $stateId,
        ]));
    }

    /**
     * @param array<int,array<string,mixed>> $playlists
     *
     * @return array{playlists:int,items:int,added:int,updated:int,removed:int}
     */
    private function makeSummary(array $playlists): array
    {
        $visible = array_values(array_filter(
            $playlists,
            static fn(array $playlist): bool => (
                null === ($playlist['deleted_at'] ?? null)
                && true === (bool) ag($playlist, 'metadata.sync.eligible', true)
            ),
        ));

        return [
            'playlists' => count($visible),
            'items' => array_sum(array_map(static fn(array $playlist) => count($playlist['items'] ?? []), $visible)),
            'added' => 0,
            'updated' => 0,
            'removed' => 0,
        ];
    }

    /**
     * @param array<string,iClient> $clients
     * @param array<string,array{playlists:int,items:int,added:int,updated:int,removed:int}> $results
     * @param array<string,array{added:int,updated:int,removed:int}> $syncStats
     *
     * @return array<string,array{playlists:int,items:int,added:int,updated:int,removed:int}>
     */
    private function summarizeBackends(
        array $clients,
        PlaylistStore $store,
        array $results,
        array $syncStats,
        bool $dryRun,
    ): array {
        if (true === $dryRun) {
            return $results;
        }

        foreach (array_keys($clients) as $backendName) {
            $results[$backendName] = array_replace(
                $results[$backendName],
                $this->makeSummary($store->getByBackend($backendName)),
                $syncStats[$backendName] ?? [],
            );
        }

        return $results;
    }

    /**
     * @param array<string,mixed> $winner
     * @param array{
     *   items:array<int,array<string,mixed>>,
     *   total_count:int,
     *   available_count:int,
     *   missing_count:int,
     *   missing_state_ids:array<int,int>,
     *   missing_titles:array<int,string>,
     *   content_hash:string
     * } $resolution
     *
     * @return array<string,mixed>
     */
    private function makeTargetSyncMetadata(array $winner, array $resolution): array
    {
        if (0 === $resolution['missing_count']) {
            return [];
        }

        return [
            'partial' => true,
            'generated_by_sync' => true,
            'partial_reason' => 'missing_target_items',
            'source_backend' => (string) ($winner['backend'] ?? 'unknown'),
            'expected_item_count' => $resolution['total_count'],
            'available_item_count' => $resolution['available_count'],
            'missing_state_ids' => $resolution['missing_state_ids'],
            'missing_titles' => $resolution['missing_titles'],
            'desired_content_hash' => (string) ($winner['content_hash'] ?? $this->makePlaylistContentHash($winner)),
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     *
     * @return array<string,mixed>
     */
    private function preserveGeneratedPartialState(PlaylistStore $store, string $backendName, array $snapshot): array
    {
        $backendId = trim((string) ($snapshot['id'] ?? ''));
        if ('' === $backendId) {
            return $snapshot;
        }

        $stored = $store->getByBackendId($backendName, $backendId);
        if (null === $stored || true !== $this->isGeneratedPartialPlaylist($stored)) {
            return $snapshot;
        }

        $storedSync = $this->getSyncMetadata($stored);
        $desiredHash = (string) ($storedSync['desired_content_hash'] ?? '');
        $currentHash = $this->makePlaylistContentHash($snapshot);

        if ('' !== $desiredHash && $desiredHash === $currentHash) {
            return $this->clearGeneratedPartialSyncMetadata($snapshot);
        }

        $snapshot['metadata']['sync'] = array_replace(
            ag($snapshot, 'metadata.sync', []),
            $storedSync,
            [
                'eligible' => true === (bool) ag($snapshot, 'metadata.sync.eligible', true),
                'reason' => ag($snapshot, 'metadata.sync.reason'),
                'available_item_count' => count($snapshot['items'] ?? []),
            ],
        );

        return $snapshot;
    }

    /**
     * @param array<string,mixed> $target
     * @param array<string,mixed> $winner
     *
     * @return array<string,mixed>
     */
    private function clearSatisfiedPartialTargetState(PlaylistStore $store, array $target, array $winner): array
    {
        if (true !== $this->isGeneratedPartialPlaylist($target)) {
            return $target;
        }

        $currentHash = (string) ($target['content_hash'] ?? '');
        $desiredHash = (string) ag($target, 'metadata.sync.desired_content_hash', '');
        $winnerHash = (string) ($winner['content_hash'] ?? '');

        if ('' === $currentHash || $currentHash !== $desiredHash && $currentHash !== $winnerHash) {
            return $target;
        }

        $cleared = $this->clearGeneratedPartialSyncMetadata($target);
        $store->updateMetadata((int) $target['id'], $cleared['metadata'] ?? []);

        $this->logger->info(
            "Cleared the partial sync marker for playlist '{playlist_title}' on '{backend}'; the target playlist now matches the desired state.",
            [
                'event_name' => 'playlist.partial_marker.cleared',
                'subsystem' => 'playlist',
                'operation' => 'clear_partial_marker',
                'outcome' => 'completed',
                'backend' => (string) ag($target, 'backend', 'unknown'),
                'playlist_id' => (string) ag($target, 'backend_id', ag($target, 'sync_id', '')),
                'playlist_title' => (string) ag($target, 'title', 'Untitled playlist'),
            ],
        );

        return array_replace($target, [
            'metadata' => $cleared['metadata'] ?? [],
        ]);
    }

    /**
     * @param array<string,mixed> $target
     * @param array<string,mixed> $winner
     * @param array{
     *   items:array<int,array<string,mixed>>,
     *   total_count:int,
     *   available_count:int,
     *   missing_count:int,
     *   missing_state_ids:array<int,int>,
     *   missing_titles:array<int,string>,
     *   content_hash:string
     * } $resolution
     */
    private function shouldWaitForPartialTarget(array $target, array $winner, array $resolution): bool
    {
        if (0 === $resolution['missing_count']) {
            return false;
        }

        return (
            (string) ag($target, 'metadata.sync.desired_content_hash', '') === (string) ($winner['content_hash'] ?? '')
            && (string) ($target['content_hash'] ?? '') === $resolution['content_hash']
        );
    }

    /**
     * @param array<string,mixed>|null $playlist
     */
    private function isGeneratedPartialPlaylist(?array $playlist): bool
    {
        if (null === $playlist) {
            return false;
        }

        return (
            true === (bool) ag($playlist, 'metadata.sync.partial', false)
            && true === (bool) ag($playlist, 'metadata.sync.generated_by_sync', false)
        );
    }

    /**
     * @param array<string,mixed> $playlist
     *
     * @return array<string,mixed>
     */
    private function clearGeneratedPartialSyncMetadata(array $playlist): array
    {
        $sync = $this->getSyncMetadata($playlist);

        unset(
            $sync['partial'],
            $sync['generated_by_sync'],
            $sync['partial_reason'],
            $sync['source_backend'],
            $sync['expected_item_count'],
            $sync['available_item_count'],
            $sync['missing_state_ids'],
            $sync['missing_titles'],
            $sync['desired_content_hash'],
        );

        $playlist['metadata']['sync'] = $sync;

        return $playlist;
    }

    /**
     * @param array<string,mixed> $playlist
     *
     * @return array<string,mixed>
     */
    private function getSyncMetadata(array $playlist): array
    {
        $sync = ag($playlist, 'metadata.sync', []);

        return true === is_array($sync) ? $sync : [];
    }

    /**
     * @param array<string,mixed> $playlist
     */
    private function makePlaylistContentHash(array $playlist): string
    {
        $itemKeys = [];

        foreach (array_values($playlist['items'] ?? []) as $index => $item) {
            $stateId = null !== ($item['state_id'] ?? null) ? (int) $item['state_id'] : null;

            $itemKeys[] = [
                'position' => (int) ($item['position'] ?? $index),
                'state_id' => $stateId,
                'backend_item_id' => null === $stateId ? (string) ($item['backend_item_id'] ?? '') : '',
                'item_type' => (string) ($item['item_type'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
            ];
        }

        $payload = json_encode(
            [
                'title' => (string) ($playlist['title'] ?? ''),
                'type' => strtolower((string) ($playlist['type'] ?? 'video')),
                'items' => $itemKeys,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE,
        );

        return hash('sha256', false !== $payload ? $payload : '{}');
    }

    /**
     * @return array{
     *   summaries:int,
     *   details:int,
     *   snapshots:int,
     *   items:int,
     *   eligible:int,
     *   ineligible:int,
     *   skipped_unchanged:int,
     *   detail_failures:int,
     *   list_failures:int,
     *   forced_ids:int
     * }
     */
    private function makeFetchStats(): array
    {
        return [
            'summaries' => 0,
            'details' => 0,
            'snapshots' => 0,
            'items' => 0,
            'eligible' => 0,
            'ineligible' => 0,
            'skipped_unchanged' => 0,
            'detail_failures' => 0,
            'list_failures' => 0,
            'forced_ids' => 0,
        ];
    }

    /**
     * @param array<string,int> $left
     * @param array<string,int> $right
     *
     * @return array<string,int>
     */
    private function mergeFetchStats(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            $left[$key] = (int) ($left[$key] ?? 0) + (int) $value;
        }

        return $left;
    }

    /**
     * @param array<int,string> $sourceBackends
     * @param array<int,string> $targetBackends
     */
    private function resolveBackendDirection(string $backendName, array $sourceBackends, array $targetBackends): string
    {
        $isSource = true === in_array($backendName, $sourceBackends, true);
        $isTarget = true === in_array($backendName, $targetBackends, true);

        if (true === $isSource && true === $isTarget) {
            return 'both';
        }

        if (true === $isSource) {
            return 'source';
        }

        if (true === $isTarget) {
            return 'target';
        }

        return 'passive';
    }

    /**
     * @param array<int,string> $sourceBackends
     * @param array<int,string> $targetBackends
     */
    private function resolveEarlyStopReason(bool $dryRun, array $sourceBackends, array $targetBackends): string
    {
        if (true === $dryRun) {
            return 'dry-run';
        }

        if ([] === $sourceBackends && [] === $targetBackends) {
            return 'no-source-or-target-backends';
        }

        if ([] === $sourceBackends) {
            return 'no-source-backends';
        }

        if ([] === $targetBackends) {
            return 'no-target-backends';
        }

        return 'fetch-only';
    }

    /**
     * @param array<int,array<string,mixed>> $operations
     *
     * @return array{actions:array{create:int,replace:int,delete:int},backends:array<string,array<string,int>>}
     */
    private function summarizeOperations(array $operations): array
    {
        $summary = [
            'actions' => [
                'create' => 0,
                'replace' => 0,
                'delete' => 0,
            ],
            'backends' => [],
        ];

        foreach ($operations as $operation) {
            $action = (string) ($operation['action'] ?? '');
            $backend = (string) ($operation['backend'] ?? '');

            if ('' === $action || '' === $backend) {
                continue;
            }

            $summary['actions'][$action] = (int) ($summary['actions'][$action] ?? 0) + 1;
            $summary['backends'][$backend][$action] = (int) ($summary['backends'][$backend][$action] ?? 0) + 1;
        }

        return $summary;
    }

    /**
     * @param array<string,array{added:int,updated:int,removed:int}> $stats
     *
     * @return array{added:int,updated:int,removed:int}
     */
    private function summarizeSyncStats(array $stats): array
    {
        $summary = [
            'added' => 0,
            'updated' => 0,
            'removed' => 0,
        ];

        foreach ($stats as $backendStats) {
            $summary['added'] += (int) ($backendStats['added'] ?? 0);
            $summary['updated'] += (int) ($backendStats['updated'] ?? 0);
            $summary['removed'] += (int) ($backendStats['removed'] ?? 0);
        }

        return $summary;
    }

    /**
     * @param array<string,array{playlists:int,items:int,added:int,updated:int,removed:int}> $results
     *
     * @return array{playlists:int,items:int,added:int,updated:int,removed:int}
     */
    private function summarizeResultTotals(array $results): array
    {
        $summary = [
            'playlists' => 0,
            'items' => 0,
            'added' => 0,
            'updated' => 0,
            'removed' => 0,
        ];

        foreach ($results as $backendStats) {
            $summary['playlists'] += (int) ($backendStats['playlists'] ?? 0);
            $summary['items'] += (int) ($backendStats['items'] ?? 0);
            $summary['added'] += (int) ($backendStats['added'] ?? 0);
            $summary['updated'] += (int) ($backendStats['updated'] ?? 0);
            $summary['removed'] += (int) ($backendStats['removed'] ?? 0);
        }

        return $summary;
    }

    /**
     * @return array{now:string,peak:string}
     */
    private function getMemoryContext(): array
    {
        return [
            'now' => get_memory_usage(),
            'peak' => get_peak_memory_usage(),
        ];
    }

    private function getPlaylistImportLastSync(UserContext $userContext, string $backendName): ?int
    {
        $value = $userContext->config->get("{$backendName}.import.playlist.lastSync", null);

        return is_numeric($value) ? (int) $value : null;
    }

    private function setPlaylistImportLastSync(UserContext $userContext, string $backendName, int $timestamp): void
    {
        $import = $userContext->config->get("{$backendName}.import", []);
        $import['playlist'] = array_replace($import['playlist'] ?? [], ['lastSync' => $timestamp]);
        $userContext->config->set("{$backendName}.import", $import);
    }

    private function setPlaylistExportLastSync(UserContext $userContext, string $backendName, int $timestamp): void
    {
        $export = $userContext->config->get("{$backendName}.export", []);
        $export['playlist'] = array_replace($export['playlist'] ?? [], ['lastSync' => $timestamp]);
        $userContext->config->set("{$backendName}.export", $export);
    }

    /**
     * @param array<string,mixed> $playlist
     */
    private function resolveRemoteUpdatedAt(array $playlist): int
    {
        $direct = $playlist['remote_updated_at'] ?? null;
        if (is_int($direct)) {
            return $direct;
        }

        if (is_numeric($direct)) {
            return (int) $direct;
        }

        $plexUpdatedAt = ag($playlist, 'raw.detail.updatedAt', ag($playlist, 'raw.updatedAt'));
        if (is_numeric($plexUpdatedAt)) {
            return (int) $plexUpdatedAt;
        }

        $dateLastSaved = ag($playlist, 'raw.detail.DateLastSaved', ag($playlist, 'raw.DateLastSaved'));
        if (is_string($dateLastSaved) && '' !== $dateLastSaved) {
            return make_date($dateLastSaved)->getTimestamp();
        }

        return 0;
    }

    private function logBackendThrowable(
        Throwable $e,
        string $backendName,
        string $user,
        string $operation,
        string $playlistId = '',
        string $playlistTitle = '',
        string $eventName = 'playlist.operation.failed',
        ?string $operationLabel = null,
    ): void {
        $operationLabel ??= str_replace('_', ' ', $operation);
        $message = '' !== trim($playlistId . $playlistTitle)
            ? "Failed to {operation_label} playlist '{playlist_title}' on '{user}@{backend}'."
            : "Failed to {operation_label} for '{user}@{backend}'.";

        $this->logger->error(
            ...lw(
                message: $message,
                context: array_filter(
                    [
                        'event_name' => $eventName,
                        'subsystem' => 'playlist',
                        'operation' => $operation,
                        'operation_label' => $operationLabel,
                        'outcome' => 'failed',
                        'user' => $user,
                        'backend' => $backendName,
                        'playlist_id' => '' !== $playlistId ? $playlistId : null,
                        'playlist_title' => '' !== $playlistTitle ? $playlistTitle : null,
                        ...exception_log($e),
                    ],
                    static fn(mixed $value): bool => null !== $value,
                ),
                e: $e,
            ),
        );
    }
}
