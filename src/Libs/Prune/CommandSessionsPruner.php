<?php

declare(strict_types=1);

namespace App\Libs\Prune;

use App\Libs\Attributes\Cli\Prune;
use App\Libs\Config;

#[Prune(name: 'Command Sessions', cron: '*/5 * * * *', desc: 'Remove expired command sessions.')]
final class CommandSessionsPruner
{
    private const int COMPLETED_SESSION_RETENTION_SECONDS = 86_400;

    public function __invoke(bool $execute): void
    {
        $path = fix_path((string) Config::get('tmpDir')) . '/console';
        if (false === is_dir($path)) {
            return;
        }

        foreach (new \DirectoryIterator($path) as $item) {
            if ($item->isDot() || false === $item->isDir()) {
                continue;
            }

            $sessionPath = $item->getRealPath();
            if (false === $sessionPath) {
                continue;
            }

            $state = $this->readState($sessionPath);
            if (false === $this->shouldPrune($state)) {
                continue;
            }

            if (false === $execute) {
                continue;
            }

            $this->cleanupSession($sessionPath);
        }
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

    private function cleanupSession(string $path): bool
    {
        if (false === is_dir($path)) {
            return false;
        }

        $locks = $this->acquireCleanupLocks($path);
        if ([] === $locks) {
            return false;
        }

        try {
            $this->removeDirectory($path);
        } finally {
            $this->releaseCleanupLocks($locks);
        }

        return false === is_dir($path);
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
