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
        $store = new PlaylistStore($userContext->db->getDBLayer());
        $planner = new PlaylistSyncPlanner();
        $dryRun = true === (bool) ($opts[Options::DRY_RUN] ?? false);
        $forceFull = true === (bool) ($opts[Options::FORCE_FULL] ?? false);

        $results = array_fill_keys(array_keys($clients), $this->makeSummary([]));
        $sourceBackends = array_values(array_unique(array_map('strval', $opts['source_backends'] ?? [])));
        $targetBackends = array_values(array_unique(array_map('strval', $opts['target_backends'] ?? [])));
        $syncStats = [];
        $fetchedBackends = [];

        foreach ($clients as $backendName => $client) {
            $snapshot = $this->fetchBackendPlaylists(
                client: $client,
                userContext: $userContext,
                store: $store,
                opts: [
                    Options::FORCE_FULL => true === $dryRun || true === $forceFull,
                    'last_sync' => $this->getPlaylistImportLastSync($userContext, $backendName),
                ],
            );
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

        if (true === $dryRun || [] === $targetBackends || [] === $sourceBackends) {
            return $this->summarizeBackends($clients, $store, $results, $syncStats, $dryRun);
        }

        $operations = $this->planSyncOperations(
            userContext: $userContext,
            store: $store,
            planner: $planner,
            sourceBackends: $sourceBackends,
            targetBackends: $targetBackends,
        );

        $applied = $this->applySyncOperations($clients, $store, $operations);

        foreach ($applied['stats'] as $backendName => $stats) {
            $syncStats[$backendName] = array_replace($syncStats[$backendName] ?? [], $stats);
        }

        foreach ($targetBackends as $backendName) {
            if (true === isset($applied['failed_backends'][$backendName])) {
                continue;
            }

            $this->setPlaylistExportLastSync($userContext, $backendName, time());
        }

        foreach ($applied['touched_backends'] as $backendName) {
            $refresh = $this->fetchBackendPlaylists(
                client: $clients[$backendName],
                userContext: $userContext,
                store: $store,
                opts: [
                    Options::FORCE_FULL => true,
                    'force_ids' => array_keys($applied['refresh_sync_ids'][$backendName] ?? []),
                    'sync_id_overrides' => $applied['refresh_sync_ids'][$backendName] ?? [],
                ],
            );

            if (false === $refresh['ok']) {
                continue;
            }

            $store->replaceBackendPlaylists($backendName, $refresh['playlists'], $refresh['seen_backend_ids']);
            $this->setPlaylistImportLastSync($userContext, $backendName, time());
        }

        return $this->summarizeBackends($clients, $store, $results, $syncStats, false);
    }

    /**
     * @param array{
     *   FORCE_FULL?:bool,
     *   force_ids?:array<int,string>,
     *   last_sync?:int|null,
     *   sync_id_overrides?:array<string,string>
     * } $opts
     *
     * @return array{ok:bool,playlists:array<int,array<string,mixed>>,seen_backend_ids:array<int,string>}
     */
    private function fetchBackendPlaylists(
        iClient $client,
        UserContext $userContext,
        PlaylistStore $store,
        array $opts = [],
    ): array {
        try {
            $playlistSummaries = $client->getPlaylistsList([Options::RAW_RESPONSE => true]);
        } catch (Throwable $e) {
            $this->logBackendThrowable($e, $client->getContext()->backendName, 'get playlists list');

            return [
                'ok' => false,
                'playlists' => [],
                'seen_backend_ids' => [],
            ];
        }

        $playlists = [];
        $seenBackendIds = [];
        $forceFull = true === (bool) ($opts[Options::FORCE_FULL] ?? false);
        $lastSync = is_numeric($opts['last_sync'] ?? null) ? (int) $opts['last_sync'] : null;
        $syncIdOverrides = $opts['sync_id_overrides'] ?? [];
        $forceIds = [];

        foreach ($opts['force_ids'] ?? [] as $playlistId) {
            $forceIds[(string) $playlistId] = true;
        }

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
                continue;
            }

            try {
                $playlist = $client->getPlaylist($playlistId, [Options::RAW_RESPONSE => true]);
            } catch (Throwable $e) {
                $this->logBackendThrowable($e, $client->getContext()->backendName, 'get playlist');
                continue;
            }

            $snapshot = $this->buildPlaylistSnapshot(
                client: $client,
                userContext: $userContext,
                playlist: $playlist,
                syncId: $syncIdOverrides[$playlistId] ?? null,
            );

            if (null !== $snapshot) {
                $playlists[] = $snapshot;
            }
        }

        return [
            'ok' => true,
            'playlists' => $playlists,
            'seen_backend_ids' => array_values(array_unique($seenBackendIds)),
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
                    "PLAYLIST: Skipping '{user}@{backend}' playlist '{title}'. Some items did not resolve to local state.",
                    [
                        'user' => $userContext->name,
                        'backend' => $client->getContext()->backendName,
                        'title' => ag($playlist, 'title', 'unknown'),
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
                "PLAYLIST: Failed to map '{backend}' playlist item '{title}'. {error}",
                [
                    'backend' => $client->getName(),
                    'title' => ag($item, ['title', 'Name', 'OriginalTitle'], 'unknown'),
                    'error' => $e->getMessage(),
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
        $operations = [];

        if ([] === $targetBackends) {
            return $operations;
        }

        foreach ($store->getSyncGroups() as $syncId => $group) {
            $collapsed = $planner->collapseGroup($group);
            $sourceCandidates = array_filter(
                $collapsed,
                static fn(array $playlist): bool => true === in_array((string) ($playlist['backend'] ?? ''), $sourceBackends, true),
            );

            if ([] === $sourceCandidates) {
                continue;
            }

            $winner = $planner->selectWinner($sourceCandidates);

            if (null === $winner) {
                continue;
            }

            foreach ($targetBackends as $backendName) {
                if ($backendName === (string) ($winner['backend'] ?? '')) {
                    continue;
                }

                $target = $collapsed[$backendName] ?? null;
                if (false === $planner->shouldSync($target, $winner)) {
                    continue;
                }

                if (null !== ($winner['deleted_at'] ?? null)) {
                    $operations[] = [
                        'action' => 'delete',
                        'backend' => $backendName,
                        'sync_id' => $syncId,
                        'winner' => $winner,
                        'target' => $target,
                    ];
                    continue;
                }

                $items = $this->resolveTargetItemsForBackend($userContext, $backendName, $winner);
                if (null === $items) {
                    continue;
                }

                $operations[] = [
                    'action' => null === $target || null !== ($target['deleted_at'] ?? null) ? 'create' : 'replace',
                    'backend' => $backendName,
                    'sync_id' => $syncId,
                    'winner' => $winner,
                    'target' => $target,
                    'items' => $items,
                ];
            }
        }

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
                    $this->logBackendThrowable($e, $backendName, 'delete playlist');
                    $failedBackends[$backendName] = true;
                    continue;
                }

                $store->markDeleted((int) $target['id']);
                $touchedBackends[$backendName] = true;
                $stats[$backendName]['removed'] = ($stats[$backendName]['removed'] ?? 0) + 1;
                continue;
            }

            if ('replace' === $operation['action'] && null !== $target) {
                try {
                    $client->deletePlaylist((string) ($target['backend_id'] ?? ''));
                } catch (Throwable $e) {
                    $this->logBackendThrowable($e, $backendName, 'delete playlist before replace');
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
                $this->logBackendThrowable($e, $backendName, 'create playlist');
                $failedBackends[$backendName] = true;
                continue;
            }

            $touchedBackends[$backendName] = true;
            $createdId = trim((string) ag($createResult, 'id', ''));
            if ('' === $createdId) {
                $this->logger->warning(
                    "PLAYLIST: Backend '{backend}' create request completed without a playlist id.",
                    ['backend' => $backendName],
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
            ));
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
     *
     * @return array<string,mixed>
     */
    private function makeOptimisticPlaylistSnapshot(
        string $backend,
        string $backendId,
        string $syncId,
        array $winner,
        array $items,
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
                'sync' => [
                    'eligible' => true,
                    'reason' => null,
                ],
            ],
            'items' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $winner
     *
     * @return array<int,array<string,mixed>>|null
     */
    private function resolveTargetItemsForBackend(UserContext $userContext, string $backend, array $winner): ?array
    {
        $items = [];

        foreach (array_values($winner['items'] ?? []) as $position => $item) {
            $stateId = $item['state_id'] ?? null;
            if (null === $stateId) {
                return null;
            }

            $entity = $this->getLocalStateById($userContext, (int) $stateId);
            if (null === $entity) {
                $this->logger->info(
                    "PLAYLIST: Skipping '{backend}' sync for '{title}'. Local state '{state_id}' no longer exists.",
                    [
                        'backend' => $backend,
                        'title' => ag($winner, 'title', 'unknown'),
                        'state_id' => $stateId,
                    ],
                );
                return null;
            }

            $backendId = trim((string) ag($entity->getMetadata($backend), iState::COLUMN_ID, ''));
            if ('' === $backendId) {
                $this->logger->info(
                    "PLAYLIST: Skipping '{backend}' sync for '{title}'. Item '{item}' is not available on target backend.",
                    [
                        'backend' => $backend,
                        'title' => ag($winner, 'title', 'unknown'),
                        'item' => $entity->getName(),
                    ],
                );
                return null;
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

        return $items;
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

    private function logBackendThrowable(Throwable $e, string $backendName, string $action): void
    {
        $this->logger->error(
            "PLAYLIST: Failed to {action} for '{backend}'. '{error.message}' at '{error.file}:{error.line}'.",
            [
                'action' => $action,
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
