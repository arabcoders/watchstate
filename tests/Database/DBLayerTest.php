<?php
/** @noinspection SqlResolve, SqlWithoutWhere */

declare(strict_types=1);

namespace Tests\Database;

use App\Libs\Config;
use App\Libs\Database\DBLayer;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\DBLayerException;
use App\Libs\Exceptions\ErrorException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Guid;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use PDOException;
use Throwable;
use TypeError;

class DBLayerTest extends TestCase
{
    private DBLayer|null $db = null;
    protected TestHandler|null $handler = null;

    public function setUp(): void
    {
        $this->handler = new TestHandler();
        $logger = new Logger('logger');
        $logger->pushHandler($this->handler);
        Guid::setLogger($logger);

        if (null === Config::get('database', null)) {
            Config::init([
                'database' => ag(require __DIR__ . '/../../config/config.php', 'database', [])
            ]);
        }

        $this->db = new DBLayer(new PDO(dsn: 'sqlite::memory:', options: Config::get('database.options', [])));

        foreach (Config::get('database.exec', []) as $cmd) {
            $this->db->exec($cmd);
        }

        (new PDOAdapter($logger, $this->db))->migrations('up');
    }

    public function test_exec()
    {
        $this->checkException(
            closure: fn() => $this->db->exec('SELECT * FROM movies'),
            reason: 'Should throw an exception when an error occurs and no on_failure handler is set.',
            exception: DBLayerException::class,
            exceptionMessage: 'no such table',
        );

        $this->checkException(
            closure: fn() => $this->db->exec(
                sql: 'SELECT * FROM movies',
                options: ['on_failure' => fn(Throwable $e) => throw new ErrorException('Error occurred')]
            ),
            reason: 'the on_failure handler should be called when an error occurs.',
            exception: ErrorException::class,
            exceptionMessage: 'Error occurred',
        );

        $this->assertSame(0, $this->db->exec('DELETE FROM state'));
    }

    public function test_query()
    {
        $this->checkException(
            closure: fn() => $this->db->query(sql: 'SELECT * FROM movies'),
            reason: 'Should throw an exception when an error occurs and no on_failure handler is set.',
            exception: DBLayerException::class,
            exceptionMessage: 'no such table',
        );

        $this->checkException(
            closure: fn() => $this->db->query(
                sql: 'SELECT * FROM movies',
                options: ['on_failure' => fn(Throwable $e) => throw new ErrorException('Error occurred')]
            ),
            reason: 'the on_failure handler should be called when an error occurs.',
            exception: ErrorException::class,
            exceptionMessage: 'Error occurred',
        );

        $this->checkException(
            closure: function () {
                $options = Config::get('database.options', []);
                $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_SILENT;

                $pdo = new PDO(dsn: 'sqlite::memory:', options: $options);
                $db = new DBLayer($pdo);

                foreach (Config::get('database.exec', []) as $cmd) {
                    $this->db->exec($cmd);
                }

                (new PDOAdapter(new Logger('test'), $this->db))->migrations('up');

                return $db->query(sql: 'SELECT * FROM state WHERE zid = :id');
            },
            reason: 'If PDO error mode is set to silent mode, failing to prepare a statement should still throw an exception.',
            exception: DBLayerException::class,
            exceptionMessage: 'Unable to prepare statement.',
        );
    }

