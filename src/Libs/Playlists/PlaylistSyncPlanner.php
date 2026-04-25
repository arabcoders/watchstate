<?php

declare(strict_types=1);

namespace App\Libs\Playlists;

final class PlaylistSyncPlanner
{
    /**
     * Collapse a sync group to the current row for each backend.
     *
     * @param array<int,array<string,mixed>> $group
     *
     * @return array<string,array<string,mixed>>
     */
    public function collapseGroup(array $group): array
    {
        $collapsed = [];

        foreach ($group as $playlist) {
            $backend = (string) ($playlist['backend'] ?? '');
            if ('' === $backend) {
                continue;
            }

            if (false === array_key_exists($backend, $collapsed)) {
                $collapsed[$backend] = $playlist;
                continue;
            }

            if ($this->compareCurrentRow($playlist, $collapsed[$backend]) < 0) {
                $collapsed[$backend] = $playlist;
            }
        }

        return $collapsed;
    }

    /**
     * Select the canonical winner for a sync group.
     *
     * @param array<int,array<string,mixed>>|array<string,array<string,mixed>> $group
     *
     * @return array<string,mixed>|null
     */
    public function selectWinner(array $group): ?array
    {
        $collapsed = array_is_list($group) ? $this->collapseGroup($group) : $group;
        $candidates = [];

        foreach ($collapsed as $playlist) {
            if (null !== ($playlist['deleted_at'] ?? null)) {
                $candidates[] = $playlist;
                continue;
            }

            if (true !== $this->isEligible($playlist)) {
                continue;
            }

            $candidates[] = $playlist;
        }

        if ([] === $candidates) {
            return null;
        }

        usort($candidates, $this->compareWinner(...));

        return $candidates[0];
    }

    /**
     * Determine if the target backend should receive the winner playlist.
     *
     * @param array<string,mixed>|null $target
     * @param array<string,mixed> $winner
     */
    public function shouldSync(?array $target, array $winner): bool
    {
        if (null !== ($winner['deleted_at'] ?? null)) {
            return null !== $target && null === ($target['deleted_at'] ?? null) && true === $this->isEligible($target);
        }

        if (null === $target) {
            return true;
        }

        if (null !== ($target['deleted_at'] ?? null)) {
            return true;
        }

        if (true !== $this->isEligible($target)) {
            return false;
        }

        return (string) ($target['content_hash'] ?? '') !== (string) ($winner['content_hash'] ?? '');
    }

    /**
     * @param array<string,mixed> $playlist
     */
    public function isEligible(array $playlist): bool
    {
        return true === (bool) ag($playlist, 'metadata.sync.eligible', true);
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function compareCurrentRow(array $left, array $right): int
    {
        $leftDeleted = null !== ($left['deleted_at'] ?? null);
        $rightDeleted = null !== ($right['deleted_at'] ?? null);

        if ($leftDeleted !== $rightDeleted) {
            return true === $leftDeleted ? 1 : -1;
        }

        $leftUpdated = (int) ($left['remote_updated_at'] ?? 0);
        $rightUpdated = (int) ($right['remote_updated_at'] ?? 0);

        if ($leftUpdated !== $rightUpdated) {
            return $rightUpdated <=> $leftUpdated;
        }

        return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function compareWinner(array $left, array $right): int
    {
        $leftUpdated = (int) ($left['remote_updated_at'] ?? 0);
        $rightUpdated = (int) ($right['remote_updated_at'] ?? 0);

        if ($leftUpdated !== $rightUpdated) {
            return $rightUpdated <=> $leftUpdated;
        }

        $leftDeleted = null !== ($left['deleted_at'] ?? null);
        $rightDeleted = null !== ($right['deleted_at'] ?? null);

        if ($leftDeleted !== $rightDeleted) {
            return true === $leftDeleted ? -1 : 1;
        }

        $leftHash = (string) ($left['content_hash'] ?? '');
        $rightHash = (string) ($right['content_hash'] ?? '');

        if ($leftHash !== $rightHash) {
            return strcmp($leftHash, $rightHash);
        }

        return strcmp((string) ($left['backend'] ?? ''), (string) ($right['backend'] ?? ''));
    }
}
