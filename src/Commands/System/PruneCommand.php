<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Database\DBLayer;
use App\Libs\Extends\Date;
use App\Model\Events\EventsTable;
use DirectoryIterator;
use Psr\Log\LoggerInterface as iLogger;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class PruneCommand
 *
 * This command removes automatically generated files like logs and backups.
 * It provides an option to run in dry-run mode to see what files will be removed without actually removing them.
 */
#[Cli(command: self::ROUTE)]
final class PruneCommand extends Command
{
    public const string ROUTE = 'system:prune';

    public const string TASK_NAME = 'prune';

    /**
     * Class Constructor.
     *
     * @param iLogger $logger The logger implementation used for logging.
     */
    public function __construct(
        private readonly iLogger $logger,
        private readonly DBLayer $db,
    ) {
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not perform any actions on files.')
            ->setDescription('Remove automatically generated files.')
            ->setHelp(
                r(
                    <<<HELP

                        This command remove automatically generated files. like logs and backups.

                        to see what files will be removed without actually removing them. run the following command.

                        {cmd} <cmd>{route}</cmd> <flag>--dry-run</flag> <flag>-vvv</flag>

                        HELP,
                    [
                        'cmd' => trim(command_context()),
                        'route' => self::ROUTE,
                    ],
                ),
            );
    }

