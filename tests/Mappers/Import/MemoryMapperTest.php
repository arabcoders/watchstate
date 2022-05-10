<?php

declare(strict_types=1);

namespace Tests\Mappers\Import;

use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use App\Libs\Extends\CliLogger;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\Storage\PDO\PDOAdapter;
use App\Libs\Storage\StorageInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class MemoryMapperTest extends TestCase
{
    private array $testMovie = [];
    private array $testEpisode = [];

    private MemoryMapper|null $mapper = null;
    private StorageInterface|null $storage = null;

    public function setUp(): void
    {
        $this->output = new NullOutput();
        $this->input = new ArrayInput([]);

        $this->testMovie = require __DIR__ . '/../../Fixtures/MovieEntity.php';
        $this->testEpisode = require __DIR__ . '/../../Fixtures/EpisodeEntity.php';

        $logger = new CliLogger($this->output);

        $this->storage = new PDOAdapter($logger, new PDO('sqlite::memory:'));
        $this->storage->migrations('up');

        $this->mapper = new MemoryMapper($logger, $this->storage);
        $this->mapper->setUp(['class' => new StateEntity([])]);
    }

    public function test_loadData_null_date_conditions(): void
    {
        $testEpisode = new StateEntity($this->testEpisode);
        $testMovie = new StateEntity($this->testMovie);

        // -- expect 0 as we have not modified or added new item yet.
        $this->assertSame(0, $this->mapper->getObjectsCount());

        $this->storage->commit([$testEpisode, $testMovie]);

        $this->mapper->loadData();

        $this->assertSame(2, $this->mapper->getObjectsCount());
    }

    public function test_loadData_date_conditions(): void
    {
        $time = time();

        $this->testEpisode['updated'] = $time;

        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

        // -- expect 0 as we have not modified or added new item yet.
        $this->assertSame(0, $this->mapper->getObjectsCount());

        $this->storage->commit([$testEpisode, $testMovie]);

        $this->mapper->loadData(makeDate($time - 1));

        $this->assertSame(1, $this->mapper->getObjectsCount());
    }

    public function test_add_conditions(): void
    {
        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

        // -- expect 0 as we have not modified or added new item yet.
        $this->assertCount(0, $this->mapper);

        $this->mapper->add('test', 'test1', $testEpisode)->add('test', 'test2', $testMovie);

        $this->assertCount(2, $this->mapper);

        $this->assertSame(
            [
                StateInterface::TYPE_MOVIE => ['added' => 1, 'updated' => 0, 'failed' => 0],
                StateInterface::TYPE_EPISODE => ['added' => 1, 'updated' => 0, 'failed' => 0],
            ],
            $this->mapper->commit()
        );

        // -- assert 0 as we have committed the changes to the db, and the state should have been reset.
        $this->assertCount(0, $this->mapper);

        $testEpisode->guids['guid_tvrage'] = '2';

        $this->mapper->add('test', 'test1', $testEpisode);

        $this->assertCount(1, $this->mapper);

        $this->assertSame(
            [
                StateInterface::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
                StateInterface::TYPE_EPISODE => ['added' => 0, 'updated' => 1, 'failed' => 0],
            ],
            $this->mapper->commit()
        );

        $this->assertCount(0, $this->mapper);
    }

    public function test_get_conditions(): void
    {
        $movie = $this->testMovie;
        $episode = $this->testEpisode;

        ksort($movie['parent']);
        ksort($movie['guids']);
        ksort($movie['extra']);

        ksort($episode['parent']);
        ksort($episode['guids']);
        ksort($episode['extra']);

        $testMovie = new StateEntity($movie);
        $testEpisode = new StateEntity($episode);

        // -- expect null as we haven't added anything to db yet.
        $this->assertNull($this->mapper->get($testEpisode));

        $this->storage->commit([$testEpisode, $testMovie]);

        clone $testMovie2 = $testMovie;
        clone $testEpisode2 = $testEpisode;
        $testMovie2->id = 2;
        $testEpisode2->id = 1;

        $this->assertSame($testEpisode2->getAll(), $this->mapper->get($testEpisode)->getAll());
        $this->assertSame($testMovie2->getAll(), $this->mapper->get($testMovie)->getAll());
    }

    public function test_get_fully_loaded_conditions(): void
    {
        $time = time();

        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);
        $testEpisode->updated = $time;

        $this->mapper->loadData();

        $this->storage->commit([$testEpisode, $testMovie]);

        $this->assertNull($this->mapper->get($testMovie));
        $this->assertNull($this->mapper->get($testEpisode));

        $this->mapper->loadData(makeDate($time - 1));
        $this->assertInstanceOf(StateInterface::class, $this->mapper->get($testEpisode));
    }

    public function test_commit_conditions(): void
    {
        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

        $this->mapper->add('test', 'movie', $testMovie)
            ->add('test', 'episode', $testEpisode);

        $this->assertSame(
            [
                StateInterface::TYPE_MOVIE => ['added' => 1, 'updated' => 0, 'failed' => 0],
                StateInterface::TYPE_EPISODE => ['added' => 1, 'updated' => 0, 'failed' => 0],
            ],
            $this->mapper->commit()
        );

        $testMovie->guids['guid_anidb'] = '1';
        $testEpisode->guids['guid_anidb'] = '1';

        $this->assertSame(
            [
                StateInterface::TYPE_MOVIE => ['added' => 0, 'updated' => 1, 'failed' => 0],
                StateInterface::TYPE_EPISODE => ['added' => 0, 'updated' => 1, 'failed' => 0],
            ],
            $this->mapper->add('test', 'movie', $testMovie)->add('test', 'episode', $testEpisode)->commit()
        );
    }

    public function test_remove_conditions(): void
    {
        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

        $this->assertFalse($this->mapper->remove($testEpisode));
        $this->mapper->add('test', 'episode', $testEpisode)->add('test', 'movie', $testMovie)->commit();
        $this->assertTrue($this->mapper->remove($testEpisode));
    }

    public function test_has_conditions(): void
    {
        $testEpisode = new StateEntity($this->testEpisode);
        $this->assertFalse($this->mapper->has($testEpisode));
        $this->storage->commit([$testEpisode]);
        $this->assertTrue($this->mapper->has($testEpisode));
    }

    public function test_has_fully_loaded_conditions(): void
    {
        $time = time();

        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);
        $testEpisode->updated = $time;

        $this->mapper->loadData();
        $this->storage->commit([$testEpisode, $testMovie]);
        $this->assertFalse($this->mapper->has($testEpisode));
        $this->mapper->loadData(makeDate($time - 1));
        $this->assertTrue($this->mapper->has($testEpisode));
    }

    public function test_reset_conditions(): void
    {
        $testEpisode = new StateEntity($this->testEpisode);
        $this->assertCount(0, $this->mapper);

        $this->mapper->add('test', 'episode', $testEpisode);
        $this->assertCount(1, $this->mapper);

        $this->mapper->reset();
        $this->assertCount(0, $this->mapper);
    }

    public function test_getObjects_conditions(): void
    {
        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

        $this->assertCount(0, $this->mapper->getObjects());

        $this->storage->commit([$testMovie, $testEpisode]);

        $this->mapper->loadData();

        $this->assertCount(2, $this->mapper->getObjects());
        $this->assertCount(0, $this->mapper->reset()->getObjects());
    }

}