    public function test_transactions_operations()
    {
        $this->db->start();
        $this->assertTrue($this->db->inTransaction(), 'Should be in transaction.');
        $this->assertFalse($this->db->start(), 'Should not start a new transaction if we are already in one.');
        $this->db->insert('sqlite_sequence', ['name' => 'state', 'seq' => 1]);
        $this->assertSame('1', $this->db->lastInsertId(), 'Should return last insert id.');
        $this->db->rollBack();
        $this->assertFalse($this->db->inTransaction(), 'Should not be in transaction.');

        $this->db->start();
        $this->assertCount(
            0,
            $this->db->select('sqlite_sequence')->fetchAll(),
            'Should not have any records, as we rolled back.'
        );
        $this->db->insert('sqlite_sequence', ['name' => 'state', 'seq' => 1]);
        $this->assertSame('1', $this->db->lastInsertId(), 'Should return last insert id.');
        $this->db->commit();
        $this->assertFalse($this->db->inTransaction(), 'Should not be in transaction.');
        $this->assertCount(
            1,
            $this->db->select('sqlite_sequence')->fetchAll(),
            'Should have one record, as we committed.'
        );

        $this->checkException(
            closure: fn() => $this->db->transactional(function (DBLayer $db) {
                $this->db->insert('sqlite_sequence', ['name' => 'state2', 'seq' => 1]);
                $db->insert('not_set', ['name' => 'state', 'seq' => 1]);
            }),
            reason: 'Should throw an exception when trying to commit without starting a transaction.',
            exception: DBLayerException::class,
            exceptionMessage: 'no such table',
        );

        $this->assertCount(
            1,
            $this->db->select('sqlite_sequence')->fetchAll(),
            'Should have one record, as the previous transaction was rolled back.'
        );

        $ret = $this->db->transactional(function (DBLayer $db) {
            return $db->insert('sqlite_sequence', ['name' => 'state2', 'seq' => 1]);
        });

        $this->assertSame(1, $ret->rowCount(), 'Should return the number of affected rows.');
    }

    public function test_insert()
    {
        $this->checkException(
            closure: fn() => $this->db->insert('state', []),
            reason: 'Should throw exception if conditions parameter is empty.',
            exception: RuntimeException::class
        );
    }

    public function test_delete()
    {
        $this->checkException(
            closure: fn() => $this->db->delete('state', []),
            reason: 'Should throw exception if conditions parameter is empty.',
            exception: RuntimeException::class
        );

        $this->db->insert('sqlite_sequence', ['name' => 'state', 'seq' => 1]);
        try {
            $this->assertSame(
                1,
                $this->db->delete('sqlite_sequence', ['name' => 'state'], options: [
                    'limit' => 1,
                    'ignore_safety' => true
                ])->rowCount(),
                'Should return the number of affected rows.'
            );
        } catch (DBLayerException $e) {
            if (str_contains($e->getMessage(), 'near "LIMIT": syntax error') && 'sqlite' === $this->db->getDriver()) {
                $this->assertSame(
                    1,
                    $this->db->delete('sqlite_sequence', ['name' => 'state'])->rowCount(),
                    'Should return the number of affected rows.'
                );
            } else {
                throw $e;
            }
        }
    }

    public function test_getCount()
    {
        $this->assertSame(
            0,
            $this->db->getCount('sqlite_sequence'),
            'Should return the number of records in the table.'
        );
        $this->db->insert('sqlite_sequence', ['name' => 'state', 'seq' => 1]);
        $total = $this->db->getCount('sqlite_sequence', [
            'seq' => [DBLayer::IS_HIGHER_THAN_OR_EQUAL, 1]
        ], options: [
            'groupby' => ['name'],
            'orderby' => ['name' => 'ASC'],
        ]);
        $this->assertSame(1, $total, 'Should return the number of records in the table.');
        $this->assertSame($total, $this->db->totalRows(), 'Should return the number of records in the table.');

        $this->db->delete('sqlite_sequence', ['name' => 'state']);
        $this->assertSame(
            0,
            $this->db->getCount('sqlite_sequence'),
            'Should return the number of records in the table.'
        );
    }

    public function test_update()
    {
        $this->checkException(
            closure: fn() => $this->db->update('state', [], ['id' => 1]),
            reason: 'Should throw exception if conditions parameter is empty.',
            exception: RuntimeException::class
        );

        $this->checkException(
            closure: fn() => $this->db->update('state', ['name' => 'test'], []),
            reason: 'Should throw exception if conditions parameter is empty.',
            exception: RuntimeException::class
        );

        $this->db->insert('sqlite_sequence', ['name' => 'state', 'seq' => 1]);
        $this->assertSame(
            1,
            $this->db->update('sqlite_sequence', ['seq' => 2], ['name' => 'state'])->rowCount(),
            'Should return the number of affected rows.'
        );
        try {
            $this->assertSame(
                1,
                $this->db->update('sqlite_sequence', ['seq' => 1], ['name' => 'state'], options: [
                    'limit' => 1,
                    'ignore_safety' => true
                ])->rowCount(),
                'Should return the number of affected rows.'
            );
        } catch (DBLayerException $e) {
            if (str_contains($e->getMessage(), 'near "LIMIT": syntax error') && 'sqlite' === $this->db->getDriver()) {
                $this->assertSame(
                    1,
                    $this->db->update('sqlite_sequence', ['seq' => 1], ['name' => 'state'])->rowCount(),
                    'Should return the number of affected rows.'
                );
            } else {
                throw $e;
            }
        }
    }

