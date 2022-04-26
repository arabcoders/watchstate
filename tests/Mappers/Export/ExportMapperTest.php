<?php

declare(strict_types=1);

namespace Tests\Mappers\Export;

use App\Libs\Entity\StateEntity;
use App\Libs\Extends\CliLogger;
use App\Libs\Mappers\Export\ExportMapper;
use App\Libs\Storage\PDO\PDOAdapter;
use App\Libs\Storage\StorageInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ExportMapperTest extends TestCase
{
    private array $testMovie = [];
    private array $testEpisode = [];

    private ExportMapper|null $mapper = null;
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

        $this->mapper = new ExportMapper($this->storage);
        $this->mapper->setUp(['class' => new StateEntity([])]);
    }

    public function test_loadData_null_date_conditions(): void
    {
        $testEpisode = new StateEntity($this->testEpisode);
        $testMovie = new StateEntity($this->testMovie);

        // -- expect 0 as there is 0 items in db.
        $this->assertCount(0, $this->mapper->getObjects());

        $this->storage->commit([$testEpisode, $testMovie]);

        // -- fully load data.
        $this->mapper->loadData();

        $this->assertCount(2, $this->mapper->getObjects());
    }

    public function test_loadData_date_conditions(): void
    {
        $time = time();

        $this->testEpisode['updated'] = $time;

        $testMovie = new StateEntity($this->testMovie);
        $testEpisode = new StateEntity($this->testEpisode);

        // -- expect 0 as we have not modified or added new item yet.
        $this->assertCount(0, $this->mapper->getObjects());

        $this->storage->commit([$testEpisode, $testMovie]);

        $this->mapper->loadData(makeDate($time - 1));

        $this->assertCount(1, $this->mapper->getObjects());
    }

}
