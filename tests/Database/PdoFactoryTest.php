<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libs\Database\PdoFactory;
use App\Libs\TestCase;
use PDO;

final class PdoFactoryTest extends TestCase
{
    /**
     * This is a regression test for long `state:import` run which sometimes triggers the `database is locked`.
     *
     * The sequence on how the error can occur is as follows:
     *
     * 1. The import process opens a long SQLite transaction.
     * 2. The transaction performs reads first, which establishes a read snapshot.
     * 3. A concurrent request opens another PDO connection while that import transaction is still active.
     * 4. PdoFactory used to replay every configured bootstrap statement on every new connection, including
     *    persistent SQLite PRAGMAs such as `PRAGMA user_version=...` and `PRAGMA journal_mode=WAL`.
     * 5. Replaying those persistent PRAGMAs can write to SQLite database metadata even when the value is already
     *    what we want. That changes the database after the import transaction has taken its read snapshot.
     * 6. When the import transaction later tries to upgrade from read to write, SQLite rejects the stale snapshot
     *    with `SQLSTATE[HY000]: General error: 5 database is locked`.
     *
     * Retrying the UPDATE inside the same transaction cannot fix this class of lock: the transaction snapshot is
     * already stale. The correct fix is to avoid mutating persistent SQLite metadata from later connections when
     * it is already set. Connection-local PRAGMAs like `busy_timeout` still need to run for every connection.
     *
     * This test deliberately opens another connection between the SELECT and UPDATE. If `PdoFactory` starts
     * blindly executing stable persistent PRAGMAs again, the UPDATE below should fail with `database is locked`.
     */
    public function test_skips_stable_pragmas(): void
    {
        $this->initTempDir();

        $factory = new PdoFactory();
        $file = self::$tmpPath . '/snapshot.db';

        $setup = $factory->createForFile($file);
        $setup->exec('CREATE TABLE state (id INTEGER PRIMARY KEY, name TEXT)');
        $setup->exec("INSERT INTO state (name) VALUES ('before')");
        $setup = null;

        $transaction = $factory->createForFile($file);
        $transaction->beginTransaction();

        $stmt = $transaction->query('SELECT * FROM state');
        self::assertNotFalse($stmt);
        $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $opened = $factory->createForFile($file);
        self::assertInstanceOf(PDO::class, $opened);

        self::assertSame(1, $transaction->exec("UPDATE state SET name = 'after' WHERE id = 1"));

        $transaction->rollBack();
    }
}
