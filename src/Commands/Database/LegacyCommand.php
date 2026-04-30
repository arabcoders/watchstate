<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Database\PackageMigrationFactory;
use App\Libs\Database\PdoFactory;
use App\Libs\Exceptions\RuntimeException;
use PDO;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[Cli(command: self::ROUTE)]
final class LegacyCommand extends Command
{
    public const string ROUTE = 'db:legacy';

    private const array APP_TABLES = [
        'events',
        'playlist_items',
        'playlists',
        'state',
    ];

    public function __construct(
        private readonly PdoFactory $pdoFactory,
        private readonly PackageMigrationFactory $migrations,
        private readonly iLogger $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Import legacy databases into the current database format.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user. Default all users.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace non-empty db instead of skipping them')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove previously renamed legacy backups (*.migrated)')
            ->addOption('execute', 'x', InputOption::VALUE_NONE, 'Execute migration or backup removal');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        try {
            $targets = $this->targets($input->getOption('user'));
        } catch (RuntimeException $e) {
            $output->writeln(r('<error>{message}</error>', [
                'message' => $e->getMessage(),
            ]));

            return self::FAILURE;
        }

        if (true === (bool) $input->getOption('remove')) {
            return $this->removeBackups($targets, (bool) $input->getOption('execute'), $output);
        }

        try {
            $plan = $this->preflight($targets, (bool) $input->getOption('force'), $output);
        } catch (Throwable $e) {
            $output->writeln(r('<error>{message}</error>', [
                'message' => $e->getMessage(),
            ]));

            return self::FAILURE;
        }

        foreach ($plan as $target) {
            if (false === (bool) $input->getOption('execute')) {
                $action = true === $target['replace'] ? 'replace' : 'create';
                $output->writeln(r('<info>{target}: Would {action} {target_file} from {source} and rename source to {backup}.</info>', [
                    'target' => $target['name'],
                    'action' => $action,
                    'target_file' => $target['target'],
                    'source' => $target['source'],
                    'backup' => $target['backup'],
                ]));
                continue;
            }

            try {
                $this->migrateTarget($target);
            } catch (Throwable $e) {
                $output->writeln(r('<error>{target}: {message}</error>', [
                    'target' => $target['name'],
                    'message' => $e->getMessage(),
                ]));

                return self::FAILURE;
            }

            $output->writeln(r('<info>{target}: Migrated {source} into {target_file} and renamed source to {backup}.</info>', [
                'target' => $target['name'],
                'source' => $target['source'],
                'target_file' => $target['target'],
                'backup' => $target['backup'],
            ]));
        }

