<?php

declare(strict_types=1);

namespace App\Libs\Database\PDO;

use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\DBLayer;
use App\Libs\Stream;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SplFileObject;

/**
 * Class PDOMigrations
 *
 * Provides functionality to handle database migrations using PDO.
 */
final class PDOMigrations
{
    /**
     * @var string The path to migrations directory.
     */
    private string $path;

    /**
     * @var string The database driver.
     */
    private string $driver;

    /**
     * @var array<SplFileObject> List of migration files.
     */
    private array $files = [];

    /**
     * Constructs a new instance of the class.
     *
     * @param DBLayer $db The database connection object.
     * @param LoggerInterface $logger The logger instance.
     *
     * @return void
     */
    public function __construct(private DBLayer $db, private LoggerInterface $logger)
    {
        $this->path = __DIR__ . '/../../../../migrations';
        $this->driver = $this->getDriver();
    }

    /**
     * Sets the logger instance.
     *
     * @param LoggerInterface $logger The logger instance.
     *
     * @return self The current instance of the class.
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Checks if all migration files have been successfully migrated up to the current version.
     *
     * @return bool Returns true if all migration has been applied. false otherwise.
     */
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

    /**
     * Applies migrations up to the current version.
     *
     * @param array $opts Options for the migration.
     *                    Possible options:
     *                    - fresh: If set to true, all migrations will be applied from the beginning.
     *                    Defaults to false.
     *
     * @return int Returns 0 if the migrations were applied successfully.
     */
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
                $this->logger->debug(r('PDOMigrations: Migration #{id} - {name} has no up path, Skipping.', context: [
                    'id' => ag($migrate, 'id'),
                    'name' => ag($migrate, 'name')
                ]));
                continue;
            }

            $run++;

            $this->logger->info(r('PDOMigrations: Applying Migration #{id} - {name}.', context: [
                'id' => ag($migrate, 'id'),
                'name' => ag($migrate, 'name')
            ]));

            $this->db->exec((string)ag($migrate, iDB::MIGRATE_UP));
            $this->setVersion(ag($migrate, 'id'));
        }

        if (0 === $run) {
            $this->logger->debug(r('PDOMigrations: No migrations is needed. Version @ {version}', context: [
                'version' => $version
            ]));
        } else {
            $this->logger->info(r('PDOMigrations: Applied ({total}) migrations. Version is at number {number}.', [
                'total' => $run,
                'number' => $this->getVersion(),
            ]));
        }

        return 0;
    }

    /**
     * Logs a message indicating that the driver does not support down migrations and returns 0.
     *
     * @return int Returns 0.
     */
    public function down(): int
    {
        $this->logger->info('PDOMigrations: This driver does not support down migrations at this time.');

        return 0;
    }

    /**
     * Creates a new migration file with the given name.
     *
     * @param string $name The name of the migration file.
     *
     * @return string The path of the newly created migration file.
     */
    public function make(string $name): string
    {
        $name = str_replace(chr(040), '_', $name);

        $fileName = sprintf('%s_%d_%s.sql', $this->driver, time(), $name);

        $file = $this->path . '/' . $fileName;

        if (!touch($file)) {
            throw new RuntimeException(r("PDOMigrations: Unable to create new migration at '{file}'.", [
                'file' => $this->path
            ]));
        }

        $stream = new Stream($file, 'w');
        $stream->write(
            <<<SQL
        -- # migrate_up

        -- Put your upgrade database commands here.

        -- # migrate_down

        -- put your downgrade database commands here.

        SQL
        );
        $stream->close();

        $this->logger->info(r("PDOMigrations: Created new Migration file at '{file}'.", [
            'file' => $file
        ]));

        return $file;
    }

    /**
     * Runs maintenance operations based on the database driver.
     *
     * @return int|bool return maintenance result or false if not supported.
     */
    public function runMaintenance(): int|bool
    {
        if ('sqlite' === $this->driver) {
            return $this->db->exec('VACUUM;');
        }

        return false;
    }

    /**
     * Retrieves the current version of the database schema.
     *
     * @return int Returns the current database schema version as an integer.
     */
    private function getVersion(): int
    {
        return (int)$this->db->query('PRAGMA user_version')->fetchColumn();
    }

    /**
     * Sets the current version of the migration.
     *
     * @param int $version The version to set.
     *
     * @return void
     */
    private function setVersion(int $version): void
    {
        $this->db->exec('PRAGMA user_version = ' . $version);
    }

    /**
     * Retrieves the driver name associated with the current PDO instance.
     *
     * @return string Returns the name of the driver as a lower case string.
     */
    private function getDriver(): string
    {
        $driver = $this->db->getDriver();

        if (empty($driver)) {
            $driver = 'unknown';
        }

        return strtolower($driver);
    }

    /**
     * Parses the migration files and returns an array of parsed migrations.
     *
     * @return array<array-key, array{type: string, id: int, name: string, up: string, down: string}> Returns an array of parsed migrations.
     */
    private function parseFiles(): array
    {
        $migrations = [];

        foreach ($this->getFiles() as $file) {
            [$type, $id, $name] = (array)preg_split(
                '#^(\w+)_(\d+)_(.+)\.sql$#',
                $file->getBasename(),
                -1,
                PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE
            );

            if ($type !== $this->driver) {
                continue;
            }

            $id = (int)$id;

            [$up, $down] = (array)preg_split(
                '/^-- #\s+?migrate_down\b/im',
                (string)$file->fread($file->getSize()),
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

    /**
     * Retrieves a list of all migration files in the specified path.
     *
     * @return array<SplFileObject> Returns an array of SplFileObject instances.
     */
    private function getFiles(): array
    {
        if (!empty($this->files)) {
            return $this->files;
        }

        foreach ((array)glob($this->path . '/*.sql') as $file) {
            if (!is_string($file) || false === ($f = realpath($file))) {
                throw new RuntimeException(r("PDOMigrations: Unable to get real path to '{file}'.", [
                    'file' => $file
                ]));
            }

            $this->files[] = new SplFileObject($f);
        }

        return $this->files;
    }
}
