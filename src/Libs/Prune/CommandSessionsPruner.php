<?php

declare(strict_types=1);

namespace App\Libs\Prune;

use App\Libs\Attributes\Cli\Prune;
use App\Libs\Config;
use Psr\Log\LoggerInterface as iLogger;

#[Prune(name: 'Command Sessions', cron: '*/5 * * * *', desc: 'Remove expired command sessions.')]
final class CommandSessionsPruner
{
    private const int COMPLETED_SESSION_RETENTION_SECONDS = 86_400;

    public function __construct(
        private readonly iLogger $logger,
    ) {}

    public function __invoke(bool $execute): void
    {
        $path = fix_path((string) Config::get('tmpDir')) . '/console';
        if (false === is_dir($path)) {
            $this->logger->debug('Console sessions directory not found.', [
                'event_name' => 'prune.session.scan_started',
                'subsystem' => 'prune',
                'operation' => 'prune_expired_sessions',
                'outcome' => 'skipped',
                'reason' => 'directory_not_found',
                'path' => $path,
            ]);
            return;
        }

        $this->logger->debug('Scanning for expired command sessions.', [
            'event_name' => 'prune.session.scan_started',
            'subsystem' => 'prune',
            'operation' => 'prune_expired_sessions',
            'outcome' => 'started',
            'execute' => $execute,
            'path' => $path,
        ]);

        $found = 0;
        $removed = 0;

        foreach (new \DirectoryIterator($path) as $item) {
            if ($item->isDot() || false === $item->isDir()) {
                continue;
            }

            $sessionPath = $item->getRealPath();
            if (false === $sessionPath) {
                continue;
            }

            $sessionName = $item->getBasename();
            $state = $this->readState($sessionPath);

            if (null === $state) {
                $this->logger->debug("Session '{session}' has no readable state, marking for removal.", [
                    'event_name' => 'prune.session.no_state',
                    'subsystem' => 'prune',
                    'operation' => 'prune_expired_sessions',
                    'outcome' => 'found',
                    'reason' => 'no_readable_state',
                    'session' => $sessionName,
                ]);
                $found++;
                if (true === $execute) {
                    $this->cleanupSession($sessionPath);
                    $removed++;
                }
                continue;
            }

            if (false === $this->shouldPrune($state)) {
                continue;
            }

            $found++;
            $reason = $this->resolvePruneReason($state);

            $this->logger->debug("Session '{session}' marked for removal.", [
                'event_name' => 'prune.session.found',
                'subsystem' => 'prune',
                'operation' => 'prune_expired_sessions',
                'outcome' => 'found',
                'reason' => $reason,
                'session' => $sessionName,
                'status' => ag($state, 'status'),
            ]);

            if (false === $execute) {
                continue;
            }

            if (true === $this->cleanupSession($sessionPath)) {
                $removed++;
            }
        }

        if (1 > $found) {
            $this->logger->debug('No expired command sessions found.', [
                'event_name' => 'prune.session.skipped',
                'subsystem' => 'prune',
                'operation' => 'prune_expired_sessions',
                'outcome' => 'skipped',
                'reason' => 'no_expired_sessions',
                'path' => $path,
            ]);
            return;
        }

        $this->logger->info(
            true === $execute
                ? "Pruned '{count}' expired command sessions."
                : "Found '{count}' expired command sessions.",
            [
                'event_name' => 'prune.session.completed',
                'subsystem' => 'prune',
                'operation' => 'prune_expired_sessions',
                'outcome' => true === $execute ? 'completed' : 'dry_run',
                'count' => true === $execute ? $removed : $found,
            ],
        );
    }

    private function shouldPrune(?array $state): bool
    {
        if (null === $state) {
            return true;
        }

        if ('queued' === ag($state, 'status')) {
            $expiresAt = ag($state, 'expires_at');
            if (!is_string($expiresAt) || '' === trim($expiresAt)) {
                return true;
            }

            $expiresAtUnix = strtotime($expiresAt);
            return false === $expiresAtUnix || $expiresAtUnix < time();
        }

        if ('completed' !== ag($state, 'status')) {
            return false;
        }

        if (0 !== (int) ag($state, 'connections', 0)) {
            return false;
        }

        $finishedAt = ag($state, 'finished_at');
        if (!is_string($finishedAt) || '' === trim($finishedAt)) {
            return true;
        }

        $finishedAtUnix = strtotime($finishedAt);
        if (false === $finishedAtUnix) {
            return true;
        }

        return ($finishedAtUnix + self::COMPLETED_SESSION_RETENTION_SECONDS) <= time();
    }

    /**
     * @return string
     */
    private function resolvePruneReason(?array $state): string
    {
        if (null === $state) {
            return 'no_state';
        }

        $status = (string) ag($state, 'status', 'unknown');

        if ('queued' === $status) {
            return 'queued_expired';
        }

        if ('completed' === $status) {
            return 'completed_expired';
        }

        return 'unknown_status';
    }

    private function cleanupSession(string $path): bool
    {
        if (false === is_dir($path)) {
            return false;
        }

        $locks = $this->acquireCleanupLocks($path);
        if ([] === $locks) {
            $this->logger->debug("Could not acquire locks for session directory '{path}'.", [
                'event_name' => 'prune.session.lock_failed',
                'subsystem' => 'prune',
                'operation' => 'prune_expired_sessions',
                'outcome' => 'failed',
                'reason' => 'lock_acquisition_failed',
                'path' => $path,
            ]);
            return false;
        }

        try {
            $this->removeDirectory($path);
        } finally {
            $this->releaseCleanupLocks($locks);
        }

        $removed = false === is_dir($path);

        if (true === $removed) {
            $this->logger->debug("Removed session directory '{path}'.", [
                'event_name' => 'prune.session.removed',
                'subsystem' => 'prune',
                'operation' => 'prune_expired_sessions',
                'outcome' => 'completed',
                'path' => $path,
            ]);
        }

        return $removed;
    }

    /**
     * @return array<int, mixed>
     */
    private function acquireCleanupLocks(string $path): array
    {
        $handles = [];

        foreach (['state.lock', 'writer.lock'] as $file) {
            $handle = @fopen($path . '/' . $file, 'c+');
            if (false === $handle) {
                $this->releaseCleanupLocks($handles);
                return [];
            }

            if (false === flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                $this->releaseCleanupLocks($handles);
                return [];
            }

            $handles[] = $handle;
        }

        return $handles;
    }

    /**
     * @param array<int, mixed> $handles
     */
    private function releaseCleanupLocks(array $handles): void
    {
        foreach ($handles as $handle) {
            if (!is_resource($handle)) {
                continue;
            }

            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (false === is_dir($path)) {
            return;
        }

        foreach (new \DirectoryIterator($path) as $item) {
            if ($item->isDot()) {
                continue;
            }

            $itemPath = $item->getRealPath();
            if (false === $itemPath) {
                continue;
            }

            if ($item->isDir()) {
                $this->removeDirectory($itemPath);
                continue;
            }

            @unlink($itemPath);
        }

        @rmdir($path);
    }

    private function readState(string $sessionPath): ?array
    {
        $statePath = $sessionPath . '/state.json';
        if (false === is_readable($statePath)) {
            return null;
        }

        $state = json_decode((string) file_get_contents($statePath), true);
        return is_array($state) ? $state : null;
    }
}
