<?php

declare(strict_types=1);

namespace App\Libs\Playlists;

use App\Libs\Database\DBLayer;
use PDO;
use Psr\Log\LoggerInterface as iLogger;

final class PlaylistStore
{
    public function __construct(
        private readonly DBLayer $db,
        private readonly ?iLogger $logger = null,
    ) {}

    /**
     * Replace all stored playlists for the given backend.
     *
     * @param string $backend
     * @param array<int,array<string,mixed>> $playlists
     * @param array<int,string>|null $seenBackendIds
     *
     * @return array{playlists:int,items:int,added:int,updated:int,removed:int}
     */
    public function replaceBackendPlaylists(string $backend, array $playlists, ?array $seenBackendIds = null): array
    {
        $stats = [
            'playlists' => count($playlists),
            'items' => 0,
            'added' => 0,
            'updated' => 0,
            'removed' => 0,
        ];

        $seen = [];

        foreach ($seenBackendIds ?? [] as $backendId) {
            $seen[(string) $backendId] = true;
        }

        $this->db->transactional(function () use ($backend, $playlists, &$stats, &$seen) {
            foreach ($playlists as $playlist) {
                $playlistId = $this->upsertPlaylist($backend, $playlist, $stats);
                $seen[(string) $playlist['id']] = true;

                $this->replacePlaylistItems($playlistId, $playlist['items'] ?? [], $stats);

                $this->logger?->info("Stored playlist snapshot '{playlist_title}' from '{backend}' with {item_count} items.", [
                    'event_name' => 'playlist.snapshot.stored',
                    'subsystem' => 'playlist',
                    'operation' => 'store_snapshot',
                    'outcome' => 'completed',
                    'backend' => $backend,
                    'playlist_id' => (string) ($playlist['id'] ?? ''),
                    'playlist_title' => (string) ($playlist['title'] ?? 'Untitled playlist'),
                    'item_count' => count($playlist['items'] ?? []),
                    'hash' => $this->makeContentHash($playlist),
                    'changed' => true,
                ]);
            }

            foreach ($this->getExistingBackendMap($backend) as $backendId => $playlistId) {
                if (true === isset($seen[$backendId])) {
                    continue;
                }

                $this->markDeleted((int) $playlistId);
                $stats['removed']++;
            }
        });

        return $stats;
    }

