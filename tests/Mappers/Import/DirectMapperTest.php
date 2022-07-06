<?php

declare(strict_types=1);

namespace Tests\Mappers\Import;

use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Message;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;
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
                iFace::TYPE_MOVIE => ['added' => 1, 'updated' => 0, 'failed' => 0],
                iFace::TYPE_EPISODE => ['added' => 1, 'updated' => 0, 'failed' => 0],
            ],
            $this->mapper->commit()
        );

        // -- assert 0 as we have committed the changes to the db, and the state should have been reset.
        $this->assertCount(0, $this->mapper);

        $testEpisode->metadata['home_plex'][iFace::COLUMN_GUIDS][Guid::GUID_TVRAGE] = '2';

        $this->mapper->add($testEpisode);

        $this->assertCount(1, $this->mapper);

        $this->assertSame(
            [
                iFace::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
                iFace::TYPE_EPISODE => ['added' => 0, 'updated' => 1, 'failed' => 0],
            ],
            $this->mapper->commit()
        );

        $this->assertCount(0, $this->mapper);
    }

    public function test_get_conditions(): void
    {
        $movie = $this->testMovie;
        $episode = $this->testEpisode;

        foreach (iFace::ENTITY_ARRAY_KEYS as $key) {
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
                iFace::TYPE_MOVIE => ['added' => 1, 'updated' => 0, 'failed' => 0],
                iFace::TYPE_EPISODE => ['added' => 1, 'updated' => 0, 'failed' => 0],
            ],
            $insert
        );

        $testMovie->metadata['home_plex'][iFace::COLUMN_GUIDS][Guid::GUID_ANIDB] = '1920';
        $testEpisode->metadata['home_plex'][iFace::COLUMN_GUIDS][Guid::GUID_ANIDB] = '1900';

        $this->mapper
            ->add($testMovie, ['diff_keys' => iFace::ENTITY_KEYS])
            ->add($testEpisode, ['diff_keys' => iFace::ENTITY_KEYS]);

        $updated = $this->mapper->commit();

        $this->assertSame(
            [
                iFace::TYPE_MOVIE => ['added' => 0, 'updated' => 1, 'failed' => 0],
                iFace::TYPE_EPISODE => ['added' => 0, 'updated' => 1, 'failed' => 0],
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
