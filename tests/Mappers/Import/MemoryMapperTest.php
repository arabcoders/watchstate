<?php

declare(strict_types=1);

namespace Tests\Mappers\Import;

use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use App\Libs\Extends\CliLogger;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\Storage\PDO\PDOAdapter;
use App\Libs\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class MemoryMapperTest extends TestCase
{
    private array $testEpisode = [
        'id' => null,
        'type' => StateInterface::TYPE_EPISODE,
        'updated' => 0,
        'watched' => 1,
        'meta' => [],
        'guid_plex' => StateInterface::TYPE_EPISODE . '/1',
        'guid_imdb' => StateInterface::TYPE_EPISODE . '/2',
        'guid_tvdb' => StateInterface::TYPE_EPISODE . '/3',
        'guid_tmdb' => StateInterface::TYPE_EPISODE . '/4',
        'guid_tvmaze' => StateInterface::TYPE_EPISODE . '/5',
        'guid_tvrage' => StateInterface::TYPE_EPISODE . '/6',
        'guid_anidb' => StateInterface::TYPE_EPISODE . '/7',
    ];

    private array $testMovie = [
        'id' => null,
        'type' => StateInterface::TYPE_MOVIE,
        'updated' => 1,
        'watched' => 1,
        'meta' => [],
        'guid_plex' => StateInterface::TYPE_MOVIE . '/10',
        'guid_imdb' => StateInterface::TYPE_MOVIE . '/20',
        'guid_tvdb' => StateInterface::TYPE_MOVIE . '/30',
        'guid_tmdb' => StateInterface::TYPE_MOVIE . '/40',
        'guid_tvmaze' => StateInterface::TYPE_MOVIE . '/50',
        'guid_tvrage' => StateInterface::TYPE_MOVIE . '/60',
        'guid_anidb' => StateInterface::TYPE_MOVIE . '/70',
    ];

    private MemoryMapper|null $mapper = null;
    private StorageInterface|null $storage = null;

    public function setUp(): void
    {
        $this->output = new NullOutput();
        $this->input = new ArrayInput([]);
        $logger = new CliLogger($this->output);

        $this->storage = new PDOAdapter($logger);
        $this->storage->setUp(['dsn' => 'sqlite::memory:']);
        $this->storage->migrations('up', $this->input, $this->output);

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

        $testEpisode->guid_tvrage = StateInterface::TYPE_EPISODE . '/2';

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
}
