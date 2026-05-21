<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libs\Config;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\DBLayer;
use App\Libs\Database\PackageMigrationFactory;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\DBAdapterException as DBException;
use App\Libs\Exceptions\DBLayerException;
use App\Libs\Guid;
use App\Libs\Options;
use App\Libs\TestCase;
use DateInterval;
use DateTimeImmutable;
use Monolog\Logger;
use Monolog\Handler\TestHandler;
use PDO;
use Psr\SimpleCache\CacheInterface;
use Tests\Support\SQLiteDbTestSupport;

class PDOAdapterTest extends TestCase
{
    use SQLiteDbTestSupport;

    private array $testMovie = [];
    private array $testEpisode = [];

    private iDB|null $db = null;

    public function setUp(): void
    {
        $this->testMovie = require __DIR__ . '/../Fixtures/MovieEntity.php';
        $this->testEpisode = require __DIR__ . '/../Fixtures/EpisodeEntity.php';

        $logger = new Logger('logger');
        Guid::setLogger($logger);

        $this->db = $this->createDb($logger);
        $this->db->setOptions([
            Options::DEBUG_TRACE => true,
            'class' => new StateEntity([]),
        ]);
        $this->db->setLogger($logger);
    }

    private function makeCacheStub(): CacheInterface
    {
        return new class implements CacheInterface {
            public array $store = [];
            public int $getCalls = 0;
            public int $setCalls = 0;

            public function get(string $key, mixed $default = null): mixed
            {
                $this->getCalls++;
                return $this->store[$key] ?? $default;
            }

            public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
            {
                $this->setCalls++;
                $this->store[$key] = $value;
                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);
                return true;
            }

            public function clear(): bool
            {
                $this->store = [];
                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                foreach ($keys as $key) {
                    yield $key => $this->get((string)$key, $default);
                }
            }

            public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->set((string)$key, $value, $ttl);
                }

                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $key) {
                    $this->delete((string)$key);
                }

                return true;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->store);
            }
        };
    }

    /**
     * @return array<int, iState>
     */
    private function seedEntities(): array
    {
        $episode = $this->db->insert(new StateEntity($this->testEpisode));

        $movie = $this->db->insert(new StateEntity($this->testMovie));

        $altEpisode = $this->testEpisode;
        $altEpisode[iState::COLUMN_EPISODE] = 3;
        $altEpisode[iState::COLUMN_TITLE] = 'Different Episode';
        $altEpisode[iState::COLUMN_GUIDS][Guid::GUID_IMDB] = 'tt6101';
        $altEpisode[iState::COLUMN_GUIDS][Guid::GUID_TVDB] = '6201';
        $altEpisode[iState::COLUMN_GUIDS][Guid::GUID_TMDB] = '6301';
        $altEpisode[iState::COLUMN_GUIDS][Guid::GUID_TVMAZE] = '6401';
        $altEpisode[iState::COLUMN_GUIDS][Guid::GUID_TVRAGE] = '6501';
        $altEpisode[iState::COLUMN_GUIDS][Guid::GUID_ANIDB] = '6601';
        foreach ($altEpisode[iState::COLUMN_META_DATA] as $backend => $metadata) {
            $altEpisode[iState::COLUMN_META_DATA][$backend][iState::COLUMN_META_DATA_EXTRA][iState::COLUMN_META_DATA_EXTRA_TITLE] =
                'Different Episode Title';
            $altEpisode[iState::COLUMN_META_DATA][$backend][iState::COLUMN_ID] =
                ($metadata[iState::COLUMN_ID] ?? 0) + 100;
        }

        $altEpisodeEntity = $this->db->insert(new StateEntity($altEpisode));

        return [$episode, $movie, $altEpisodeEntity];
    }

    public function test_insert_existing_throws(): void
    {
        $this->checkException(
            closure: function () {
                $item = new StateEntity($this->testEpisode);
                $this->db->insert($item);
                $this->db->insert($item);
            },
            reason: 'When inserting item with id, an exception should be thrown.',
            exception: DBException::class,
            exceptionMessage: 'primary key already defined',
            exceptionCode: 21,
        );
    }

    public function test_insert_conditions(): void
    {
        $this->checkException(
            closure: function () {
                $item = new StateEntity($this->testEpisode);
                $item->type = 'invalid';
                $this->db->insert($item);
            },
            reason: 'When inserting item with id, an exception should be thrown.',
            exception: DBException::class,
            exceptionMessage: 'Unexpected content type',
            exceptionCode: 22,
        );
        $this->checkException(
            closure: function () {
                $item = new StateEntity($this->testEpisode);
                $item->episode = 0;
                $this->db->insert($item);
            },
            reason: 'When inserting episode item with episode number 0, an exception should be thrown.',
            exception: DBException::class,
            exceptionMessage: 'Unexpected episode number',
        );
    }

    public function test_insert_successful(): void
    {
        $item = new StateEntity($this->testEpisode);
        $item->created_at = 0;
        $item->updated_at = 0;
        $item->watched = 0;

        $item = $this->db->insert($item);
        $this->assertSame(1, $item->id, 'When inserting new item, id is set to 1 when db is empty.');

        $item = new StateEntity($this->testMovie);
        $item->created_at = 0;
        $item->updated_at = 0;
        $item->watched = 0;
        $item->setMetadata([
            iState::COLUMN_META_DATA_PLAYED_AT => null,
        ]);

        $item = $this->db->insert($item);
        $this->assertSame(2, $item->id, 'When inserting new item, id is set to 1 when db is empty.');
    }

    public function test_get_conditions(): void
    {
        $test = $this->testEpisode;

        foreach (iState::ENTITY_ARRAY_KEYS as $key) {
            if (null === ($test[$key] ?? null)) {
                continue;
            }
            ksort($test[$key]);
        }

        $item = new StateEntity($test);

        // -- db should be empty at this stage. as such we expect null.
        $this->assertNull($this->db->get($item), 'When db is empty, get returns null.');

        // -- insert and return object and assert it's the same
        $modified = $this->db->insert(clone $item);

        $this->assertSame(
            $modified->getAll(),
            $this->db->get($item)->getAll(),
            'When db is not empty, get returns object.'
        );

        // -- look up based on id
        $this->assertSame(
            $modified->getAll(),
            $this->db->get($modified)->getAll(),
            'Look up db using id pointer and return object.'
        );
    }

    public function test_getAll_conditions(): void
    {
        $item = new StateEntity($this->testEpisode);

        $this->assertSame(
            [],
            $this->db->getAll(opts: ['class' => $item]),
            'When db is empty, getAll returns empty array.'
        );

        $this->db->insert($item);

        $this->assertCount(
            1,
            $this->db->getAll(opts: ['class' => $item]),
            'When db is not empty, objects returned.'
        );

        $this->assertCount(
            0,
            $this->db->getAll(date: new DateTimeImmutable('now'), opts: ['class' => $item]),
            'When db is not empty, And date condition is not met. empty array should be returned.'
        );
    }

    public function test_update_fail_conditions(): void
    {
        $this->checkException(
            closure: fn() => $this->db->update(new StateEntity($this->testEpisode)),
            reason: 'When updating item without id, an exception should be thrown.',
            exception: DBException::class,
            exceptionMessage: 'without primary key',
            exceptionCode: 51,
        );

        $this->checkException(
            closure: function () {
                $item = new StateEntity($this->testEpisode);
                $this->db->insert($item);
                $item->episode = 0;
                $this->db->update($item);
            },
            reason: 'When inserting episode item with episode number 0, an exception should be thrown.',
            exception: DBException::class,
            exceptionMessage: 'Unexpected episode number',
        );
    }

    public function test_update_conditions(): void
    {
        $test = $this->testEpisode;

        foreach (iState::ENTITY_ARRAY_KEYS as $key) {
            if (null === ($test[$key] ?? null)) {
                continue;
            }
            ksort($test[$key]);
        }

        $item = $this->db->insert(new StateEntity($test));
        $item->guids[Guid::GUID_IMDB] = '6101';

        $updatedItem = $this->db->update($item);

        $this->assertSame($item, $updatedItem, 'When updating item, same object is returned.');

        $r = $this->db->get($item)->getAll();
        $updatedItem->updated_at = $r[iState::COLUMN_UPDATED_AT];
        $this->assertSame(
            $updatedItem->getAll(),
            $r,
            'When updating item, getAll should return same values as the recorded item.'
        );

        $updatedItem->watched = 0;
        $item->setMetadata([
            iState::COLUMN_META_DATA_PLAYED_AT => null,
        ]);
        $item = $this->db->update($item);

        $this->assertNull(
            ag($item->getMetadata($item->via), iState::COLUMN_META_DATA_PLAYED_AT),
            'When watched flag is set to 0, played_at metadata should be null.'
        );
    }

    public function test_insert_lock_fast(): void
    {
        $logger = new Logger('logger');
        $this->initTempDir();
        [$db, , $path] = $this->createFileDb($logger, self::$tmpPath . '/lock-insert.db');
        $db->setOptions([
            Options::DEBUG_TRACE => true,
            'class' => new StateEntity([]),
        ]);
        $db->setLogger($logger);

        $lock = $this->openSqliteFile($path);
        $lock->exec('BEGIN EXCLUSIVE');

        try {
            $this->checkException(
                closure: fn() => $db->insert(new StateEntity($this->testEpisode), [
                    Options::FAIL_FAST_ON_LOCK => true,
                    'max_sleep' => 0,
                ]),
                reason: 'Fail-fast lock errors should bubble out of insert.',
                exception: DBLayerException::class,
                exceptionMessage: 'database is locked',
            );
        } finally {
            if (true === $lock->inTransaction()) {
                $lock->rollBack();
            }
        }
    }

    public function test_update_lock_fast(): void
    {
        $logger = new Logger('logger');
        $this->initTempDir();
        [$db, , $path] = $this->createFileDb($logger, self::$tmpPath . '/lock-update.db');
        $db->setOptions([
            Options::DEBUG_TRACE => true,
            'class' => new StateEntity([]),
        ]);
        $db->setLogger($logger);

        $item = $db->insert(new StateEntity($this->testEpisode));
        $lock = $this->openSqliteFile($path);
        $lock->exec('BEGIN EXCLUSIVE');

        try {
            $this->checkException(
                closure: fn() => $db->update($item, [
                    Options::FAIL_FAST_ON_LOCK => true,
                    'max_sleep' => 0,
                ]),
                reason: 'Fail-fast lock errors should bubble out of update.',
                exception: DBLayerException::class,
                exceptionMessage: 'database is locked',
            );
        } finally {
            if (true === $lock->inTransaction()) {
                $lock->rollBack();
            }
        }
    }

    public function test_insert_lock_logs_structured_failure(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('logger', [$handler]);
        $this->initTempDir();
        [$db, , $path] = $this->createFileDb($logger, self::$tmpPath . '/lock-logged-insert.db');
        $db->setOptions([
            Options::DEBUG_TRACE => true,
            'class' => new StateEntity([]),
        ]);
        $db->setLogger($logger);

        $lock = $this->openSqliteFile($path);
        $lock->exec('BEGIN EXCLUSIVE');

        try {
            $entity = $db->insert(new StateEntity($this->testEpisode), ['max_sleep' => 0]);

            self::assertNull($entity->id, 'Non-transaction insert failures should keep behavior and return the unsaved entity.');
            self::assertTrue($handler->hasErrorRecords(), 'Insert lock failures should be logged.');

            $records = $handler->getRecords();
            $record = end($records);
            self::assertSame('database.state.operation_failed', $record->context['event_name'] ?? null);
            self::assertSame('insert', $record->context['operation'] ?? null);
            self::assertSame('episode', $record->context['item_type'] ?? null);
        } finally {
            if (true === $lock->inTransaction()) {
                $lock->rollBack();
            }
        }
    }

    public function test_duplicates_uses_cache(): void
    {
        $cache = $this->makeCacheStub();

        $path = '/library/series/season1/episode02.mkv';

        $episode = $this->testEpisode;
        foreach ($episode[iState::COLUMN_META_DATA] as $backend => $metadata) {
            $episode[iState::COLUMN_META_DATA][$backend][iState::COLUMN_META_PATH] = $path;
        }

        $movie = $this->testMovie;
        foreach ($movie[iState::COLUMN_META_DATA] as $backend => $metadata) {
            $movie[iState::COLUMN_META_DATA][$backend][iState::COLUMN_META_PATH] = $path;
        }

        $episodeEntity = $this->db->insert(new StateEntity($episode));
        $movieEntity = $this->db->insert(new StateEntity($movie));

        $this->assertSame(0, $cache->setCalls, 'Cache should not be written before duplicates run.');

        $first = $this->db->duplicates($episodeEntity, $cache);

        $this->assertCount(2, $first, 'Duplicates should return all entities that share the same media path.');
        $this->assertSame(1, $cache->getCalls, 'Cache should be checked once per duplicates call.');
        $this->assertSame(1, $cache->setCalls, 'First duplicates run should populate the cache.');

        $second = $this->db->duplicates($episodeEntity, $cache);

        $this->assertCount(2, $second, 'Cached duplicates result should include the same entities.');
        $this->assertSame(2, $cache->getCalls, 'Second duplicates call should hit the cache again.');
        $this->assertSame(1, $cache->setCalls, 'Cached result should prevent additional writes.');
        $this->assertArrayHasKey(
            $episodeEntity->id,
            $second,
            'Episode record should remain present in cached duplicates result.'
        );
        $this->assertArrayHasKey(
            $movieEntity->id,
            $second,
            'Related movie record should remain present in cached duplicates result.'
        );
    }

    public function test_fetch_returns_all_entities(): void
    {
        $entities = $this->seedEntities();

        $items = iterator_to_array($this->db->fetch(), false);

        $this->assertCount(3, $items, 'Fetch should yield each inserted entity.');
        $this->assertContainsOnlyInstancesOf(iState::class, $items, 'Fetch should yield instances of StateInterface.');

        $expectedIds = array_map(fn(iState $entity) => $entity->id, $entities);
        $fetchedIds = array_map(fn(iState $entity) => $entity->id, $items);

        sort($expectedIds);
        sort($fetchedIds);

        $this->assertSame(
            $expectedIds,
            $fetchedIds,
            'Fetch should yield the same set of IDs that were inserted.'
        );
    }

    public function test_getTotal_returns_record_count(): void
    {
        $this->seedEntities();

        $this->assertSame(3, $this->db->getTotal(), 'getTotal should return number of rows in state table.');
    }

    public function test_remove_conditions(): void
    {
        $item1 = new StateEntity($this->testEpisode);
        $item2 = new StateEntity($this->testMovie);
        $item3 = new StateEntity([]);

        $this->assertFalse($this->db->remove($item1), 'When db is empty, remove returns false.');

        $item1 = $this->db->insert($item1);
        $this->db->insert($item2);

        $this->assertTrue(
            $this->db->remove($item1),
            'When db is not empty, remove returns true if record removed.'
        );
        $this->assertInstanceOf(
            iState::class,
            $this->db->get($item2),
            'When Record exists an instance of StateInterface is returned.'
        );

        $this->assertNull(
            $this->db->get($item3),
            'When Record does not exists a null is returned.'
        );

        // -- remove without id pointer.
        $this->assertTrue(
            $this->db->remove($item2),
            'If record does not have id but have pointers resolve it in db and remove it, and return true.'
        );

        $this->assertFalse(
            $this->db->remove($item3),
            'If record does not have id and/or pointers, return false.'
        );

        $item1 = new StateEntity($this->testEpisode);
        $this->db->insert($item1);
        $this->assertTrue(
            $this->db->remove(new StateEntity($this->testEpisode)),
            'When removing item with id, return true.'
        );
    }

    public function test_commit_conditions(): void
    {
        $item1 = new StateEntity($this->testEpisode);
        $item2 = new StateEntity($this->testMovie);

        $this->assertSame(
            ['added' => 2, 'updated' => 0, 'failed' => 0],
            $this->db->commit([$item1, $item2]),
            'Array<added, updated, failed> with count of each operation status.'
        );

        $item1->guids['guid_anidb'] = iState::TYPE_EPISODE . '/1';
        $item2->guids['guid_anidb'] = iState::TYPE_MOVIE . '/1';

        $this->assertSame(
            ['added' => 0, 'updated' => 2, 'failed' => 0],
            $this->db->commit([$item1, $item2]),
            'Array<added, updated, failed> with count of each operation status.'
        );
    }

    public function test_find(): void
    {
        $item1 = new StateEntity($this->testEpisode);
        $item2 = new StateEntity($this->testMovie);
        $this->db->insert($item1);
        $this->db->insert($item2);

        $items = $this->db->find($item1, $item2, new StateEntity([]));

        $this->assertCount(2, $items, 'Only items that are found should be returned.');
        $this->assertSame($item1->id, array_values($items)[0]->id, 'When items are found, they should be returned.');
        $this->assertSame($item2->id, array_values($items)[1]->id, 'When items are found, they should be returned.');
    }

    public function test_findByBackendId(): void
    {
        $this->db->setOptions(['class' => new StateEntity([])]);

        $item1 = new StateEntity($this->testEpisode);
        $item2 = new StateEntity($this->testMovie);
        $this->db->insert($item1);
        $this->db->insert($item2);

        $item1_db = $this->db->findByBackendId(
            $item1->via,
            ag($item1->getMetadata($item1->via), iState::COLUMN_ID),
            $item1->type,
        );

        $this->assertCount(0, $item1_db->apply($item1)->diff(), 'When item is found, it should be returned.');
        $this->assertNull(
            $this->db->findByBackendId('not_set', 0, 'movie'),
            'When item is not found, null should be returned.'
        );

        $item2_db = $this->db->findByBackendId(
            $item2->via,
            ag($item2->getMetadata($item2->via), iState::COLUMN_ID),
            $item2->type,
        );

        $this->assertCount(0, $item2_db->apply($item2)->diff(), 'When item is found, it should be returned.');
    }

    public function test_indexer_manages_external_indexes(): void
    {
        $originalConfig = Config::getAll();
        Config::init(require ROOT_PATH . '/config/config.php');
        Config::save('servers', [
            'test_plex' => [],
            'test_jellyfin' => [],
        ]);

        $pdo = new PDO('sqlite::memory:');
        $db = new PDOAdapter(new Logger('logger'), new DBLayer($pdo));
        $migrations = new PackageMigrationFactory();
        $expected = $this->expectedManagedIndexes(['test_plex', 'test_jellyfin']);

        try {
            if (false === $migrations->isMigrated($pdo)) {
                $migrations->migrate($pdo, dryRun: false);
            }

            self::assertSame([], $this->stateManagedIndexes($pdo));
            $logger = new Logger('logger');

            self::assertTrue(ensure_indexes($db->getDBLayer(), $logger));
            self::assertSame($expected, $this->stateManagedIndexes($pdo));

            self::assertTrue(ensure_indexes($db->getDBLayer(), $logger));
            self::assertSame($expected, $this->stateManagedIndexes($pdo));

            self::assertTrue(ensure_indexes($db->getDBLayer(), $logger, ['force-reindex' => true]));
            self::assertSame($expected, $this->stateManagedIndexes($pdo));
        } finally {
            Config::init($originalConfig);
        }
    }

    public function test_reset_clears_rows_sequence(): void
    {
        $this->seedEntities();

        $this->assertTrue($this->db->reset(), 'Reset should succeed for a migrated sqlite database.');
        $this->assertSame(0, $this->db->getTotal(), 'Reset should remove every row from the state table.');

        $inserted = $this->db->insert(new StateEntity($this->testEpisode));

        $this->assertSame(1, $inserted->id, 'Reset should also clear the sqlite autoincrement sequence.');
    }

    public function test_reset_keeps_migration_metadata(): void
    {
        $this->seedEntities();

        $this->assertTrue($this->db->reset(), 'Reset should succeed for a migrated sqlite database.');

        $pdo = $this->db->getDBLayer()->getBackend();
        $migrations = new PackageMigrationFactory();

        self::assertTrue($migrations->isMigrated($pdo), 'Reset should preserve package migration state.');
        self::assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM migration_version')?->fetchColumn());
        self::assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM migration_lock')?->fetchColumn());
    }

    public function test_transaction()
    {
        $this->db->getDBLayer()->transactional(function () {
            $this->checkException(
                closure: function () {
                    return $this->db->transactional(fn() => throw new \PDOException('test', 11));
                },
                reason: 'If we started transaction from outside the db, it shouldn\'t swallow the exception.',
                exception: DBLayerException::class,
                exceptionMessage: 'test',
                exceptionCode: 11,
            );
        });

        $this->db->transactional(fn($db) => $db->insert(new StateEntity($this->testEpisode)));

        $this->checkException(
            closure: function () {
                return $this->db->transactional(fn() => throw new \PDOException('test', 11));
            },
            reason: 'The exception should be thrown after rollback.',
            exception: \PDOException::class,
            exceptionMessage: 'test',
            exceptionCode: 11,
        );
    }

    public function test_destruct_no_commit(): void
    {
        $logger = new Logger('logger');
        [$db, , $path] = $this->createFileDb($logger);
        $db->setOptions([
            Options::DEBUG_TRACE => true,
            'class' => new StateEntity([]),
        ]);
        $db->setLogger($logger);

        $db->getDBLayer()->getBackend()->beginTransaction();
        $db->insert(new StateEntity($this->testEpisode));

        $verify = $this->openSqliteFile($path);

        self::assertSame(
            0,
            (int) $verify->query('SELECT COUNT(*) FROM state')->fetchColumn(),
            'Uncommitted adapter writes should not be visible to another connection.'
        );

        unset($db);

        self::assertSame(
            0,
            (int) $verify->query('SELECT COUNT(*) FROM state')->fetchColumn(),
            'Destroying adapter should not auto-commit an open transaction.'
        );
    }

    /**
     * @param array<int,string> $backends
     *
     * @return array<int,string>
     */
    private function expectedManagedIndexes(array $backends): array
    {
        $indexes = [];

        foreach (array_keys(Guid::getSupported()) as $subKey) {
            foreach ([iState::COLUMN_PARENT, iState::COLUMN_GUIDS] as $column) {
                $indexes[] = sprintf('state_%s_%s', $column, $subKey);
            }
        }

        foreach ($backends as $backend) {
            foreach ([iState::COLUMN_ID, iState::COLUMN_META_SHOW, iState::COLUMN_META_LIBRARY, iState::COLUMN_META_MULTI] as $subKey) {
                $indexes[] = sprintf('state_%s_%s_%s', iState::COLUMN_META_DATA, $backend, $subKey);
            }
        }

        sort($indexes);

        return $indexes;
    }

    /**
     * @return array<int,string>
     */
    private function stateManagedIndexes(PDO $pdo): array
    {
        $indexes = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'state' ORDER BY name"
        )?->fetchAll(PDO::FETCH_COLUMN);

        $managed = array_values(array_filter(
            false === is_array($indexes) ? [] : $indexes,
            fn(mixed $name): bool => true === is_string($name) && (
                str_starts_with($name, 'state_parent_')
                || str_starts_with($name, 'state_guids_')
                || str_starts_with($name, 'state_metadata_')
            ),
        ));

        sort($managed);

        return $managed;
    }

}
