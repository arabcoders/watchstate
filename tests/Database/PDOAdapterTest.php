<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libs\Database\DatabaseException as DBException;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use App\Libs\Guid;
use DateTimeImmutable;
use Error;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class PDOAdapterTest extends TestCase
{
    private array $testMovie = [];
    private array $testEpisode = [];

    private iDB|null $db = null;
    protected TestHandler|null $handler = null;

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

        $this->db = new PDOAdapter($logger, new PDO('sqlite::memory:'));
        $this->db->migrations('up');
    }

    public function test_insert_throw_exception_if_has_id(): void
    {
        $this->expectException(DBException::class);
        $this->expectExceptionCode(21);
        $item = new StateEntity($this->testEpisode);
        $this->db->insert($item);
        $this->db->insert($item);
    }

    public function test_insert_successful(): void
    {
        $item = $this->db->insert(new StateEntity($this->testEpisode));
        $this->assertSame(1, $item->id);
    }

    public function test_get_conditions(): void
    {
        $test = $this->testEpisode;

        foreach (StateInterface::ENTITY_ARRAY_KEYS as $key) {
            if (null === ($test[$key] ?? null)) {
                continue;
            }
            ksort($test[$key]);
        }

        $item = new StateEntity($test);

        // -- db should be empty at this stage. as such we expect null.
        $this->assertNull($this->db->get($item));

        // -- insert and return object and assert it's the same
        $modified = $this->db->insert(clone $item);

        $this->assertSame($modified->getAll(), $this->db->get($item)->getAll());

        // -- look up based on id
        $this->assertSame($modified->getAll(), $this->db->get($modified)->getAll());
    }

    public function test_getAll_call_without_initialized_container(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Call to a member function');
        $this->db->getAll();
    }

    public function test_getAll_conditions(): void
    {
        $item = new StateEntity($this->testEpisode);

        $this->assertSame([], $this->db->getAll(opts: ['class' => $item]));

        $this->db->insert($item);

        $this->assertCount(1, $this->db->getAll(opts: ['class' => $item]));

        // -- future date should be 0.
        $this->assertCount(0, $this->db->getAll(date: new DateTimeImmutable('now'), opts: ['class' => $item]));
    }

    public function test_update_call_without_id_exception(): void
    {
        $this->expectException(DBException::class);
        $this->expectExceptionCode(51);
        $item = new StateEntity($this->testEpisode);

        $this->db->update($item);
    }

    public function test_update_conditions(): void
    {
        $test = $this->testEpisode;

        foreach (StateInterface::ENTITY_ARRAY_KEYS as $key) {
            if (null === ($test[$key] ?? null)) {
                continue;
            }
            ksort($test[$key]);
        }

        $item = $this->db->insert(new StateEntity($test));
        $item->guids[Guid::GUID_IMDB] = '6101';

        $updatedItem = $this->db->update($item);

        $this->assertSame($item, $updatedItem);
        $this->assertSame($updatedItem->getAll(), $this->db->get($item)->getAll());
    }

    public function test_remove_conditions(): void
    {
        $item1 = new StateEntity($this->testEpisode);
        $item2 = new StateEntity($this->testMovie);
        $item3 = new StateEntity([]);

        $this->assertFalse($this->db->remove($item1));

        $item1 = $this->db->insert($item1);
        $this->db->insert($item2);

        $this->assertTrue($this->db->remove($item1));
        $this->assertInstanceOf(StateInterface::class, $this->db->get($item2));

        // -- remove without id pointer.
        $this->assertTrue($this->db->remove($item2));
        $this->assertFalse($this->db->remove($item3));
    }

    public function test_commit_conditions(): void
    {
        $item1 = new StateEntity($this->testEpisode);
        $item2 = new StateEntity($this->testMovie);

        $this->assertSame(
            ['added' => 2, 'updated' => 0, 'failed' => 0],
            $this->db->commit([$item1, $item2])
        );

        $item1->guids['guid_anidb'] = StateInterface::TYPE_EPISODE . '/1';
        $item2->guids['guid_anidb'] = StateInterface::TYPE_MOVIE . '/1';

        $this->assertSame(
            ['added' => 0, 'updated' => 2, 'failed' => 0],
            $this->db->commit([$item1, $item2])
        );
    }

    public function test_migrations_call_with_wrong_direction_exception(): void
    {
        $this->expectException(DBException::class);
        $this->expectExceptionCode(91);
        $this->db->migrations('not_dd');
    }
}