    public function test_quote()
    {
        if ('sqlite' === $this->db->getDriver()) {
            $this->assertEquals("'test'", $this->db->quote('test'), "Should return 'test'.");
            $this->assertSame("'''test'''", $this->db->quote("'test'"), "Should return ''''test''''.");
            $this->assertSame("'\"test\"'", $this->db->quote('"test"'), "Should return '\"test\"'.");
        }
    }

    public function test_id()
    {
        $this->db->insert('sqlite_sequence', ['name' => 'state', 'seq' => 1]);
        $this->assertSame('1', $this->db->id('state'), 'Should return the last insert id.');
        $this->assertSame('1', $this->db->id(), 'Should return the last insert id.');
    }

    public function test_escapeIdentifier()
    {
        $this->checkException(
            closure: fn() => $this->db->escapeIdentifier(''),
            reason: 'Should throw exception if the identifier is empty.',
            exception: RuntimeException::class,
            exceptionMessage: 'Column/table must be valid ASCII code'
        );

        $this->checkException(
            closure: fn() => $this->db->escapeIdentifier('😊'),
            reason: 'Should throw exception if the identifier contains non-ASCII characters.',
            exception: RuntimeException::class,
            exceptionMessage: 'Column/table must be valid ASCII code.'
        );

        $this->checkException(
            closure: fn() => $this->db->escapeIdentifier('1foo'),
            reason: 'Should throw exception if the identifier contains non-ASCII characters.',
            exception: RuntimeException::class,
            exceptionMessage: 'Must begin with a letter or underscore'
        );

        $this->assertSame('foo', $this->db->escapeIdentifier('foo'), 'Should return foo if quote is off.');

        if ('sqlite' === $this->db->getDriver()) {
            $this->assertSame('"foo"', $this->db->escapeIdentifier('foo', true), 'Should return "foo".');
            $this->assertSame(
                '""foo"."bar""',
                $this->db->escapeIdentifier('"foo"."bar"', true),
                'Should return ""foo"."bar"".'
            );
        }
    }

    public function test_getBackend()
    {
        $this->assertInstanceOf(PDO::class, $this->db->getBackend(), 'Should return the PDO instance.');
    }

    public function test_select()
    {
        $this->checkException(
            closure: fn() => $this->db->select('state', ['id' => 1], ['id' => 1]),
            reason: 'Should throw TypeError exception if cols value is not a string.',
            exception: TypeError::class,
            exceptionMessage: 'must be of type string',
        );

        $this->checkException(
            closure: fn() => $this->db->select('state', ['*'], ['id'], options: ['count' => true]),
            reason: 'Should throw exception if conditions parameter is not an key/value pairs.',
            exception: TypeError::class,
            exceptionMessage: 'must be of type string',
        );

        $f = $this->db->select('state', [], [
            iState::COLUMN_META_DATA_ADDED_AT => [DBLayer::IS_HIGHER_THAN_OR_EQUAL, 1],
            iState::COLUMN_UPDATED_AT => [DBLayer::IS_LOWER_THAN_OR_EQUAL, 100],
            iState::COLUMN_WATCHED => [DBLAyer::IS_IN, [1, 2]],
        ], [
            'count' => true,
            'groupby' => ['id'],
            'orderby' => ['id' => 'ASC'],
            'limit' => 1,
            'start' => 0,
        ]);
    }

    public function test_lock_retry()
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $random = random_int(1, 100);

        $this->db->transactional(function (DBLayer $db, array $options = []) use ($random) {
            // -- trigger database lock exception
            if ((int)ag($options, 'attempts', 0) < 1) {
                throw new PDOException('database is locked');
            }

            $db->insert('sqlite_sequence', ['name' => 'state-' . $random, 'seq' => 1]);
        }, options: [
            'max_sleep' => 0,
        ]);

        $this->assertSame(1, $this->db->getCount('sqlite_sequence', ['name' => 'state-' . $random]));
    }
}
