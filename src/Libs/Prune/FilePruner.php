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

        foreach ($directories as $item) {
            $this->pruneDirectory($item, $execute);
        }
    }

    /**
     * @param array{name:string,path:?string,base:?string,filter:?string,time:int|false,validate?:callable,report?:bool} $item
     */
    private function pruneDirectory(array $item, bool $execute): void
    {
        $name = (string) ag($item, 'name');
        $path = ag($item, 'path');
        $filter = ag($item, 'filter');
        $expiresAt = ag($item, 'time');

        if (!is_int($expiresAt)) {
            $this->logger->warning("No expected time to live was found for '{name}' - '{path}'.", [
                'name' => $name,
                'path' => $path,
            ]);
            return;
        }

        if (null === $path || false === is_dir($path)) {
            if (true === (bool) ag($item, 'report', true)) {
                $this->logger->warning("{name}: Path '{path}' not found or is inaccessible.", [
                    'name' => $name,
                    'path' => $path,
                ]);
            }
            return;
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

            if (null !== $filter && false === @preg_match($filter, $fileName)) {
                $this->logger->debug("{name}: File '{file}' did not pass filter checks.", [
                    'name' => $name,
                    'file' => after($entry->getRealPath(), (string) ag($item, 'base') . '/'),
                ]);
                continue;
            }

            if (is_callable($validate) && false === $validate($entry)) {
                $this->logger->debug("{name}: File '{file}' did not pass validation checks.", [
                    'name' => $name,
                    'file' => after($entry->getRealPath(), (string) ag($item, 'base') . '/'),
                ]);
                continue;
            }

            if ($entry->getMTime() > $expiresAt) {
                $this->logger->debug("{name}: File '{file}' Not yet expired. '{ttl}' seconds left.", [
                    'name' => $name,
                    'file' => after($entry->getRealPath(), (string) ag($item, 'base') . '/'),
                    'ttl' => number_format($entry->getMTime() - $expiresAt),
                ]);
                continue;
            }

            $this->logger->notice("{name}: Removing '{file}'. expired TTL.", [
                'name' => $name,
                'file' => after($entry->getRealPath(), (string) ag($item, 'base') . '/'),
            ]);

            if (true === $execute) {
                @unlink($entry->getRealPath());
            }
        }
    }
}
