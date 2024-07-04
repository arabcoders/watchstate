<?php

declare(strict_types=1);

namespace Tests\Mappers\Import;

use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\DatabaseException;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\Guid;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Message;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractTestsMapper extends TestCase
{
    protected array $testMovie = [];
    protected array $testEpisode = [];

    protected ImportInterface|null $mapper = null;
    protected iDB|null $db = null;
    protected TestHandler|null $handler = null;
    protected LoggerInterface|null $logger = null;
    protected OutputInterface|null $output = null;
    protected InputInterface|null $input = null;

    abstract protected function setupMapper(): ImportInterface;

    public function setUp(): void
    {
        $this->output = new NullOutput();
        $this->input = new ArrayInput([]);

        $this->testMovie = require __DIR__ . '/../../Fixtures/MovieEntity.php';
        $this->testEpisode = require __DIR__ . '/../../Fixtures/EpisodeEntity.php';

        $this->handler = new TestHandler();
        $this->logger = new Logger('logger', processors: [new LogMessageProcessor()]);
        $this->logger->pushHandler($this->handler);
        Guid::setLogger($this->logger);

        $this->db = new PDOAdapter($this->logger, new PDO('sqlite::memory:'));
        $this->db->migrations('up');

        $this->mapper = $this->setupMapper();

        Message::reset();
    }

    /**
     * @throws RandomException
     */
    public function test_loadData_null_date_conditions(): void
    {
        $testEpisode = new StateEntity($this->testEpisode);
        $testMovie = new StateEntity($this->testMovie);

        // -- expect 0 as we have not modified or added new item yet.
        $this->assertSame(
            0,
            $this->mapper->getObjectsCount(),
            'getObjectsCount() should return 0 as we have not modified or added new item yet.'
        );

        $this->db->commit([$testEpisode, $testMovie]);

        $this->mapper->loadData();

        $this->assertSame(
            2,
            $this->mapper->getObjectsCount(),
            'getObjectsCount() should return 2 as we have added 2 items to the db.'
        );
    }

    /**
     * @throws RandomException
     */
    public function test_loadData_date_conditions(): void
    {
        $time = time();

        $this->testEpisode[iState::COLUMN_UPDATED] = $time;

        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

        // -- expect 0 as we have not modified or added new item yet.
        $this->assertSame(
            0,
            $this->mapper->getObjectsCount(),
            'getObjectsCount() should return 0 as we have not modified or added new item yet.'
        );

        $this->db->commit([$testEpisode, $testMovie]);

        $this->mapper->loadData(makeDate($time - 1));

        $this->assertSame(
            1,
            $this->mapper->getObjectsCount(),
            'getObjectsCount() should return 1 as we have added 2 items to the db, but only 1 is newer than the date provided.'
        );
    }

    public function test_add_conditions(): void
    {
        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

        // -- expect 0 as we have not modified or added new item yet.
        $this->assertCount(
            0,
            $this->mapper,
            'Mapper should be empty as we have not modified or added new item yet.'
        );

        $this->mapper->add($testEpisode)->add($testMovie);

        $this->assertCount(
            2,
            $this->mapper,
            'Mapper should have 2 items as we have added 2 items to the mapper.'
        );

        $this->assertSame(
            [
                iState::TYPE_MOVIE => ['added' => 1, 'updated' => 0, 'failed' => 0],
                iState::TYPE_EPISODE => ['added' => 1, 'updated' => 0, 'failed' => 0],
            ],
            $this->mapper->commit(),
            'commit() should return an array with the correct counts in format of [movie => [added, updated, failed],movie => [added, updated, failed]].'
        );

        // -- assert 0 as we have committed the changes to the db, and the state should have been reset.
        $this->assertCount(
            0,
            $this->mapper,
            'Mapper should be empty as we have committed the changes to the db, and the state should have been reset.'
        );

        $testEpisode->metadata['home_plex'][iState::COLUMN_GUIDS][Guid::GUID_TVRAGE] = '2';

        $this->mapper->add($testEpisode);

        $this->assertCount(1, $this->mapper, 'Mapper should have 1 item as we have added 1 item to the mapper.');

        $this->assertSame(
            [
                iState::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
                iState::TYPE_EPISODE => ['added' => 0, 'updated' => 1, 'failed' => 0],
            ],
            $this->mapper->commit(),
            'commit() should return an array with the correct counts in format of [movie => [added, updated, failed],movie => [added, updated, failed]].'
        );

        $this->assertCount(0, $this->mapper);
    }

    public function test_update_watch_conditions(): void
    {
        // --prep.
        $this->testMovie[iState::COLUMN_WATCHED] = 0;
        $this->testMovie = ag_set($this->testMovie, 'metadata.home_plex.watched', 0);

        $testMovie = new StateEntity($this->testMovie);

        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();
        $obj = $this->mapper->get($testMovie);
        $this->assertSame(0, $obj->watched, 'watched should be 0');
        $this->assertSame(1, $obj->updated, 'updated should be 1');
        $this->assertSame(
            0,
            (int)ag($obj->getMetadata($testMovie->via), iState::COLUMN_WATCHED),
            'metadata.home_plex.watched should be 0'
        );

        // -- update

        $this->testMovie[iState::COLUMN_WATCHED] = 1;
        $this->testMovie[iState::COLUMN_UPDATED] = 5;
        $this->testMovie = ag_set($this->testMovie, 'metadata.home_plex.watched', 1);
        $this->testMovie = ag_set($this->testMovie, 'metadata.home_plex.played_at', 5);

        $testMovie = new StateEntity($this->testMovie);
        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();
        $obj = $this->mapper->get($testMovie);

        $this->assertSame(1, $testMovie->watched, 'watched should be 1');
        $this->assertSame(1, $obj->watched, 'watched should be 1');
        $this->assertSame(5, $obj->updated, 'updated should be 5');
        $this->assertSame(
            1,
            (int)ag($obj->getMetadata($testMovie->via), iState::COLUMN_WATCHED),
            'metadata.home_plex.watched should be 1'
        );
        $this->assertSame(
            5,
            (int)ag($obj->getMetadata($testMovie->via), iState::COLUMN_META_DATA_PLAYED_AT),
            'metadata.home_plex.played_at should be 5'
        );
    }

    public function test_update_unwatch_conditions(): void
    {
        $testMovie = new StateEntity($this->testMovie);

        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        $testMovie->watched = 0;
        $this->mapper->add($testMovie, ['after' => new \DateTimeImmutable('now')]);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();
        $obj = $this->mapper->get($testMovie);

        $this->assertSame(0, $obj->watched, 'watched should be 0');
        $this->assertSame($obj->updated, $obj->updated, 'updated should be 1');
        $this->assertSame(
            0,
            (int)ag($obj->getMetadata($testMovie->via), iState::COLUMN_WATCHED),
            'metadata.home_plex.watched should be 0'
        );
    }

    /**
     * @throws \Exception
     */
    public function test_update_unwatch_conflict_no_metadata(): void
    {
        $this->mapper->add(new StateEntity($this->testMovie));
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        $timeNow = time();

        $testData = $this->testMovie;
        $testData[iState::COLUMN_VIA] = 'fiz';
        $testData[iState::COLUMN_WATCHED] = 0;
        $testData[iState::COLUMN_UPDATED] = $timeNow;
        $testData[iState::COLUMN_META_DATA] = [
            'fiz' => [
                iState::COLUMN_ID => 121,
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_WATCHED => 0,
                iState::COLUMN_YEAR => '2020',
                iState::COLUMN_META_DATA_EXTRA => [
                    iState::COLUMN_META_DATA_EXTRA_DATE => '2020-01-03',
                ],
                iState::COLUMN_META_DATA_ADDED_AT => $timeNow,
            ],
        ];

        $testMovie = new StateEntity($testData);
        $this->mapper->add($testMovie, ['after' => new \DateTimeImmutable('@' . ($timeNow - 10))]);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();
        $obj = $this->mapper->get($testMovie);

        $this->assertTrue(
            $obj->isWatched(),
            'If implemented correctly, Mapper call to shouldMarkAsUnplayed() will fail due to missing metadata, and the play state should not change.'
        );
    }

    /**
     * @throws \Exception
     */
    public function test_update_unwatch_conflict_no_date(): void
    {
        $testData = $this->testMovie;
        $timeNow = time();

        $testData[iState::COLUMN_UPDATED] = $timeNow;
        $testData[iState::COLUMN_META_DATA]['home_plex'][iState::COLUMN_META_DATA_PLAYED_AT] = $timeNow;

        $movie = new StateEntity($testData);
        $this->mapper->add($movie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        $testData[iState::COLUMN_WATCHED] = 0;
        $testData[iState::COLUMN_UPDATED] = $timeNow;

        $testMovie = new StateEntity($testData);

        $this->mapper->add($testMovie, ['after' => new \DateTimeImmutable('@' . ($timeNow - 10))]);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();
        $obj = $this->mapper->get($testMovie);

        $this->assertTrue(
            $obj->isWatched(),
            'If implemented correctly, Mapper call to shouldMarkAsUnplayed() will fail due to missing date, and the play state should not change.'
        );
    }

    /**
     * @throws RandomException
     */
    public function test_get_conditions(): void
    {
        $movie = $this->testMovie;
        $episode = $this->testEpisode;

        foreach (iState::ENTITY_ARRAY_KEYS as $key) {
            if (null !== ($movie[$key] ?? null)) {
                ksort($movie[$key]);
            }
            if (null !== ($episode[$key] ?? null)) {
                ksort($episode[$key]);
            }
        }

        $testMovie = new StateEntity($movie);
        $testEpisode = new StateEntity($episode);

        // -- expect null as we haven't added anything to db yet.
        $this->assertNull(
            $this->mapper->get($testEpisode),
            'get() should return null as we haven\'t added anything to db yet.'
        );

        $this->db->commit([$testEpisode, $testMovie]);

        clone $testMovie2 = $testMovie;
        clone $testEpisode2 = $testEpisode;
        $testMovie2->id = 2;
        $testEpisode2->id = 1;

        $this->assertSame(
            $testEpisode2->getAll(),
            $this->mapper->get($testEpisode)->getAll(),
            'get() should return the correct data for the episode.'
        );
        $this->assertSame(
            $testMovie2->getAll(),
            $this->mapper->get($testMovie)->getAll(),
            'get() should return the correct data for the movie.'
        );
    }

    /**
     * @throws RandomException
     */
    public function test_get_fully_loaded_conditions(): void
    {
        $time = time();

        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);
        $testEpisode->updated = $time;

        $this->mapper->loadData();

        $this->db->commit([$testEpisode, $testMovie]);

        $this->assertNull(
            $this->mapper->get($testMovie),
            'get() should return null as load data was called with fully loaded.'
        );
        $this->assertNull(
            $this->mapper->get($testEpisode),
            'get() should return null as load data was called with fully loaded.'
        );

        $this->mapper->loadData(makeDate($time - 1));
        $this->assertInstanceOf(
            iState::class,
            $this->mapper->get($testEpisode),
            'get() should return the correct data as we called loadData with a date that is older than the updated date.'
        );
    }

    public function test_commit_conditions(): void
    {
        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

        $insert = $this->mapper
            ->add($testMovie)
            ->add($testEpisode)
            ->commit();

        $this->assertSame(
            [
                iState::TYPE_MOVIE => ['added' => 1, 'updated' => 0, 'failed' => 0],
                iState::TYPE_EPISODE => ['added' => 1, 'updated' => 0, 'failed' => 0],
            ],
            $insert,
            'commit() should return an array with the correct counts in format of [ movie => [ added => int, updated => int, failed => int ], episode => [ added => int, updated => int, failed => int ] ].'
        );

        $testMovie->metadata['home_plex'][iState::COLUMN_GUIDS][Guid::GUID_ANIDB] = '1920';
        $testEpisode->metadata['home_plex'][iState::COLUMN_GUIDS][Guid::GUID_ANIDB] = '1900';

        $this->mapper
            ->add($testMovie, ['diff_keys' => iState::ENTITY_KEYS])
            ->add($testEpisode, ['diff_keys' => iState::ENTITY_KEYS]);

        $updated = $this->mapper->commit();

        $this->assertSame(
            [
                iState::TYPE_MOVIE => ['added' => 0, 'updated' => 1, 'failed' => 0],
                iState::TYPE_EPISODE => ['added' => 0, 'updated' => 1, 'failed' => 0],
            ],
            $updated,
            'commit() should return an array with the correct counts in format of [ movie => [ added => int, updated => int, failed => int ], episode => [ added => int, updated => int, failed => int ] ].'
        );
    }

    public function test_remove_conditions(): void
    {
        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

        $this->assertFalse(
            $this->mapper->remove($testEpisode),
            'remove() should return false as as the object does not yet exists in db.'
        );
        $this->mapper->add($testEpisode)->add($testMovie)->commit();
        $this->assertTrue(
            $this->mapper->remove($testEpisode),
            'remove() should return true as as the object exists in db and was removed.'
        );
    }

    /**
     * @throws RandomException
     */
    public function test_has_conditions(): void
    {
        $testEpisode = new StateEntity($this->testEpisode);
        $this->assertFalse(
            $this->mapper->has($testEpisode),
            'has() should return false as the object does not exists db yet.'
        );
        $this->db->commit([$testEpisode]);
        $this->assertTrue(
            $this->mapper->has($testEpisode),
            'has() should return true as the object exists in db.'
        );
    }

    /**
     * @throws RandomException
     */
    public function test_has_fully_loaded_conditions(): void
    {
        $time = time();

        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);
        $testEpisode->updated = $time;

        $this->mapper->loadData();
        $this->db->commit([$testEpisode, $testMovie]);
        $this->assertFalse(
            $this->mapper->has($testEpisode),
            'has() should return false as loadData was called before inserting the records into db.'
        );
        $this->mapper->loadData(makeDate($time - 1));
        $this->assertTrue(
            $this->mapper->has($testEpisode),
            'has() should return true as loadData was called with a date that is older than the entity updated'
        );
    }

    public function test_reset_conditions(): void
    {
        $testEpisode = new StateEntity($this->testEpisode);
        $this->assertCount(0, $this->mapper, 'Mapper should be empty as we have not added new item yet.');

        $this->mapper->add($testEpisode);
        $this->assertCount(1, $this->mapper, 'Mapper should have 1 item as we have added 1 item to the mapper.');

        $this->mapper->reset();
        $this->assertCount(0, $this->mapper, 'Mapper should be empty as we have called reset on the mapper.');
    }

    /**
     * @throws RandomException
     */
    public function test_getObjects_conditions(): void
    {
        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

        $this->assertCount(
            0,
            $this->mapper->getObjects(),
            'getObjects() should return 0 as we have not added items yet.'
        );

        $this->db->commit([$testMovie, $testEpisode]);

        $this->mapper->loadData();

        $this->assertCount(2, $this->mapper->getObjects(), 'getObjects() should return 2 as we have added 2 items.');
        $this->assertCount(
            0,
            $this->mapper->reset()->getObjects(),
            'getObjects() should return 0 as we have called reset on the mapper.'
        );
    }

    /**
     * @throws RandomException
     */
    public function test_commit_with_no_episode_number(): void
    {
        $testEpisode = new StateEntity($this->testEpisode);
        $testEpisode->episode = 0;
        $this->expectException(DatabaseException::class);
        $this->db->commit([$testEpisode]);
    }

    /**
     * @throws RandomException
     */
    public function test_insert_with_no_episode_number(): void
    {
        $testEpisode = new StateEntity($this->testEpisode);
        $testEpisode->episode = 0;
        $this->expectException(DatabaseException::class);
        $this->db->insert($testEpisode);
    }

    /**
     * @throws RandomException
     */
    public function test_update_with_no_episode_number(): void
    {
        $testEpisode = new StateEntity($this->testEpisode);
        $testEpisode->episode = 0;
        $this->expectException(DatabaseException::class);
        $this->db->update($testEpisode);
    }

    public function test_mapper_add_with_no_episode_number(): void
    {
        $testEpisode = new StateEntity($this->testEpisode);
        $testEpisode->episode = 0;
        $this->mapper->add($testEpisode);
        $this->mapper->setLogger($this->logger);

        $this->assertSame(
            0,
            $this->mapper->getObjectsCount(),
            "getObjectsCount() should return 0 as as the episode number is 0 and shouldn't be processed."
        );
    }
}
