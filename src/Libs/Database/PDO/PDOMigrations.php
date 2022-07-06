<?php

declare(strict_types=1);

namespace App\Libs\Database\PDO;

use App\Libs\Database\DatabaseInterface as iDB;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class PDOMigrations
{
    private string $path;
    private string $driver;
    private array $files = [];

    public function __construct(private PDO $pdo, private LoggerInterface $logger)
    {
        $this->path = __DIR__ . '/../../../../migrations';
        $this->driver = $this->getDriver();
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function isMigrated(): bool
    {
        $version = $this->getVersion();

        foreach ($this->parseFiles() as $migrate) {
            if ($version >= ($migrate['id'] ?? 0)) {
                continue;
            }
            return false;
        }

        return true;
    }

    public function up(array $opts = []): int
    {
        if (true === ($opts['fresh'] ?? false)) {
            $version = 0;
        } else {
            $version = $this->getVersion();
        }

        $run = 0;

        foreach ($this->parseFiles() as $migrate) {
            if ($version >= ag($migrate, 'id')) {
                continue;
            }

            if (null === ag($migrate, iDB::MIGRATE_UP, null)) {
                $this->logger->debug(
                    sprintf(
                        'Migration #%d - %s has no up path, Skipping.',
                        ag($migrate, 'id'),
                        ag($migrate, 'name')
                    )
                );
                continue;
            }

            $run++;

            $this->logger->info(sprintf('Applying Migration #%d - %s', ag($migrate, 'id'), ag($migrate, 'name')));

            $this->pdo->exec((string)ag($migrate, iDB::MIGRATE_UP));
            $this->setVersion(ag($migrate, 'id'));
        }

        if (0 === $run) {
            $this->logger->debug(sprintf('No migrations is needed. Version @ %d', $version));
        } else {
            $this->logger->info(sprintf('Applied (%d) migrations. Version is at number %d', $run, $this->getVersion()));
        }

        return 0;
    }

    public function down(): int
    {
        $this->logger->info('This driver does not support down migrations at this time.');

        return 0;
    }

    public function make(string $name): string
    {
        $name = str_replace(chr(040), '_', $name);

        $fileName = sprintf('%s_%d_%s.sql', $this->driver, time(), $name);

        $file = $this->path . '/' . $fileName;

        if (!touch($file)) {
            throw new RuntimeException(sprintf('Unable to create new migration at \'%s\'.', $this->path));
        }

        file_put_contents(
            $file,
            <<<SQL
        -- # migrate_up

        -- Put your upgrade database commands here.

        -- # migrate_down

        -- put your downgrade database commands here.

        SQL
        );

        $this->logger->info(
            sprintf('Created new Migration file at \'%s\'.</>', $file)
        );

        return $file;
    }

    public function runMaintenance(): int|bool
    {
        if ('sqlite' === $this->driver) {
            return $this->pdo->exec('VACUUM;');
        }

        return false;
    }

    private function getVersion(): int
    {
        return (int)$this->pdo->query('PRAGMA user_version')->fetchColumn();
    }

    private function setVersion(int $version): void
    {
        $this->pdo->exec('PRAGMA user_version = ' . $version);
    }

    private function getDriver(): string
    {
        $driver = $this->pdo->getAttribute($this->pdo::ATTR_DRIVER_NAME);

        if (empty($driver) || !is_string($driver)) {
            $driver = 'unknown';
        }

        return strtolower($driver);
    }

    private function parseFiles(): array
    {
        $migrations = [];

        foreach ($this->getFiles() as $file) {
            [$type, $id, $name] = (array)preg_split(
                '#^(\w+)_(\d+)_(.+)\.sql$#',
                basename($file),
                -1,
                PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE
            );

            if ($type !== $this->driver) {
                continue;
            }

            $id = (int)$id;

            [$up, $down] = (array)preg_split(
                '/^-- #\s+?migrate_down\b/im',
                (string)file_get_contents($file),
                -1,
                PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE
            );

            $up = trim(preg_replace('/^-- #\s+?migrate_up\b/i', '', (string)$up));
            $down = trim((string)$down);

            $migrations[$id] = [
                'type' => $type,
                'id' => $id,
                'name' => $name,
                iDB::MIGRATE_UP => $up,
                iDB::MIGRATE_DOWN => $down,
            ];
        }

        return $migrations;
    }

    private function getFiles(): array
    {
        if (!empty($this->files)) {
            return $this->files;
        }

        foreach ((array)glob($this->path . '/*.sql') as $file) {
            if (!is_string($file) || false === ($f = realpath($file))) {
                throw new RuntimeException(sprintf('Unable to get real path to \'%s\'', $file));
            }

            $this->files[] = $f;
        }

        return $this->files;
    }
}
