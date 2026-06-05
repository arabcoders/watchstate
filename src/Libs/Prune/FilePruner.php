<?php

declare(strict_types=1);

namespace App\Libs\Prune;

use App\Libs\Attributes\Cli\Prune;
use App\Libs\Config;
use Psr\Log\LoggerInterface as iLogger;
use SplFileInfo;

#[Prune(name: 'File Pruner', cron: '0 */12 * * *', desc: 'Remove expired generated files.')]
final class FilePruner
{
    public function __construct(
        private readonly iLogger $logger,
    ) {}

    public function __invoke(bool $execute): void
    {
        $time = time();

        $directories = [
            [
                'name' => 'logs_remover',
                'path' => Config::get('tmpDir') . '/logs',
                'base' => Config::get('tmpDir'),
                'filter' => '/\.log$/',
                'time' => strtotime((string) Config::get('logs.prune.after', '-7 DAYS'), $time),
            ],
            [
                'name' => 'webhooks_remover',
                'path' => Config::get('tmpDir') . '/webhooks',
                'base' => Config::get('tmpDir'),
                'filter' => '/\.json$/',
                'time' => strtotime('-3 DAYS', $time),
            ],
            [
                'name' => 'profiler_remover',
                'path' => Config::get('tmpDir') . '/profiler',
                'base' => Config::get('tmpDir'),
                'filter' => '/\.json$/',
                'time' => strtotime('-3 DAYS', $time),
            ],
            [
                'name' => 'debug_remover',
                'path' => Config::get('tmpDir') . '/debug',
                'base' => Config::get('tmpDir'),
                'filter' => '/\.json$/',
                'time' => strtotime('-3 DAYS', $time),
            ],
            [
                'name' => 'backup_remover',
                'path' => Config::get('path') . '/backup',
                'base' => Config::get('path'),
                'filter' => '/\.json$|\.json\.zip$/',
                'validate' => static fn(SplFileInfo $file): bool => 1
                === @preg_match(
                    '/^(\w+\.)?\w+\.\d{8}\.json(\.zip)?$/i',
                    $file->getBasename(),
                ),
                'time' => strtotime('-90 DAYS', $time),
            ],
        ];

        $this->logger->debug('Scanning for expired generated files.', [
            'event_name' => 'prune.file.scan_started',
            'subsystem' => 'prune',
            'operation' => 'prune_expired_files',
            'outcome' => 'started',
            'execute' => $execute,
        ]);

        $totalRemoved = 0;
        $totalFound = 0;

        foreach ($directories as $item) {
            $stats = $this->pruneDirectory($item, $execute);
            $totalFound += $stats['found'];
            $totalRemoved += $stats['removed'];
        }

        if (1 > $totalFound) {
            $this->logger->debug('No expired generated files found.', [
                'event_name' => 'prune.file.skipped',
                'subsystem' => 'prune',
                'operation' => 'prune_expired_files',
                'outcome' => 'skipped',
                'reason' => 'no_expired_files',
                'execute' => $execute,
            ]);
            return;
        }

        $this->logger->info(
            true === $execute
                ? "Pruned '{count}' expired generated files."
                : "Found '{count}' expired generated files.",
            [
                'event_name' => 'prune.file.completed',
                'subsystem' => 'prune',
                'operation' => 'prune_expired_files',
                'outcome' => true === $execute ? 'completed' : 'dry_run',
                'count' => true === $execute ? $totalRemoved : $totalFound,
            ],
        );
    }

    /**
     * @param array{name:string,path:?string,base:?string,filter:?string,time:int|false,validate?:callable,report?:bool} $item
     * @return array{found:int,removed:int}
     */
    private function pruneDirectory(array $item, bool $execute): array
    {
        $name = (string) ag($item, 'name');
        $path = ag($item, 'path');
        $filter = ag($item, 'filter');
        $expiresAt = ag($item, 'time');

        $found = 0;
        $removed = 0;

        if (!is_int($expiresAt)) {
            $this->logger->warning("No expected time to live found for '{name}'.", [
                'event_name' => 'prune.file.ttl_missing',
                'subsystem' => 'prune',
                'operation' => 'prune_expired_files',
                'outcome' => 'failed',
                'reason' => 'missing_ttl',
                'name' => $name,
                'path' => $path,
            ]);
            return ['found' => $found, 'removed' => $removed];
        }

        if (null === $path || false === is_dir($path)) {
            if (true === (bool) ag($item, 'report', true)) {
                $this->logger->warning("Path for '{name}' not found or is inaccessible.", [
                    'event_name' => 'prune.file.path_missing',
                    'subsystem' => 'prune',
                    'operation' => 'prune_expired_files',
                    'outcome' => 'failed',
                    'reason' => 'path_not_found',
                    'name' => $name,
                    'path' => $path,
                ]);
            }
            return ['found' => $found, 'removed' => $removed];
        }

        $validate = ag($item, 'validate');

        foreach (new \DirectoryIterator($path) as $file) {
            if ($file->isDot() || $file->isDir() || false === $file->isFile() || $file->isLink()) {
                continue;
            }

            $realPath = $file->getRealPath();
            if (false === $realPath) {
                continue;
            }

            $entry = new SplFileInfo($realPath);
            $fileName = $entry->getBasename();
            $relativeFile = after($entry->getRealPath(), (string) ag($item, 'base') . '/');

            if (null !== $filter && false === @preg_match($filter, $fileName)) {
                $this->logger->debug("File '{file}' did not pass filter checks.", [
                    'event_name' => 'prune.file.filtered',
                    'subsystem' => 'prune',
                    'operation' => 'prune_expired_files',
                    'outcome' => 'skipped',
                    'reason' => 'filter_mismatch',
                    'name' => $name,
                    'file' => $relativeFile,
                ]);
                continue;
            }

            if (is_callable($validate) && false === $validate($entry)) {
                $this->logger->debug("File '{file}' did not pass validation checks.", [
                    'event_name' => 'prune.file.validation_failed',
                    'subsystem' => 'prune',
                    'operation' => 'prune_expired_files',
                    'outcome' => 'skipped',
                    'reason' => 'validation_failed',
                    'name' => $name,
                    'file' => $relativeFile,
                ]);
                continue;
            }

            if ($entry->getMTime() > $expiresAt) {
                $this->logger->debug("File '{file}' not yet expired.", [
                    'event_name' => 'prune.file.not_expired',
                    'subsystem' => 'prune',
                    'operation' => 'prune_expired_files',
                    'outcome' => 'skipped',
                    'reason' => 'not_expired',
                    'name' => $name,
                    'file' => $relativeFile,
                    'ttl' => number_format($entry->getMTime() - $expiresAt),
                ]);
                continue;
            }

            $found++;

            $this->logger->debug("Removing expired file '{file}'.", [
                'event_name' => 'prune.file.removing',
                'subsystem' => 'prune',
                'operation' => 'prune_expired_files',
                'outcome' => true === $execute ? 'removed' : 'dry_run',
                'name' => $name,
                'file' => $relativeFile,
                'mtime' => $entry->getMTime(),
            ]);

            if (true === $execute) {
                @unlink($entry->getRealPath());
                $removed++;
            }
        }

        if (0 === $found) {
            $this->logger->debug("No expired files found for '{name}'.", [
                'event_name' => 'prune.file.directory_clean',
                'subsystem' => 'prune',
                'operation' => 'prune_expired_files',
                'outcome' => 'skipped',
                'reason' => 'no_expired_files',
                'name' => $name,
                'path' => $path,
            ]);
        }

        return ['found' => $found, 'removed' => $removed];
    }
}
