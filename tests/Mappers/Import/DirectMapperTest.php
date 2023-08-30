<?php

declare(strict_types=1);

namespace Tests\Mappers\Import;

use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Message;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class DirectMapperTest extends TestCase
{
    private array $testMovie = [];
    private array $testEpisode = [];

    protected DirectMapper|null $mapper = null;
    protected iDB|null $db = null;
    protected TestHandler|null $handler = null;

    public function setUp(): void
    {
        $this->output = new NullOutput();
        $this->input = new ArrayInput([]);

        $this->testMovie = require __DIR__ . '/../../Fixtures/MovieEntity.php';
        $this->testEpisode = require __DIR__ . '/../../Fixtures/EpisodeEntity.php';

        $this->handler = new TestHandler();
        $logger = new Logger('logger');
        $logger->pushHandler($this->handler);
        Guid::setLogger($logger);

        $this->db = new PDOAdapter($logger, new PDO('sqlite::memory:'));
        $this->db->migrations('up');


        $this->mapper = new DirectMapper($logger, $this->db);
        $this->mapper->setOptions(options: ['class' => new StateEntity([])]);

        Message::reset();
    }

    public function test_add_conditions(): void
    {
        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

        // -- expect 0 as we have not modified or added new item yet.
        $this->assertCount(0, $this->mapper);

        $this->mapper->add($testEpisode)->add($testMovie);

        $this->assertCount(2, $this->mapper);

        $this->assertSame(
            [
                iState::TYPE_MOVIE => ['added' => 1, 'updated' => 0, 'failed' => 0],
                iState::TYPE_EPISODE => ['added' => 1, 'updated' => 0, 'failed' => 0],
            ],
            $this->mapper->commit()
        );

        // -- assert 0 as we have committed the changes to the db, and the state should have been reset.
        $this->assertCount(0, $this->mapper);

        $testEpisode->metadata['home_plex'][iState::COLUMN_GUIDS][Guid::GUID_TVRAGE] = '2';

        $this->mapper->add($testEpisode);

        $this->assertCount(1, $this->mapper);

        $this->assertSame(
            [
                iState::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
                iState::TYPE_EPISODE => ['added' => 0, 'updated' => 1, 'failed' => 0],
            ],
            $this->mapper->commit()
        );

        $this->assertCount(0, $this->mapper);
    }

    public function test_update_watch_conditions(): void
    {
        $this->testMovie[iState::COLUMN_WATCHED] = 0;
        $this->testMovie = ag_set($this->testMovie, 'metadata.home_plex.watched', 0);

        $testMovie = new StateEntity($this->testMovie);

        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();
        $obj = $this->mapper->get($testMovie);
        $this->assertSame(0, $obj->watched);
        $this->assertSame(1, $obj->updated);
        $this->assertSame(0, (int)ag($obj->getMetadata($testMovie->via), iState::COLUMN_WATCHED));

        $this->testMovie[iState::COLUMN_WATCHED] = 1;
        $this->testMovie[iState::COLUMN_UPDATED] = 10;
        $this->testMovie = ag_set($this->testMovie, 'metadata.home_plex.watched', 1);
        $this->testMovie = ag_set($this->testMovie, 'metadata.home_plex.played_at', 10);
        $testMovie = new StateEntity($this->testMovie);
        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();
        $obj = $this->mapper->get($testMovie);

        $this->assertSame(1, $testMovie->watched);
        $this->assertSame(1, $obj->watched);
        $this->assertSame(10, $obj->updated);
        $this->assertSame(1, (int)ag($obj->getMetadata($testMovie->via), iState::COLUMN_WATCHED));
        $this->assertSame(10, (int)ag($obj->getMetadata($testMovie->via), iState::COLUMN_META_DATA_PLAYED_AT));
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
        $objs = $this->mapper->getObjects();
        $obj = array_pop($objs);

        $this->assertSame(0, (int)$obj->watched);
        $this->assertSame($obj->updated, (int)$obj->updated);
        $this->assertSame(0, (int)ag($obj->getMetadata($testMovie->via), iState::COLUMN_WATCHED));
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

        $this->assertSame(1, $obj->watched);
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

        $this->assertSame(1, $obj->watched);
    }

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
        $this->assertNull($this->mapper->get($testEpisode));

        $this->db->commit([$testEpisode, $testMovie]);

        clone $testMovie2 = $testMovie;
        clone $testEpisode2 = $testEpisode;
        $testMovie2->id = 2;
        $testEpisode2->id = 1;

        $this->assertSame($testEpisode2->getAll(), $this->mapper->get($testEpisode)->getAll());
        $this->assertSame($testMovie2->getAll(), $this->mapper->get($testMovie)->getAll());
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
            $insert
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
            $updated
        );
    }

    public function test_remove_conditions(): void
    {
        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

        $this->assertFalse($this->mapper->remove($testEpisode));
        $this->mapper->add($testEpisode)->add($testMovie)->commit();
        $this->assertTrue($this->mapper->remove($testEpisode));
    }

    public function test_has_conditions(): void
    {
        $testEpisode = new StateEntity($this->testEpisode);
        $this->assertFalse($this->mapper->has($testEpisode));
        $this->db->commit([$testEpisode]);
        $this->assertTrue($this->mapper->has($testEpisode));
    }

    public function test_reset_conditions(): void
    {
        $testEpisode = new StateEntity($this->testEpisode);
        $this->assertCount(0, $this->mapper);

        $this->mapper->add($testEpisode);
        $this->assertCount(1, $this->mapper);

        $this->mapper->reset();
        $this->assertCount(0, $this->mapper);
    }

    public function test_getObjects_conditions(): void
    {
        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

        $this->assertCount(0, $this->mapper->getObjects());

        $this->mapper->add($testMovie)->add($testEpisode);

        $this->assertCount(2, $this->mapper->getObjects());
        $this->assertCount(0, $this->mapper->reset()->getObjects());
    }

}
