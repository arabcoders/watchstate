<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libs\Database\DBLayer;
use App\Libs\Database\PackageMigrationFactory;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Database\PdoFactory;
use Monolog\Logger;
use PDO;

trait SQLiteDbTestSupport
{
    /**
     * @return array{0: PDOAdapter, 1: PDO, 2: string}
     */
    private function createFileDb(Logger $logger, ?string $file = null): array
    {
        $this->initTempDir();

        $file ??= self::$tmpPath . '/' . PdoFactory::DB_FILE;
        $pdo = $this->openSqliteFile($file);

        $migrations = new PackageMigrationFactory();
        if (false === $migrations->isMigrated($pdo)) {
            $migrations->migrate($pdo, dryRun: false);
        }

        ensure_indexes($pdo, $logger);

        return [new PDOAdapter($logger, new DBLayer($this->openSqliteFile($file))), $pdo, $file];
    }

    private function openSqliteFile(string $file): PDO
    {
        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 0);
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo->exec('PRAGMA busy_timeout = 0');

        return $pdo;
    }
}