        if (false === (bool) $input->getOption('execute')) {
            $output->writeln('<comment>Dry-run: no files were modified. Re-run with --execute to make changes.</comment>');
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int,array{name:string,target:string,source:string,backup:string}> $targets
     *
     * @return array<int,array{name:string,target:string,source:string,backup:string,replace:bool}>
     */
    private function preflight(array $targets, bool $force, OutputInterface $output): array
    {
        $plan = [];

        foreach ($targets as $target) {
            if (false === file_exists($target['source'])) {
                if (true === file_exists($target['backup'])) {
                    $output->writeln(r('<comment>{target}: Legacy source already renamed to {backup}. Skipping.</comment>', [
                        'target' => $target['name'],
                        'backup' => $target['backup'],
                    ]));
                    continue;
                }

                $output->writeln(r('<comment>{target}: No legacy source found at {source}. Skipping.</comment>', [
                    'target' => $target['name'],
                    'source' => $target['source'],
                ]));
                continue;
            }

            if (true === file_exists($target['backup'])) {
                $output->writeln(r('<comment>WARNING {target}: Legacy backup {backup} already exists. Remove it with db:legacy --remove before retrying. Skipping.</comment>', [
                    'target' => $target['name'],
                    'backup' => $target['backup'],
                ]));
                continue;
            }

            $empty = $this->isTargetEmpty($target['target']);
            if (false === $empty && false === $force) {
                $output->writeln(r('<comment>WARNING {target}: Target {target_file} is not empty. Skipping. Re-run with --force to replace it.</comment>', [
                    'target' => $target['name'],
                    'target_file' => $target['target'],
                ]));
                continue;
            }

            $plan[] = [
                ...$target,
                'replace' => false === $empty,
            ];
        }

        return $plan;
    }

    /**
     * @param array{name:string,target:string,source:string,backup:string,replace:bool} $target
     */
    private function migrateTarget(array $target): void
    {
        $tempFile = $target['target'] . '.legacy-' . uniqid('', true) . '.tmp';
        $pdo = null;
        $sourceRenamed = false;

        try {
            $pdo = $this->pdoFactory->createForFile($tempFile);

            if (false === $this->migrations->isMigrated($pdo)) {
                $this->migrations->migrate($pdo, dryRun: false);
            }

            $this->copyLegacyData($pdo, $target['source']);
            ensure_indexes($pdo, $this->logger);
            $pdo = null;

            if (false === @rename($target['source'], $target['backup'])) {
                throw new RuntimeException(r("Unable to rename legacy database '{source}' to '{backup}'.", [
                    'source' => $target['source'],
                    'backup' => $target['backup'],
                ]));
            }

            $sourceRenamed = true;

            if (false === @rename($tempFile, $target['target'])) {
                throw new RuntimeException(r("Unable to replace '{target}' with migrated database.", [
                    'target' => $target['target'],
                ]));
            }
            $sourceRenamed = false;
        } catch (Throwable $e) {
            if (true === $sourceRenamed) {
                @rename($target['backup'], $target['source']);
            }

            throw $e;
        } finally {
            $pdo = null;

            if (true === file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    private function copyLegacyData(PDO $pdo, string $sourceFile): void
    {
        $attached = false;

        try {
            $pdo->exec('ATTACH DATABASE ' . $pdo->quote($sourceFile) . ' AS legacy');
            $attached = true;
            $pdo->beginTransaction();

            foreach (self::APP_TABLES as $table) {
                $pdo->exec(sprintf('INSERT INTO "%1$s" SELECT * FROM legacy."%1$s"', $table));
            }

            $pdo->commit();
            $pdo->exec('DETACH DATABASE legacy');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if (true === $attached) {
                try {
                    $pdo->exec('DETACH DATABASE legacy');
                } catch (Throwable) {
                }
            }

            throw $e;
        }
    }

    private function removeBackups(array $targets, bool $execute, OutputInterface $output): int
    {
        foreach ($targets as $target) {
            if (false === file_exists($target['backup'])) {
                $output->writeln(r('<comment>{target}: No legacy backup found at {backup}. Skipping.</comment>', [
                    'target' => $target['name'],
                    'backup' => $target['backup'],
                ]));
                continue;
            }

            if (false === $execute) {
                $output->writeln(r('<info>{target}: Would remove legacy backup {backup}.</info>', [
                    'target' => $target['name'],
                    'backup' => $target['backup'],
                ]));
                continue;
            }

            if (false === @unlink($target['backup'])) {
                $output->writeln(r('<error>{target}: Unable to remove legacy backup {backup}.</error>', [
                    'target' => $target['name'],
                    'backup' => $target['backup'],
                ]));

                return self::FAILURE;
            }

            $output->writeln(r('<info>{target}: Removed legacy backup {backup}.</info>', [
                'target' => $target['name'],
                'backup' => $target['backup'],
            ]));
        }

        if (false === $execute) {
            $output->writeln('<comment>Dry-run: no files were modified. Re-run with --execute to make changes.</comment>');
        }

        return self::SUCCESS;
    }

    private function isTargetEmpty(string $file): bool
    {
        if (false === file_exists($file)) {
            return true;
        }

        $pdo = $this->pdoFactory->createForFile($file);

        foreach (self::APP_TABLES as $table) {
            if (false === $this->tableExists($pdo, $table)) {
                continue;
            }

            $count = $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')?->fetchColumn();
            if ((int) $count > 0) {
                return false;
            }
        }

        return true;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
        $stmt->execute(['name' => $table]);

        return false !== $stmt->fetchColumn();
    }

    /**
     * @return array<int,array{name:string,target:string,source:string,backup:string}>
     */
    private function targets(mixed $selectedUser): array
    {
        return array_map(static function (string $user): array {
            $source = 'main' === $user
                ? Config::get('path') . '/db/' . PdoFactory::OLD_DB_FILE
                : Config::get('path') . '/users/' . $user . '/' . PdoFactory::OLD_USER_DB_FILE;

            return [
                'name' => $user,
                'target' => 'main' === $user ? (string) Config::get('database.file') : get_user_db($user),
                'source' => $source,
                'backup' => $source . '.migrated',
            ];
        }, select_users($selectedUser));
    }
}
