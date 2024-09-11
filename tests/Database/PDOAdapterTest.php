<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\DBLayer;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\DBAdapterException as DBException;
use App\Libs\Guid;
use App\Libs\Options;
use App\Libs\TestCase;
use DateTimeImmutable;
use Error;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class PDOAdapterTest extends TestCase
{
    private array $testMovie = [];
    private array $testEpisode = [];

    private iDB|null $db = null;
    protected TestHandler|null $handler = null;
    protected OutputInterface|null $output = null;
    protected InputInterface|null $input = null;

    public function setUp(): void
    {
        $this->output = new NullOutput();
        $this->input = new ArrayInput([]);

        $this->testMovie = require __DIR__ . '/../Fixtures/MovieEntity.php';
        $this->testEpisode = require __DIR__ . '/../Fixtures/EpisodeEntity.php';

        $this->handler = new TestHandler();
        $logger = new Logger('logger');
        $logger->pushHandler($this->handler);
        Guid::setLogger($logger);

        $this->db = new PDOAdapter($logger, new DBLayer(new PDO('sqlite::memory:')));
        $this->db->setOptions([
            Options::DEBUG_TRACE => true,
            'class' => new StateEntity([]),
        ]);
        $this->db->setLogger($logger);
        $this->db->migrations('up');
    }

    public function test_insert_throw_exception_if_has_id(): void
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

    public function test_getAll_call_without_initialized_container(): void
    {
        $this->db->setOptions(['class' => null]);
        $this->checkException(
            closure: fn() => $this->db->getAll(),
            reason: 'When calling getAll without initialized container, an exception should be thrown.',
            exception: Error::class,
            exceptionMessage: 'Call to a member function',
        );
    }

    public function test_getAll_conditions(): void
    {
        $item = new StateEntity($this->testEpisode);

        $this->assertSame([],
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

    public function test_migrations_call_with_wrong_direction_exception(): void
    {
        $this->checkException(
            closure: fn() => $this->db->migrations('not_dd'),
            reason: 'When calling migrations with wrong direction, an exception should be thrown.',
            exception: DBException::class,
            exceptionMessage: 'Unknown migration direction',
            exceptionCode: 91,
        );
    }

    public function test_commit_transaction_on__destruct(): void
    {
        $started = $this->db->getDBLayer()->start();
        $this->assertTrue($started, 'Transaction should be started.');

        $this->db->getDBLayer()->transactional(function () {
            $this->db->insert(new StateEntity($this->testEpisode));
            $this->db->insert(new StateEntity($this->testMovie));
        }, auto: false);

        $this->assertTrue($this->db->getDBLayer()->inTransaction(), 'Transaction should be still open.');
        $this->db->__destruct();
        $this->assertFalse($this->db->getDBLayer()->inTransaction(), 'Transaction should be closed.');

        $this->assertCount(
            2,
            $this->db->getAll(),
            'When transaction is committed, records should be found in db.'
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
        Container::init();
        Container::add(iState::class, new StateEntity([]));

        $this->db->setOptions(['class' => null]);
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

        $this->db->setOptions(['class' => new StateEntity([])]);

        $item2_db = $this->db->findByBackendId(
            $item2->via,
            ag($item2->getMetadata($item2->via), iState::COLUMN_ID),
            $item2->type,
        );
        $this->assertCount(0, $item2_db->apply($item2)->diff(), 'When item is found, it should be returned.');
    }

    public function test_ensureIndex()
    {
        $this->assertTrue($this->db->ensureIndex(), 'When ensureIndex is called, it should return true.');
    }

    public function test_migrateData()
    {
        Config::init(require __DIR__ . '/../../config/config.php');
        $this->assertFalse(
            $this->db->migrateData(Config::get('database.version')),
            'At this point we are starting with new database, so migration should be false.'
        );
    }

    public function test_maintenance()
    {
        Config::init(require __DIR__ . '/../../config/config.php');
        $this->assertTrue(
            0 === $this->db->maintenance(),
            'At this point we are starting with new database, so maintenance should be false.'
        );
    }

    public function test_reset()
    {
        $this->assertTrue($this->db->reset(), 'When reset is called, it should return true. and reset the db.');
    }

    public function test_transaction()
    {
        $this->db->getDBLayer()->start();
        $this->checkException(
            closure: function () {
                return $this->db->transactional(fn() => throw new \PDOException('test', 11));
            },
            reason: 'If we started transaction from outside the db, it shouldn\'t swallow the exception.',
            exception: \PDOException::class,
            exceptionMessage: 'test',
            exceptionCode: 11,
        );
        $this->db->getDBLayer()->rollback();


        $this->db->getDBLayer()->start();
        $this->db->transactional(fn($db) => $db->insert(new StateEntity($this->testEpisode)));
        $this->db->getDBLayer()->commit();

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

    public function test_isMigrated()
    {
        Config::init(require __DIR__ . '/../../config/config.php');
        $db = new PDOAdapter(new Logger('logger'), new DBLayer(new PDO('sqlite::memory:')));
        $this->assertFalse(
            $db->isMigrated(),
            'At this point we are starting with new database, so migration should be false.'
        );
        $this->assertTrue(
            0 === $db->migrations('up'),
            'When migrations are run, it should return true.'
        );
        $this->assertTrue(
            $db->isMigrated(),
            'When migrations are run, it should return true.'
        );
    }
}
