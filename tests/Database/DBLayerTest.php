<?php
/** @noinspection SqlResolve, SqlWithoutWhere */

declare(strict_types=1);

namespace Tests\Database;

use App\Libs\Config;
use App\Libs\Database\DBLayer;
use App\Libs\Database\PDO\PDOAdapter;
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

    private function createDB(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS "test"');
        $pdo->exec(
            <<<SQL
            CREATE TABLE "test" (
                "id" INTEGER PRIMARY KEY AUTOINCREMENT,
                "name" TEXT NULL,
                "watched" INTEGER NULL DEFAULT 0,
                "added_at" INTEGER NULL,
                "updated_at" INTEGER NULL,
                "json_data" JSON NULL,
                "nullable" TEXT NULL
            )
        SQL
        );
        $pdo->exec('DROP TABLE IF EXISTS "fts_table"');
        $pdo->exec('CREATE VIRTUAL TABLE "fts_table" USING fts5( name, json_data);');
    }

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
        $this->createDB($this->db->getBackend());

        foreach (Config::get('database.exec', []) as $cmd) {
            $this->db->exec($cmd);
        }
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

        $this->assertSame(0, $this->db->exec('DELETE FROM test'));
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

                return $db->query(sql: 'SELECT * FROM test WHERE zid = :id');
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
        $this->db->insert('sqlite_sequence', ['name' => 'test', 'seq' => 1]);
        $this->assertSame('1', $this->db->lastInsertId(), 'Should return last insert id.');
        $this->db->rollBack();
        $this->assertFalse($this->db->inTransaction(), 'Should not be in transaction.');

        $this->db->start();
        $this->assertCount(
            0,
            $this->db->select('sqlite_sequence')->fetchAll(),
            'Should not have any records, as we rolled back.'
        );
        $this->db->insert('sqlite_sequence', ['name' => 'test', 'seq' => 1]);
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
                $this->db->insert('sqlite_sequence', ['name' => 'test2', 'seq' => 1]);
                $db->insert('not_set', ['name' => 'test', 'seq' => 1]);
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
            return $db->insert('sqlite_sequence', ['name' => 'test2', 'seq' => 1]);
        });

        $this->assertSame(1, $ret->rowCount(), 'Should return the number of affected rows.');
    }

    public function test_insert()
    {
        $this->checkException(
            closure: fn() => $this->db->insert('test', []),
            reason: 'Should throw exception if conditions parameter is empty.',
            exception: RuntimeException::class
        );
    }

    public function test_delete()
    {
        $this->checkException(
            closure: fn() => $this->db->delete('test', []),
            reason: 'Should throw exception if conditions parameter is empty.',
            exception: RuntimeException::class
        );

        $this->db->insert('sqlite_sequence', ['name' => 'test', 'seq' => 1]);
        try {
            $this->assertSame(
                1,
                $this->db->delete('sqlite_sequence', ['name' => 'test'], options: [
                    'limit' => 1,
                    'ignore_safety' => true
                ])->rowCount(),
                'Should return the number of affected rows.'
            );
        } catch (DBLayerException $e) {
            if (str_contains($e->getMessage(), 'near "LIMIT": syntax error') && 'sqlite' === $this->db->getDriver()) {
                $this->assertSame(
                    1,
                    $this->db->delete('sqlite_sequence', ['name' => 'test'])->rowCount(),
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
        $this->db->insert('sqlite_sequence', ['name' => 'test', 'seq' => 1]);
        $total = $this->db->getCount('sqlite_sequence', [
            'seq' => [DBLayer::IS_HIGHER_THAN_OR_EQUAL, 1]
        ], options: [
            'groupby' => ['name'],
            'orderby' => ['name' => 'ASC'],
        ]);
        $this->assertSame(1, $total, 'Should return the number of records in the table.');
        $this->assertSame($total, $this->db->totalRows(), 'Should return the number of records in the table.');

        $this->db->delete('sqlite_sequence', ['name' => 'test']);
        $this->assertSame(
            0,
            $this->db->getCount('sqlite_sequence'),
            'Should return the number of records in the table.'
        );
    }

    public function test_update()
    {
        $this->checkException(
            closure: fn() => $this->db->update('test', [], ['id' => 1]),
            reason: 'Should throw exception if conditions parameter is empty.',
            exception: RuntimeException::class
        );

        $this->checkException(
            closure: fn() => $this->db->update('test', ['name' => 'test'], []),
            reason: 'Should throw exception if conditions parameter is empty.',
            exception: RuntimeException::class
        );

        $this->db->insert('sqlite_sequence', ['name' => 'test', 'seq' => 1]);
        $this->assertSame(
            1,
            $this->db->update('sqlite_sequence', ['seq' => 2], ['name' => 'test'])->rowCount(),
            'Should return the number of affected rows.'
        );
        try {
            $this->assertSame(
                1,
                $this->db->update('sqlite_sequence', ['seq' => 1], ['name' => 'test'], options: [
                    'limit' => 1,
                    'ignore_safety' => true
                ])->rowCount(),
                'Should return the number of affected rows.'
            );
        } catch (DBLayerException $e) {
            if (str_contains($e->getMessage(), 'near "LIMIT": syntax error') && 'sqlite' === $this->db->getDriver()) {
                $this->assertSame(
                    1,
                    $this->db->update('sqlite_sequence', ['seq' => 1], ['name' => 'test'])->rowCount(),
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
        $this->db->insert('sqlite_sequence', ['name' => 'test', 'seq' => 1]);
        $this->assertSame('1', $this->db->id('test'), 'Should return the last insert id.');
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
        $this->db->insert('test', [
            'name' => 'test',
            'watched' => 1,
            'added_at' => 1,
            'updated_at' => 2,
            'json_data' => json_encode([
                'my_id' => 1,
                'my_name' => 'test',
                'my_data' => [
                    'my_id' => 1,
                    'my_name' => 'test',
                ],
            ]),
        ]);

        $this->db->insert('test', [
            'name' => 'test2',
            'watched' => 0,
            'added_at' => 3,
            'updated_at' => 4,
            'json_data' => json_encode([
                'my_id' => 2,
                'my_name' => 'test2',
                'my_data' => [
                    'my_id' => 2,
                    'my_name' => 'test2',
                ],
            ]),
        ]);

        $data1 = $this->db->select('test', [], ['id' => 1])->fetch();
        $data2 = $this->db->select('test', [], ['id' => 2])->fetch();

        $this->checkException(
            closure: fn() => $this->db->select('test', ['id' => 1], ['id' => 1]),
            reason: 'Should throw TypeError exception if cols value is not a string.',
            exception: TypeError::class,
            exceptionMessage: 'must be of type string',
        );

        $this->checkException(
            closure: fn() => $this->db->select('test', ['*'], ['id'], options: ['count' => true]),
            reason: 'Should throw exception if conditions parameter is not an key/value pairs.',
            exception: TypeError::class,
            exceptionMessage: 'must be of type string',
        );

        $this->assertSame(
            $data1,
            $this->db->select('test', [], ['id' => 1])->fetch(),
            'Should return the record with id 1.'
        );
        $this->assertSame(
            $data2,
            $this->db->select('test', [], ['id' => 2])->fetch(),
            'Should return the record with id 2.'
        );

        $this->assertSame(
            $data2,
            $this->db->select('test', [], ['id' => 2], options: [
                'orderby' => ['id' => 'DESC'],
                'limit' => 1,
                'start' => 0,
                'groupby' => ['id'],
            ])->fetch(),
            'Should return the record with id 2.'
        );
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

            $db->insert('sqlite_sequence', ['name' => 'test-' . $random, 'seq' => 1]);
        }, options: [
            'max_sleep' => 0,
        ]);

        $this->assertSame(1, $this->db->getCount('sqlite_sequence', ['name' => 'test-' . $random]));

        $this->checkException(
            closure: function () use ($random) {
                $this->db->transactional(fn() => throw new PDOException('database is locked'), options: [
                    'max_sleep' => 0,
                    'max_attempts' => 1,
                ]);
            },
            reason: 'Should throw an exception when the maximum number of attempts is reached.',
            exception: DBLayerException::class,
            exceptionMessage: 'database is locked',
        );
        $this->checkException(
            closure: function () use ($random) {
                $this->db->transactional(fn() => throw new PDOException('database is locked'), options: [
                    'max_sleep' => 0,
                    'max_attempts' => 1,
                    'on_lock' => fn() => throw new DBLayerException('on_lock called'),
                ]);
            },
            reason: 'Should throw an exception when the maximum number of attempts is reached.',
            exception: DBLayerException::class,
            exceptionMessage: 'on_lock called',
        );
    }

    public function test_condition_parser()
    {
        $this->db->insert('test', [
            'name' => 'test',
            'watched' => 1,
            'added_at' => 1,
            'updated_at' => 2,
            'json_data' => json_encode([
                'my_id' => 1,
                'my_name' => 'test',
                'my_data' => [
                    'my_id' => 1,
                    'my_name' => 'test',
                ],
            ]),
        ]);

        $this->db->insert('test', [
            'name' => 'test2',
            'watched' => 0,
            'added_at' => 3,
            'updated_at' => 4,
            'json_data' => json_encode([
                'my_id' => 2,
                'my_name' => 'test2',
                'my_data' => [
                    'my_id' => 2,
                    'my_name' => 'test2',
                ],
            ]),
        ]);


        $data1 = $this->db->select('test', [], ['id' => 1])->fetch();
        $data2 = $this->db->select('test', [], ['id' => 2])->fetch();

        $this->db->insert('fts_table', ['name' => $data1['name'], 'json_data' => $data1['json_data']]);
        $this->db->insert('fts_table', ['name' => $data2['name'], 'json_data' => $data2['json_data']]);

        $this->assertSame(
            $data1,
            $this->db->select('test', [], [
                'id' => [DBLayer::IS_LOWER_THAN_OR_EQUAL, 1],
            ])->fetch(),
            'Should return the record with id 1.'
        );

        $this->assertSame(
            $data2,
            $this->db->select('test', [], [
                'id' => [DBLayer::IS_HIGHER_THAN_OR_EQUAL, 2],
            ])->fetch(),
            'Should return the record with id 1.'
        );
        $this->assertSame(
            $data1,
            $this->db->select('test', [], [
                'added_at' => [DBLayer::IS_BETWEEN, [1, 2]],
            ])->fetch(),
            'Should return the record with id 1.'
        );

        $this->assertSame(
            $data2,
            $this->db->select('test', [], [
                'added_at' => [DBLayer::IS_NOT_BETWEEN, [1, 2]],
            ])->fetch(),
            'Should return the record with id 2.'
        );

        $this->assertSame(
            $data1,
            $this->db->select('test', [], [
                'nullable' => [DBLayer::IS_NULL],
            ])->fetch(),
            'Should return the record with id 1.'
        );

        $this->assertSame(
            $data2,
            $this->db->select('test', [], [
                'name' => [DBLayer::IS_LIKE, 'test2'],
            ])->fetch(),
            'Should return the record with id 2.'
        );

        $this->assertSame(
            $data1,
            $this->db->select('test', [], [
                'name' => [DBLayer::IS_NOT_LIKE, 'test2'],
            ])->fetch(),
            'Should return the record with id 1.'
        );
        $this->assertSame(
            $data1,
            $this->db->select('test', [], [
                'id' => [DBLayer::IS_IN, [0, 1]],
            ])->fetch(),
            'Should return the record with id 1.'
        );

        $this->assertSame(
            $data2,
            $this->db->select('test', [], [
                'id' => [DBLayer::IS_NOT_IN, [0, 1]],
            ])->fetch(),
            'Should return the record with id 2.'
        );

        try {
            $this->assertSame(
                $data2,
                $this->db->select('test', [], ['json_data' => [DBLayer::IS_JSON_CONTAINS, '$.my_id', 2],])->fetch(),
                'Should return the record with id 1.'
            );
        } catch (DBLayerException $e) {
            if (str_contains($e->getMessage(), 'no such function') && 'sqlite' === $this->db->getDriver()) {
                // -- pass as sqlite does not support json_contains
            } else {
                throw $e;
            }
        }

        $this->checkException(
            closure: fn() => $this->db->select('test', [], ['json_data' => [DBLayer::IS_JSON_CONTAINS, '$.my_id'],]
            )->fetch(),
            reason: 'Should throw an exception when json_contains receives less then expected parameters.',
            exception: RuntimeException::class,
            exceptionMessage: 'IS_JSON_CONTAINS: expects 2',
        );

        $this->assertSame(
            $data2,
            $this->db->select('test', [], ['json_data' => [DBLayer::IS_JSON_EXTRACT, '$.my_id', '>', 1]])->fetch(),
            'Should return the record with id 2.'
        );

        $this->checkException(
            closure: fn() => $this->db->select('test', [], ['json_data' => [DBLayer::IS_JSON_EXTRACT, '$.my_id', '>']]),
            reason: 'Should throw an exception when json_extract receives less then expected parameters.',
            exception: RuntimeException::class,
            exceptionMessage: 'IS_JSON_EXTRACT: expects 3',
        );

        $this->checkException(
            closure: fn() => $this->db->select('test', [], ['json_data' => ['NOT_SET', '$.my_id', '>']]),
            reason: 'Should throw exception on unknown operator.',
            exception: RuntimeException::class,
            exceptionMessage: 'expr not implemented',
        );

        $this->assertSame(
            'test',
            $this->db->select('fts_table', [], [
                'name' => [DBLayer::IS_MATCH_AGAINST, ['name'], 'test'],
            ])->fetch()['name'],
            'Should return the record with id 2.'
        );

        $this->checkException(
            closure: fn() => $this->db->select('fts_table', [], [
                'name' => [DBLayer::IS_MATCH_AGAINST, ['name']],
            ])->fetch(),
            reason: 'Should throw an exception when match against receives less then expected parameters.',
            exception: RuntimeException::class,
            exceptionMessage: 'IS_MATCH_AGAINST: expects 2',
        );

        $this->checkException(
            closure: fn() => $this->db->select('fts_table', [], [
                'name' => [DBLayer::IS_MATCH_AGAINST, 'name', 'test'],
            ])->fetch(),
            reason: 'Should throw an exception when match against receives less then expected parameters.',
            exception: RuntimeException::class,
            exceptionMessage: 'IS_MATCH_AGAINST: expects parameter 1 to be array',
        );

        $this->checkException(
            closure: fn() => $this->db->select('fts_table', [], [
                'name' => [DBLayer::IS_MATCH_AGAINST, ['name'], ['test']],
            ])->fetch(),
            reason: 'Should throw an exception when match against receives less then expected parameters.',
            exception: RuntimeException::class,
            exceptionMessage: 'IS_MATCH_AGAINST: expects parameter 2 to be string',
        );
    }

}