    /**
     * Return stored playlists for the given backend.
     *
     * @param string $backend
     *
     * @return array<int,array<string,mixed>>
     */
    public function getByBackend(string $backend): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM playlists WHERE backend = :backend AND deleted_at IS NULL ORDER BY title ASC, id ASC',
            ['backend' => $backend],
        );

        $rows = [];

        while (false !== ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            $row['id'] = (int) $row['id'];
            $row['is_editable'] = 1 === (int) $row['is_editable'];
            $row['is_smart'] = 1 === (int) $row['is_smart'];
            $row['is_public'] = 1 === (int) $row['is_public'];
            $row['item_count'] = (int) $row['item_count'];
            $row['remote_updated_at'] = (int) ($row['remote_updated_at'] ?? 0);
            $row['deleted_at'] = null !== ($row['deleted_at'] ?? null) ? (int) $row['deleted_at'] : null;
            $row['metadata'] = $this->decodeJson($row['metadata'] ?? '{}');
            $row['items'] = $this->getItems((int) $row['id']);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Return all stored playlists, including tombstones.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM playlists ORDER BY backend ASC, title ASC, id ASC');

        $rows = [];

        while (false !== ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            $row['id'] = (int) $row['id'];
            $row['is_editable'] = 1 === (int) $row['is_editable'];
            $row['is_smart'] = 1 === (int) $row['is_smart'];
            $row['is_public'] = 1 === (int) $row['is_public'];
            $row['item_count'] = (int) $row['item_count'];
            $row['remote_updated_at'] = (int) ($row['remote_updated_at'] ?? 0);
            $row['deleted_at'] = null !== ($row['deleted_at'] ?? null) ? (int) $row['deleted_at'] : null;
            $row['metadata'] = $this->decodeJson($row['metadata'] ?? '{}');
            $row['items'] = $this->getItems((int) $row['id']);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Return playlists grouped by sync id, title fallback when no sync id is available.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function getSyncGroups(): array
    {
        $groups = [];

        foreach ($this->getAll() as $playlist) {
            $groupId = $this->getPlaylistSyncId($playlist);
            $groups[$groupId] ??= [];
            $groups[$groupId][] = $playlist;
        }

        return $groups;
    }

    /**
     * Upsert a single backend playlist snapshot.
     *
     * @param string $backend
     * @param array<string,mixed> $playlist
     *
     * @return array<string,mixed>
     */
    public function upsertBackendPlaylist(string $backend, array $playlist): array
    {
        $stats = [
            'playlists' => 1,
            'items' => 0,
            'added' => 0,
            'updated' => 0,
            'removed' => 0,
        ];

        $playlistId = 0;

        $this->db->transactional(function () use ($backend, $playlist, &$stats, &$playlistId) {
            $playlistId = $this->upsertPlaylist($backend, $playlist, $stats);
            $this->replacePlaylistItems($playlistId, $playlist['items'] ?? [], $stats);
        });

        return $this->getById($playlistId) ?? [];
    }

    /**
     * Soft delete playlist snapshot to keep delete reconciliation state.
     *
     * @param int $playlistId
     *
     * @return void
     */
    public function markDeleted(int $playlistId): void
    {
        $now = time();
        $existing = $this->getById($playlistId);
        $remoteUpdatedAt = (int) ($existing['remote_updated_at'] ?? 0);

        $this->db->transactional(function () use ($playlistId, $now, $remoteUpdatedAt) {
            $this->db->delete('playlist_items', ['playlist_id' => $playlistId]);
            $this->db->update(
                'playlists',
                [
                    'item_count' => 0,
                    'content_hash' => '',
                    'remote_updated_at' => $remoteUpdatedAt,
                    'deleted_at' => $now,
                    'updated_at' => $now,
                    'synced_at' => $now,
                ],
                ['id' => $playlistId],
            );
        });
    }

    /**
     * Soft delete all playlist snapshots for the given sync id except the preserved row ids.
     *
     * @param string $syncId
     * @param array<int,int> $preserveIds
     *
     * @return int
     */
    public function markSyncGroupDeleted(string $syncId, array $preserveIds = []): int
    {
        $rows = $this->getSyncGroupRows($syncId);
        $removed = 0;

        foreach ($rows as $row) {
            if (true === in_array((int) $row['id'], $preserveIds, true)) {
                continue;
            }

            if (null !== ($row['deleted_at'] ?? null)) {
                continue;
            }

            $this->markDeleted((int) $row['id']);
            $removed++;
        }

        return $removed;
    }

    /**
     * Update a playlist sync id in place.
     *
     * @param int $playlistId
     * @param string $syncId
     *
     * @return void
     */
    public function updateSyncId(int $playlistId, string $syncId): void
    {
        $this->db->update(
            'playlists',
            [
                'sync_id' => $syncId,
                'updated_at' => time(),
            ],
            ['id' => $playlistId],
        );
    }

    /**
     * Update playlist metadata in place.
     *
     * @param int $playlistId
     * @param array<string,mixed> $metadata
     *
     * @return void
     */
    public function updateMetadata(int $playlistId, array $metadata): void
    {
        $this->db->update(
            'playlists',
            [
                'metadata' => $this->encodeJson($metadata),
                'updated_at' => time(),
            ],
            ['id' => $playlistId],
        );
    }

    /**
     * Return one playlist snapshot by backend item id.
     *
     * @param string $backend
     * @param string $backendId
     *
     * @return array<string,mixed>|null
     */
    public function getByBackendId(string $backend, string $backendId): ?array
    {
        $stmt = $this->db->query(
            'SELECT * FROM playlists WHERE backend = :backend AND backend_id = :backend_id LIMIT 1',
            [
                'backend' => $backend,
                'backend_id' => $backendId,
            ],
        );

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (false === $row) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['is_editable'] = 1 === (int) $row['is_editable'];
        $row['is_smart'] = 1 === (int) $row['is_smart'];
        $row['is_public'] = 1 === (int) $row['is_public'];
        $row['item_count'] = (int) $row['item_count'];
        $row['remote_updated_at'] = (int) ($row['remote_updated_at'] ?? 0);
        $row['deleted_at'] = null !== ($row['deleted_at'] ?? null) ? (int) $row['deleted_at'] : null;
        $row['metadata'] = $this->decodeJson($row['metadata'] ?? '{}');
        $row['items'] = $this->getItems((int) $row['id']);

        return $row;
    }

    /**
     * Return one playlist by row id.
     *
     * @param int $id
     *
     * @return array<string,mixed>|null
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->query('SELECT * FROM playlists WHERE id = :id LIMIT 1', ['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (false === $row) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['is_editable'] = 1 === (int) $row['is_editable'];
        $row['is_smart'] = 1 === (int) $row['is_smart'];
        $row['is_public'] = 1 === (int) $row['is_public'];
        $row['item_count'] = (int) $row['item_count'];
        $row['remote_updated_at'] = (int) ($row['remote_updated_at'] ?? 0);
        $row['deleted_at'] = null !== ($row['deleted_at'] ?? null) ? (int) $row['deleted_at'] : null;
        $row['metadata'] = $this->decodeJson($row['metadata'] ?? '{}');
        $row['items'] = $this->getItems((int) $row['id']);

        return $row;
    }

    /**
     * @return array<string,int>
     */
    private function getExistingBackendMap(string $backend): array
    {
        $stmt = $this->db->query(
            'SELECT id, backend_id FROM playlists WHERE backend = :backend AND deleted_at IS NULL',
            ['backend' => $backend],
        );

        $rows = [];

        while (false !== ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            $rows[(string) $row['backend_id']] = (int) $row['id'];
        }

        return $rows;
    }

    /**
     * @param string $backend
     * @param array<string,mixed> $playlist
     * @param array{playlists:int,items:int,added:int,updated:int,removed:int} $stats
     */
    private function upsertPlaylist(string $backend, array $playlist, array &$stats): int
    {
        $resolvedSyncId = $this->resolveSyncId($playlist);

        $stmt = $this->db->query(
            'SELECT id, created_at, sync_id FROM playlists WHERE backend = :backend AND backend_id = :backend_id LIMIT 1',
            [
                'backend' => $backend,
                'backend_id' => (string) $playlist['id'],
            ],
        );

        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (false === $existing) {
            $existing = null;
        }

        if (null === $existing && '' !== $resolvedSyncId) {
            $stmt = $this->db->query(
                'SELECT id, created_at, sync_id FROM playlists WHERE backend = :backend AND sync_id = :sync_id ORDER BY updated_at DESC, id DESC LIMIT 1',
                [
                    'backend' => $backend,
                    'sync_id' => $resolvedSyncId,
                ],
            );

            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (false === $existing) {
                $existing = null;
            }
        }

        $now = time();

        $payload = [
            'backend' => $backend,
            'backend_id' => (string) $playlist['id'],
            'sync_id' => '' !== trim((string) ($existing['sync_id'] ?? ''))
                ? trim((string) $existing['sync_id'])
                : $resolvedSyncId,
            'title' => (string) ($playlist['title'] ?? 'Untitled playlist'),
            'type' => (string) ($playlist['type'] ?? 'video'),
            'summary' => $playlist['summary'] ?? null,
            'is_editable' => true === (bool) ($playlist['editable'] ?? true) ? 1 : 0,
            'is_smart' => true === (bool) ($playlist['smart'] ?? false) ? 1 : 0,
            'is_public' => true === (bool) ($playlist['public'] ?? false) ? 1 : 0,
            'item_count' => (int) count($playlist['items'] ?? []),
            'content_hash' => $this->makeContentHash($playlist),
            'remote_updated_at' => $this->resolveRemoteUpdatedAt($playlist),
            'metadata' => $this->encodeJson($playlist['metadata'] ?? []),
            'updated_at' => $now,
            'synced_at' => $now,
            'deleted_at' => null,
        ];

        if (null !== $existing) {
            $this->db->update(
                'playlists',
                $payload,
                ['id' => (int) $existing['id']],
            );

            $stats['updated']++;

            return (int) $existing['id'];
        }

        $payload['created_at'] = $now;
        $this->db->insert('playlists', $payload);
        $stats['added']++;

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param int $playlistId
     * @param array<int,array<string,mixed>> $items
     * @param array{playlists:int,items:int,added:int,updated:int,removed:int} $stats
     */
    private function replacePlaylistItems(int $playlistId, array $items, array &$stats): void
    {
        $this->db->delete('playlist_items', ['playlist_id' => $playlistId]);

        $now = time();

        foreach ($items as $item) {
            $this->db->insert('playlist_items', [
                'playlist_id' => $playlistId,
                'position' => (int) ($item['position'] ?? 0),
                'state_id' => $item['state_id'] ?? null,
                'backend_item_id' => $item['backend_item_id'] ?? null,
                'backend_entry_id' => $item['backend_entry_id'] ?? null,
                'item_type' => $item['item_type'] ?? null,
                'title' => (string) ($item['title'] ?? 'Unknown item'),
                'metadata' => $this->encodeJson($item['metadata'] ?? []),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $stats['items']++;
        }
    }

    private function deletePlaylist(int $playlistId): void
    {
        $this->db->delete('playlist_items', ['playlist_id' => $playlistId]);
        $this->db->delete('playlists', ['id' => $playlistId]);
    }

    /**
     * @param string $syncId
     *
     * @return array<int,array<string,mixed>>
     */
    private function getSyncGroupRows(string $syncId): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM playlists WHERE sync_id = :sync_id OR (sync_id IS NULL AND LOWER(title) = :title_key)',
            [
                'sync_id' => $syncId,
                'title_key' => str_starts_with($syncId, 'title:') ? substr($syncId, 6) : '',
            ],
        );

        $rows = [];

        while (false !== ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            $row['id'] = (int) $row['id'];
            $row['deleted_at'] = null !== ($row['deleted_at'] ?? null) ? (int) $row['deleted_at'] : null;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getItems(int $playlistId): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM playlist_items WHERE playlist_id = :playlist_id ORDER BY position ASC, id ASC',
            ['playlist_id' => $playlistId],
        );

        $rows = [];

        while (false !== ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            $row['id'] = (int) $row['id'];
            $row['playlist_id'] = (int) $row['playlist_id'];
            $row['position'] = (int) $row['position'];
            $row['state_id'] = null !== $row['state_id'] ? (int) $row['state_id'] : null;
            $row['metadata'] = $this->decodeJson($row['metadata'] ?? '{}');
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $playlist
     */
    private function makeContentHash(array $playlist): string
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

        return hash('sha256', $this->encodeJson([
            'title' => (string) ($playlist['title'] ?? ''),
            'type' => strtolower((string) ($playlist['type'] ?? 'video')),
            'items' => $itemKeys,
        ]));
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

        $raw = ag($playlist, 'metadata.raw.detail.updatedAt');
        if (is_numeric($raw)) {
            return (int) $raw;
        }

        $dateLastSaved = ag($playlist, 'metadata.raw.detail.DateLastSaved');
        if (is_string($dateLastSaved) && '' !== $dateLastSaved) {
            return make_date($dateLastSaved)->getTimestamp();
        }

        $listDateLastSaved = ag($playlist, 'metadata.raw.DateLastSaved');
        if (is_string($listDateLastSaved) && '' !== $listDateLastSaved) {
            return make_date($listDateLastSaved)->getTimestamp();
        }

        return 0;
    }

    /**
     * @param string $backend
     * @param array<string,mixed> $playlist
     */
    private function resolveSyncId(array $playlist): string
    {
        $existing = (string) ag($playlist, 'sync_id', '');
        if ('' !== trim($existing)) {
            return trim($existing);
        }

        $metadataSync = (string) ag($playlist, 'metadata.sync_id', '');
        if ('' !== trim($metadataSync)) {
            return trim($metadataSync);
        }

        return 'title:' . strtolower(trim((string) ag($playlist, 'title', '')));
    }

    /**
     * @param array<string,mixed> $playlist
     */
    private function getPlaylistSyncId(array $playlist): string
    {
        $syncId = trim((string) ($playlist['sync_id'] ?? ''));

        if ('' !== $syncId) {
            return $syncId;
        }

        return 'title:' . strtolower(trim((string) ($playlist['title'] ?? '')));
    }

    private function encodeJson(array $payload): string
    {
        $json = json_encode($payload, flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);

        return false !== $json ? $json : '{}';
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $payload): array
    {
        $decoded = json_decode($payload, true);

        return true === is_array($decoded) ? $decoded : [];
    }
}