    /**
     * Executes the command.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int The exit status code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $time = time();

        $directories = [
            [
                'name' => 'logs_remover',
                'path' => Config::get('tmpDir') . '/logs',
                'base' => Config::get('tmpDir'),
                'filter' => '/\.log$/',
                'time' => strtotime('-7 DAYS', $time),
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
                'filter' => '/\.json$|\.json.zip$/',
                'validate' => static fn(SplFileInfo $f): bool => 1
                === @preg_match(
                    '/^(\w+\.)?\w+\.\d{8}\.json(\.zip)?$/i',
                    $f->getBasename(),
                ),
                'time' => strtotime('-90 DAYS', $time),
            ],
        ];

        $inDryRunMode = $input->getOption('dry-run');

        foreach ($directories as $item) {
            $name = ag($item, 'name');
            $path = ag($item, 'path');
            $filter = ag($item, 'filter');

            if (null === ($expiresAt = ag($item, 'time'))) {
                $this->logger->warning("No expected time to live was found for '{name}' - '{path}'.", [
                    'name' => $name,
                    'path' => $path,
                ]);
                continue;
            }

            if (null === $path || !is_dir($path)) {
                if (true === (bool) ag($item, 'report', true)) {
                    $this->logger->warning("{name}: Path '{path}' not found or is inaccessible.", [
                        'name' => $name,
                        'path' => $path,
                    ]);
                }
                continue;
            }

            $validate = ag($item, 'validate', null);

            foreach (new DirectoryIterator($path) as $file) {
                if ($file->isDot() || $file->isDir() || false === $file->isFile() || $file->isLink()) {
                    continue;
                }

                $file = new SplFileInfo($file->getRealPath());

                $fileName = $file->getBasename();

                if (null !== $filter && false === @preg_match($filter, $fileName)) {
                    $this->logger->debug("{name}: File '{file}' did not pass filter checks.", [
                        'name' => $name,
                        'file' => after($file->getRealPath(), ag($item, 'base') . '/'),
                    ]);
                    continue;
                }

                if (null !== $validate && false === $validate($file)) {
                    $this->logger->debug("{name}: File '{file}' did not pass validation checks.", [
                        'name' => $name,
                        'file' => after($file->getRealPath(), ag($item, 'base') . '/'),
                    ]);
                    continue;
                }

                if ($file->getMTime() > $expiresAt) {
                    $this->logger->debug("{name}: File '{file}' Not yet expired. '{ttl}' seconds left.", [
                        'name' => $name,
                        'file' => after($file->getRealPath(), ag($item, 'base') . '/'),
                        'ttl' => number_format($file->getMTime() - $expiresAt),
                    ]);
                    continue;
                }

                $this->logger->notice("{name}: Removing '{file}'. expired TTL.", [
                    'name' => $name,
                    'file' => after($file->getRealPath(), ag($item, 'base') . '/'),
                ]);

                if (false === $inDryRunMode) {
                    unlink($file->getRealPath());
                }
            }
        }

        $this->cleanUp((bool) $inDryRunMode);
        return self::SUCCESS;
    }

    private function cleanUp(bool $inDryRunMode): void
    {
        $before = make_date(strtotime('-7 DAYS'));

        $sql = 'DELETE FROM
                ' . EventsTable::TABLE_NAME . '
                WHERE
                ' . EventsTable::COLUMN_CREATED_AT . ' < datetime(:before)
        ';
        $stmt = $this->db->query($sql, ['before' => $before->format('Y-m-d')]);

        $count = $stmt->rowCount();
        if ($count > 1) {
            $this->logger->info("Pruned '{count}' events.", ['count' => $count]);
        }

        $playlistBefore = strtotime('-90 DAYS');
        $playlistStmt = $this->db->query(
            'DELETE FROM playlists WHERE deleted_at IS NOT NULL AND deleted_at < :before',
            ['before' => $playlistBefore],
        );

        $playlistCount = $playlistStmt->rowCount();
        if ($playlistCount > 0) {
            $this->logger->info("Pruned '{count}' deleted playlist snapshots.", ['count' => $playlistCount]);
        }

        $this->pruneConsoleSessions($inDryRunMode);
    }

    private function pruneConsoleSessions(bool $inDryRunMode): void
    {
        $path = fix_path(Config::get('tmpDir')) . '/console';
        if (!is_dir($path)) {
            return;
        }

        $before = time() - 86_400;

        foreach (new DirectoryIterator($path) as $item) {
            if ($item->isDot() || false === $item->isDir()) {
                continue;
            }

            $sessionPath = $item->getRealPath();
            if (false === $sessionPath) {
                continue;
            }

            $statePath = $sessionPath . '/state.json';
            if (!is_readable($statePath)) {
                $this->logger->debug("console_remover: Session '{file}' has no readable state file.", [
                    'file' => after($sessionPath, Config::get('tmpDir') . '/'),
                ]);
                continue;
            }

            $state = json_decode((string) file_get_contents($statePath), true);
            if (!is_array($state)) {
                $this->logger->debug("console_remover: Session '{file}' has invalid state payload.", [
                    'file' => after($sessionPath, Config::get('tmpDir') . '/'),
                ]);
                continue;
            }

            if ('completed' !== ag($state, 'status')) {
                continue;
            }

            if (0 !== (int) ag($state, 'connections', 0)) {
                continue;
            }

            $finishedAt = ag($state, 'finished_at');
            if (!is_string($finishedAt) || '' === trim($finishedAt)) {
                continue;
            }

            $finishedAtUnix = strtotime($finishedAt);
            if (false === $finishedAtUnix || $finishedAtUnix > $before) {
                continue;
            }

            $this->logger->notice("console_remover: Removing '{file}'. expired retention window.", [
                'file' => after($sessionPath, Config::get('tmpDir') . '/'),
                'finished_at' => make_date($finishedAtUnix)->format(Date::ATOM),
            ]);

            if (true === $inDryRunMode) {
                continue;
            }

            if (false === $this->removeConsoleSessionDirectory($sessionPath)) {
                $this->logger->debug("console_remover: Session '{file}' is busy, skipping removal.", [
                    'file' => after($sessionPath, Config::get('tmpDir') . '/'),
                ]);
            }
        }
    }

    private function removeConsoleSessionDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }

        $locks = $this->acquireConsoleCleanupLocks($path);
        if ([] === $locks) {
            return false;
        }

        try {
            $this->removeDirectory($path);
        } finally {
            $this->releaseConsoleCleanupLocks($locks);
        }

        return !is_dir($path);
    }

    /**
     * @return array<int,mixed>
     */
    private function acquireConsoleCleanupLocks(string $path): array
    {
        $handles = [];

        foreach (['state.lock', 'writer.lock'] as $file) {
            $handle = @fopen($path . '/' . $file, 'c+');
            if (false === $handle) {
                $this->releaseConsoleCleanupLocks($handles);
                return [];
            }

            if (false === flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                $this->releaseConsoleCleanupLocks($handles);
                return [];
            }

            $handles[] = $handle;
        }

        return $handles;
    }

    /**
     * @param array<int,mixed> $handles
     */
    private function releaseConsoleCleanupLocks(array $handles): void
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
        if (!is_dir($path)) {
            return;
        }

        foreach (new DirectoryIterator($path) as $item) {
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
}
