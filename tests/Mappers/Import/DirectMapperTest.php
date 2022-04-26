<?php

declare(strict_types=1);

namespace Tests\Mappers\Import;

use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use App\Libs\Extends\CliLogger;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Storage\PDO\PDOAdapter;
use App\Libs\Storage\StorageInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class DirectMapperTest extends TestCase
{
    private array $testMovie = [];
    private array $testEpisode = [];

    private DirectMapper|null $mapper = null;
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

        $this->mapper = new DirectMapper($logger, $this->storage);
        $this->mapper->setUp(['class' => new StateEntity([])]);
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

        $testEpisode->guid_tvrage = StateInterface::TYPE_EPISODE . '/2';

        $this->mapper->add('test', 'test1', $testEpisode);

        $this->assertCount(1, $this->mapper);
    }

    public function test_get_conditions(): void
    {
        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

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

    public function test_remove_conditions(): void
    {
        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

        $this->assertFalse($this->mapper->remove($testEpisode));
        $this->mapper->add('test', 'episode', $testEpisode)->add('test', 'movie', $testMovie)->commit();
        $this->assertTrue($this->mapper->remove($testEpisode));
    }

    public function test_commit_conditions(): void
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

        $this->assertSame(
            [
                StateInterface::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
                StateInterface::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
            ],
            $this->mapper->commit()
        );

        $testEpisode->guid_tvrage = StateInterface::TYPE_EPISODE . '/1';
        $testMovie->guid_tvrage = StateInterface::TYPE_MOVIE . '/1';

        $this->mapper->add('test', 'test1', $testEpisode)->add('test', 'test2', $testMovie);

        $this->assertSame(
            [
                StateInterface::TYPE_MOVIE => ['added' => 0, 'updated' => 1, 'failed' => 0],
                StateInterface::TYPE_EPISODE => ['added' => 0, 'updated' => 1, 'failed' => 0],
            ],
            $this->mapper->commit()
        );
    }

    public function test_has_conditions(): void
    {
        $testEpisode = new StateEntity($this->testEpisode);
        $this->assertFalse($this->mapper->has($testEpisode));
        $this->storage->commit([$testEpisode]);
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
}
