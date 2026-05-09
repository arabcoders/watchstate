<?php
/** @noinspection SqlResolve, SqlWithoutWhere */

declare(strict_types=1);

namespace Tests\Database;

use App\Libs\Config;
use App\Libs\Database\DBLayer;
use App\Libs\Exceptions\DBLayerException;
use App\Libs\Exceptions\ErrorException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Guid;
use App\Libs\Options;
use App\Libs\TestCase;
use Monolog\Level;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use PDOException;
use Throwable;

class DBLayerTest extends TestCase
{
    private ?DBLayer $db = null;
    protected ?TestHandler $handler = null;

    protected function initTestSchema(PDO $pdo): void
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
                SQL,
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
                'database' => ag(require __DIR__ . '/../../config/config.php', 'database', []),
            ]);
        }

        $pdo = new PDO(dsn: 'sqlite::memory:', options: Config::get('database.options.sqlite', []));
        $this->db = new DBLayer($pdo);
        $this->db->setLogger($logger);
        $this->initTestSchema($pdo);

        foreach (Config::get('database.exec.sqlite', []) as $cmd) {
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
                options: ['on_failure' => fn(Throwable $e) => throw new ErrorException('Error occurred')],
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
                options: ['on_failure' => fn(Throwable $e) => throw new ErrorException('Error occurred')],
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
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
                $db = new DBLayer($pdo);

                foreach (Config::get('database.exec.sqlite', []) as $cmd) {
                    $db->exec($cmd);
                }

                $this->initTestSchema($pdo);

                return $db->query(sql: 'SELECT * FROM test WHERE zid = :id');
            },
            reason: 'If PDO error mode is set to silent mode, failing to prepare a statement should still throw an exception.',
            exception: DBLayerException::class,
            exceptionMessage: 'Unable to prepare statement.',
        );
    }

    public function test_query_prepared_reuse(): void
    {
        $this->db->insert('test', [
            'name' => 'test',
            'watched' => 1,
            'added_at' => 1,
            'updated_at' => 2,
            'json_data' => json_encode(['id' => 1]),
            'nullable' => null,
        ]);

        $stmt = $this->db->prepare(
            'UPDATE test SET name = :name, nullable = :nullable, json_data = :json_data WHERE id = :id',
        );

        $this->assertSame(
            1,
            $this->db
                ->query($stmt, [
                    'name' => 'first',
                    'nullable' => null,
                    'json_data' => json_encode(['id' => 2]),
                    'id' => 1,
                ])
                ->rowCount(),
            'Prepared statements should execute correctly with null-bound values.',
        );

        $this->assertSame(
            1,
            $this->db
                ->query($stmt, [
                    'name' => 'second',
                    'nullable' => 'set',
                    'json_data' => json_encode(['id' => 3]),
                    'id' => 1,
                ])
                ->rowCount(),
            'Prepared statements should be reusable across multiple executions.',
        );

        $row = $this->db->select('test', [], ['id' => 1])->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('second', $row['name'], 'Prepared statement reuse should persist the latest scalar value.');
        $this->assertSame('set', $row['nullable'], 'Prepared statement reuse should persist updated nullable values.');
        $this->assertSame('{"id":3}', $row['json_data'], 'Prepared statement reuse should persist updated JSON payloads.');
    }

    public function test_query_on_failure_repeats(): void
    {
        $calls = 0;

        $fallback = function () use (&$calls) {
            $calls++;

            return $this->db->query('SELECT 1');
        };

        $first = $this->db->query('SELECT * FROM movies', options: ['on_failure' => $fallback]);
        $second = $this->db->query('SELECT * FROM movies', options: ['on_failure' => $fallback]);

        $this->assertSame('1', (string) $first->fetchColumn(), 'First repeated failure should recover through on_failure.');
        $this->assertSame('1', (string) $second->fetchColumn(), 'Second identical failure should also recover through on_failure.');
        $this->assertSame(2, $calls, 'on_failure should run for each repeated identical failure.');
    }

    public function test_transactions_operations()
    {
        $this->checkException(
            closure: fn() => $this->db->transactional(function (DBLayer $db) {
                $this->assertTrue($db->getBackend()->inTransaction(), 'Should be in transaction.');
                $db->insert('sqlite_sequence', ['name' => 'test', 'seq' => 1]);
                $db->insert('not_set', ['name' => 'test', 'seq' => 1]);
            }),
            reason: 'Should roll back writes when the transaction callback fails.',
            exception: DBLayerException::class,
            exceptionMessage: 'no such table',
        );

        $this->assertCount(
            0,
            $this->db->select('sqlite_sequence')->fetchAll(),
            'Should not have any records, as we rolled back.',
        );

        $ret = $this->db->transactional(function (DBLayer $db) {
            $this->assertTrue($db->getBackend()->inTransaction(), 'Should be in transaction.');
            return $db->insert('sqlite_sequence', ['name' => 'test2', 'seq' => 1]);
        });

        $this->assertSame(1, $ret->rowCount(), 'Should return the number of affected rows.');
        $this->assertCount(
            1,
            $this->db->select('sqlite_sequence')->fetchAll(),
            'Should have one record, as we committed.',
        );
    }

    public function test_insert()
    {
        $this->checkException(
            closure: fn() => $this->db->insert('test', []),
            reason: 'Should throw exception if conditions parameter is empty.',
            exception: RuntimeException::class,
        );
    }

    public function test_delete()
    {
        $this->checkException(
            closure: fn() => $this->db->delete('test', []),
            reason: 'Should throw exception if conditions parameter is empty.',
            exception: RuntimeException::class,
        );

        $this->db->insert('sqlite_sequence', ['name' => 'test', 'seq' => 1]);
        $this->assertSame(
            1,
            $this->db->delete('sqlite_sequence', ['name' => 'test'])->rowCount(),
            'Should return the number of affected rows.',
        );
    }

    public function test_getCount()
    {
        $this->assertSame(
            0,
            $this->db->getCount('sqlite_sequence'),
            'Should return the number of records in the table.',
        );
        $this->db->insert('sqlite_sequence', ['name' => 'test', 'seq' => 1]);
        $total = $this->db->getCount(
            'sqlite_sequence',
            [
                'seq' => [DBLayer::IS_HIGHER_THAN_OR_EQUAL, 1],
            ],
            options: [
                'groupby' => ['name'],
                'orderby' => ['name' => 'ASC'],
            ],
        );
        $this->assertSame(1, $total, 'Should return the number of records in the table.');
        $this->assertSame($total, $this->db->totalRows(), 'Should return the number of records in the table.');

        $this->db->delete('sqlite_sequence', ['name' => 'test']);
        $this->assertSame(
            0,
            $this->db->getCount('sqlite_sequence'),
            'Should return the number of records in the table.',
        );
    }

    public function test_update()
    {
        $this->checkException(
            closure: fn() => $this->db->update('test', [], ['id' => 1]),
            reason: 'Should throw exception if conditions parameter is empty.',
            exception: RuntimeException::class,
        );

        $this->checkException(
            closure: fn() => $this->db->update('test', ['name' => 'test'], []),
            reason: 'Should throw exception if conditions parameter is empty.',
            exception: RuntimeException::class,
        );

        $this->db->insert('sqlite_sequence', ['name' => 'test', 'seq' => 1]);
        $this->assertSame(
            1,
            $this->db->update('sqlite_sequence', ['seq' => 2], ['name' => 'test'])->rowCount(),
            'Should return the number of affected rows.',
        );
        $this->assertSame(
            1,
            $this->db->update('sqlite_sequence', ['seq' => 1], ['name' => 'test'])->rowCount(),
            'Should return the number of affected rows.',
        );
    }

    public function test_escapeIdentifier()
    {
        $this->checkException(
            closure: fn() => $this->db->escapeIdentifier(''),
            reason: 'Should throw exception if the identifier is empty.',
            exception: RuntimeException::class,
            exceptionMessage: 'Column/table must be valid ASCII code',
        );

        $this->checkException(
            closure: fn() => $this->db->escapeIdentifier('😊'),
            reason: 'Should throw exception if the identifier contains non-ASCII characters.',
            exception: RuntimeException::class,
            exceptionMessage: 'Column/table must be valid ASCII code.',
        );

        $this->checkException(
            closure: fn() => $this->db->escapeIdentifier('1foo'),
            reason: 'Should throw exception if the identifier contains non-ASCII characters.',
            exception: RuntimeException::class,
            exceptionMessage: 'Must begin with a letter or underscore',
        );

        $this->assertSame('foo', $this->db->escapeIdentifier('foo'), 'Should return foo if quote is off.');

        if ('sqlite' === $this->db->getDriver()) {
            $this->assertSame('"foo"', $this->db->escapeIdentifier('foo', true), 'Should return "foo".');
            $this->assertSame(
                '""foo"."bar""',
                $this->db->escapeIdentifier('"foo"."bar"', true),
                'Should return ""foo"."bar"".',
            );
        }
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

        $data1 = $this->db->select('test', [], ['id' => 1])->fetch(PDO::FETCH_ASSOC);
        $data2 = $this->db->select('test', [], ['id' => 2])->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('test', $data1['name'], 'Should return the record with id 1.');
        $this->assertSame('test2', $data2['name'], 'Should return the record with id 2.');

        $this->assertSame(
            2,
            $this->db
                ->select(
                    'test',
                    [],
                    ['id' => 2],
                    options: [
                        'orderby' => ['id' => 'DESC'],
                        'limit' => 1,
                        'start' => 0,
                        'groupby' => ['id'],
                    ],
                )
                ->fetch(PDO::FETCH_ASSOC)['id'],
            'Select should honor ordering, grouping, and limit options.',
        );
    }

    public function test_lock_retry()
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $random = random_int(1, 100);

        $this->handler?->clear();

        $this->db->transactional(
            function (DBLayer $db, array $options = []) use ($random) {
                // -- trigger database lock exception
                if ((int) ag($options, 'attempts', 0) < 4) {
                    throw new PDOException('database is locked');
                }

                $db->insert('sqlite_sequence', ['name' => 'test-' . $random, 'seq' => 1]);
            },
            options: [
                'max_sleep' => 0,
            ],
        );

        $this->assertSame(1, $this->db->getCount('sqlite_sequence', ['name' => 'test-' . $random]));

        $records = array_values(array_filter(
            $this->handler?->getRecords() ?? [],
            static fn($record): bool => str_contains($record->message, 'Database is locked'),
        ));

        $this->assertSame(
            [Level::Info->value, Level::Info->value, Level::Info->value, Level::Warning->value],
            array_map(static fn($record): int => $record->level->value, $records),
            'Lock retries should stay at info until the last retry before failure, which should warn.',
        );

        $this->handler?->clear();

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

        $this->checkException(
            closure: function () {
                $this->db->transactional(
                    fn() => throw new PDOException('database is locked'),
                    options: [
                        'max_sleep' => 0,
                        Options::FAIL_FAST_ON_LOCK => true,
                        'on_lock' => fn() => throw new DBLayerException('on_lock called'),
                    ],
                );
            },
            reason: 'Should bypass lock retries when fail-fast is requested.',
            exception: DBLayerException::class,
            exceptionMessage: 'database is locked',
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
            1,
            $this->db
                ->select(
                    'test',
                    [],
                    [
                        'id' => [DBLayer::IS_LOWER_THAN_OR_EQUAL, 1],
                    ],
                )
                ->fetch(PDO::FETCH_ASSOC)['id'],
            'Comparison operators should filter matching rows.',
        );

        $this->assertSame(
            2,
            $this->db
                ->select(
                    'test',
                    [],
                    [
                        'added_at' => [DBLayer::IS_NOT_BETWEEN, [1, 2]],
                    ],
                )
                ->fetch(PDO::FETCH_ASSOC)['id'],
            'Range operators should exclude rows outside the requested bounds.',
        );

        $this->assertSame(
            1,
            $this->db
                ->select(
                    'test',
                    [],
                    [
                        'nullable' => [DBLayer::IS_NULL],
                    ],
                )
                ->fetch(PDO::FETCH_ASSOC)['id'],
            'Null operators should match nullable columns.',
        );

        $this->assertSame(
            2,
            $this->db
                ->select(
                    'test',
                    [],
                    [
                        'name' => [DBLayer::IS_LIKE, 'test2'],
                    ],
                )
                ->fetch(PDO::FETCH_ASSOC)['id'],
            'LIKE operators should match partial string values.',
        );

        $this->assertSame(
            1,
            $this->db
                ->select(
                    'test',
                    [],
                    [
                        'id' => [DBLayer::IS_IN, [0, 1]],
                    ],
                )
                ->fetch(PDO::FETCH_ASSOC)['id'],
            'IN operators should match one of the provided values.',
        );

        $this->assertSame(
            2,
            $this->db
                ->select(
                    'test',
                    [],
                    [
                        'json_data' => [DBLayer::IS_JSON_EXTRACT, '$.my_id', '>', 1],
                    ],
                )
                ->fetch(PDO::FETCH_ASSOC)['id'],
            'JSON extract conditions should filter rows by extracted values.',
        );

        $this->assertSame(
            'test',
            $this->db
                ->select(
                    'fts_table',
                    [],
                    [
                        'name' => [DBLayer::IS_MATCH_AGAINST, ['name'], 'test'],
                    ],
                )
                ->fetch(PDO::FETCH_ASSOC)['name'],
            'Full-text conditions should match indexed rows.',
        );

        $this->checkException(
            closure: fn() => $this->db->select(
                'test',
                [],
                [
                    'json_data' => [DBLayer::IS_JSON_EXTRACT, '$.my_id', '>'],
                ],
            ),
            reason: 'Should throw an exception when json_extract receives less then expected parameters.',
            exception: RuntimeException::class,
            exceptionMessage: 'IS_JSON_EXTRACT: expects 3',
        );

        $this->checkException(
            closure: fn() => $this->db->select(
                'test',
                [],
                [
                    'json_data' => ['NOT_SET', '$.my_id', '>'],
                ],
            ),
            reason: 'Should throw exception on unknown operator.',
            exception: RuntimeException::class,
            exceptionMessage: 'expr not implemented',
        );

        $this->checkException(
            closure: fn() => $this->db
                ->select(
                    'fts_table',
                    [],
                    [
                        'name' => [DBLayer::IS_MATCH_AGAINST, ['name']],
                    ],
                )
                ->fetch(),
            reason: 'Should throw an exception when match against receives less then expected parameters.',
            exception: RuntimeException::class,
            exceptionMessage: 'IS_MATCH_AGAINST: expects 2',
        );

        $this->checkException(
            closure: fn() => $this->db
                ->select(
                    'fts_table',
                    [],
                    [
                        'name' => [DBLayer::IS_MATCH_AGAINST, 'name', 'test'],
                    ],
                )
                ->fetch(),
            reason: 'Should validate match-against column input.',
            exception: RuntimeException::class,
            exceptionMessage: 'IS_MATCH_AGAINST: expects parameter 1 to be array',
        );

        $this->checkException(
            closure: fn() => $this->db
                ->select(
                    'fts_table',
                    [],
                    [
                        'name' => [DBLayer::IS_MATCH_AGAINST, ['name'], ['test']],
                    ],
                )
                ->fetch(),
            reason: 'Should validate match-against search input.',
            exception: RuntimeException::class,
            exceptionMessage: 'IS_MATCH_AGAINST: expects parameter 2 to be string',
        );
    }
}
