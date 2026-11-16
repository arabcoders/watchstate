<?php

declare(strict_types=1);

namespace App\Libs\Prune;

use App\Libs\Attributes\Cli\Prune;
use App\Libs\Config;
use DirectoryIterator;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

#[Prune(name: 'Cache Pruner', cron: '0 */12 * * *', desc: 'Remove obsolete filesystem cache namespaces.')]
final class CachePruner
{
    /**
     * @param iLogger $logger The logger instance.
     */
    public function __construct(
        private readonly iLogger $logger,
    ) {}

    /**
     * Remove cache namespaces belonging to other application versions.
     */
    public function __invoke(bool $execute): void
    {
        $prefix = Config::get('cache.prefix');
        $suffix = null !== $prefix && true === is_valid_name($prefix) ? '.' . $prefix : '';
        $this->pruneDirectory((string) Config::get('cache.path'), $suffix, $execute);

        foreach (get_users(false) as $user) {
            $path = Config::get('path') . '/users/' . $user;
            if (true === is_link($path)) {
                continue;
            }

            $suffix = '.' . (is_valid_name($user) ? $user : md5($user));
            $this->pruneDirectory($path . '/cache', $suffix, $execute);
        }
    }

    private function pruneDirectory(string $path, string $suffix, bool $execute): void
    {
        if (true === is_link($path) || false === is_dir($path)) {
            return;
        }

        if (false === is_readable($path)) {
            $this->logger->warning("Cannot read cache directory '{path}'.", [
                'operation' => 'prune.cache',
                'error' => 'directory_unreadable',
                'path' => $path,
            ]);
            return;
        }

        $current = get_app_version() . $suffix;
        $pattern = '/^(?:v?\d+\.\d+\.\d+|(?:dev|master)-\d{8}-[a-f0-9]{7,40}|dev-master)' . preg_quote($suffix, '/') . '$/D';

        foreach (new DirectoryIterator($path) as $item) {
            if ($item->isDot() || $item->isLink() || false === $item->isDir()) {
                continue;
            }

            $name = $item->getBasename();
            if ($current === $name || 1 !== preg_match($pattern, $name)) {
                continue;
            }

            $directory = $item->getPathname();
            $context = ['operation' => 'prune.cache', 'path' => $directory, 'current' => $current];
            if (false === $execute) {
                $this->logger->info("Found obsolete cache directory '{path}'; current namespace is '{current}'.", $context);
                continue;
            }

            try {
                if (true === delete_path($directory) && true === @rmdir($directory)) {
                    $this->logger->info("Removed obsolete cache directory '{path}'; current namespace is '{current}'.", $context);
                    continue;
                }
            } catch (Throwable $e) {
                $context = [...$context, ...exception_log($e)];
            }

            $this->logger->warning("Cannot remove obsolete cache directory '{path}'; current namespace is '{current}'.", [
                ...$context,
                'error' => 'directory_removal_failed',
            ]);
        }
    }
}
