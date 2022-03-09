<?php

declare(strict_types=1);

namespace Tests\Storage;

use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use App\Libs\Extends\CliLogger;
use App\Libs\Storage\PDO\PDOAdapter;
use App\Libs\Storage\StorageException;
use App\Libs\Storage\StorageInterface;
use DateTimeImmutable;
use Error;
use PDOException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class PDOAdapterTest extends TestCase
{
    private array $testMovie = [];
    private array $testEpisode = [];

    private StorageInterface|null $storage = null;

    public function setUp(): void
    {
        $this->output = new NullOutput();
        $this->input = new ArrayInput([]);

        $this->testMovie = require __DIR__ . '/../Fixtures/MovieEntity.php';
        $this->testEpisode = require __DIR__ . '/../Fixtures/EpisodeEntity.php';

        $this->storage = new PDOAdapter(new CliLogger($this->output));
        $this->storage->setUp(['dsn' => 'sqlite::memory:']);
        $this->storage->migrations('up');
    }

    /** StorageInterface::setUp */
    public function test_setup_throw_exception_if_no_dsn(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionCode(10);
        $storage = new PDOAdapter(new CliLogger($this->output));
        $storage->setUp([]);
    }

    public function test_setup_throw_exception_if_invalid_dsn(): void
    {
        $this->expectException(PDOException::class);
        $storage = new PDOAdapter(new CliLogger($this->output));
        $storage->setUp(['dsn' => 'not_real_driver::foo']);
    }

    /** StorageInterface::insert */
    public function test_insert_call_without_setup_exception(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionCode(StorageException::SETUP_NOT_CALLED);
        $storage = new PDOAdapter(new CliLogger($this->output));

        $storage->insert(new StateEntity([]));
    }

    public function test_insert_throw_exception_if_has_id(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionCode(21);
        $item = new StateEntity($this->testEpisode);
        $this->storage->insert($item);
        $this->storage->insert($item);
    }

    public function test_insert_successful(): void
    {
        $item = $this->storage->insert(new StateEntity($this->testEpisode));
        $this->assertSame(1, $item->id);
    }

    /** StorageInterface::get */
    public function test_get_call_without_setup_exception(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionCode(StorageException::SETUP_NOT_CALLED);
        $storage = new PDOAdapter(new CliLogger($this->output));
        $storage->get(new StateEntity([]));
    }

    public function test_get_conditions(): void
    {
        $item = new StateEntity($this->testEpisode);

        // -- db should be empty at this stage. as such we expect null.
        $this->assertNull($this->storage->get($item));

        // -- insert and return object and assert it's the same
        $modified = $this->storage->insert(clone $item);

        $this->assertSame($modified->getAll(), $this->storage->get($item)->getAll());

        // -- look up based on id
        $this->assertSame($modified->getAll(), $this->storage->get($modified)->getAll());
    }

    /** StorageInterface::getAll */
    public function test_getAll_call_without_setup_exception(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionCode(StorageException::SETUP_NOT_CALLED);
        $storage = new PDOAdapter(new CliLogger($this->output));
        $storage->getAll();
    }

    public function test_getAll_call_without_initialized_container(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Call to a member function');
        $this->storage->getAll();
    }

    public function test_getAll_conditions(): void
    {
        $item = new StateEntity($this->testEpisode);

        $this->assertSame([], $this->storage->getAll(class: $item));

        $this->storage->insert($item);

        $this->assertCount(1, $this->storage->getAll(class: $item));

        // -- future date should be 0.
        $this->assertCount(0, $this->storage->getAll(date: new DateTimeImmutable('now'), class: $item));
    }

    /** StorageInterface::update */
    public function test_update_call_without_setup_exception(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionCode(StorageException::SETUP_NOT_CALLED);
        $storage = new PDOAdapter(new CliLogger($this->output));
        $storage->update(new StateEntity([]));
    }

    public function test_update_call_without_id_exception(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionCode(51);
        $item = new StateEntity($this->testEpisode);

        $this->storage->update($item);
    }

    public function test_update_conditions(): void
    {
        $item = $this->storage->insert(new StateEntity($this->testEpisode));
        $item->guid_plex = StateInterface::TYPE_EPISODE . '/1000';

        $updatedItem = $this->storage->update($item);

        $this->assertSame($item, $updatedItem);
        $this->assertSame($updatedItem->getAll(), $this->storage->get($item)->getAll());
    }

    /** StorageInterface::update */
    public function test_matchAnyId_call_without_setup_exception(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionCode(StorageException::SETUP_NOT_CALLED);
        $storage = new PDOAdapter(new CliLogger($this->output));
        $storage->matchAnyId([]);
    }

    public function test_matchAnyId_call_without_initialized_container(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Call to a member function');
        $this->storage->matchAnyId([]);
    }

    public function test_matchAnyId_conditions(): void
    {
        $item1 = new StateEntity($this->testEpisode);
        $item2 = new StateEntity($this->testMovie);

        $this->assertNull(
            $this->storage->matchAnyId(
                array_intersect_key($item1->getAll(), array_flip(StateInterface::ENTITY_GUIDS)),
                $item1
            )
        );

        $newItem1 = $this->storage->insert($item1);
        $newItem2 = $this->storage->insert($item2);

        $this->assertSame(
            $newItem1->getAll(),
            $this->storage->matchAnyId(
                array_intersect_key($item1->getAll(), array_flip(StateInterface::ENTITY_GUIDS)),
                $item1
            )->getAll()
        );

        $this->assertSame(
            $newItem2->getAll(),
            $this->storage->matchAnyId(
                array_intersect_key($item2->getAll(), array_flip(StateInterface::ENTITY_GUIDS)),
                $item2
            )->getAll()
        );
    }

    /** StorageInterface::remove */
    public function test_remove_call_without_setup_exception(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionCode(StorageException::SETUP_NOT_CALLED);
        $storage = new PDOAdapter(new CliLogger($this->output));
        $storage->remove(new StateEntity([]));
    }

    public function test_remove_conditions(): void
    {
        $item1 = new StateEntity($this->testEpisode);
        $item2 = new StateEntity($this->testMovie);
        $item3 = new StateEntity([]);

        $this->assertFalse($this->storage->remove($item1));

        $item1 = $this->storage->insert($item1);
        $this->storage->insert($item2);

        $this->assertTrue($this->storage->remove($item1));
        $this->assertInstanceOf(StateInterface::class, $this->storage->get($item2));

        // -- remove without id pointer.
        $this->assertTrue($this->storage->remove($item2));
        $this->assertFalse($this->storage->remove($item3));
    }

    /** StorageInterface::commit */
    public function test_commit_call_without_setup_exception(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionCode(StorageException::SETUP_NOT_CALLED);
        $storage = new PDOAdapter(new CliLogger($this->output));
        $storage->commit([]);
    }

    public function test_commit_conditions(): void
    {
        $item1 = new StateEntity($this->testEpisode);
        $item2 = new StateEntity($this->testMovie);

        $this->assertSame(
            [
                StateInterface::TYPE_MOVIE => ['added' => 1, 'updated' => 0, 'failed' => 0],
                StateInterface::TYPE_EPISODE => ['added' => 1, 'updated' => 0, 'failed' => 0],
            ],
            $this->storage->commit([$item1, $item2])
        );

        $item1->guid_anidb = StateInterface::TYPE_EPISODE . '/1';
        $item2->guid_anidb = StateInterface::TYPE_MOVIE . '/1';

        $this->assertSame(
            [
                StateInterface::TYPE_MOVIE => ['added' => 0, 'updated' => 1, 'failed' => 0],
                StateInterface::TYPE_EPISODE => ['added' => 0, 'updated' => 1, 'failed' => 0],
            ],
            $this->storage->commit([$item1, $item2])
        );
    }

    /** StorageInterface::migrations */
    public function test_migrations_call_without_setup_exception(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionCode(StorageException::SETUP_NOT_CALLED);
        $storage = new PDOAdapter(new CliLogger($this->output));
        $storage->migrations('f');
    }

    public function test_migrations_call_with_wrong_direction_exception(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionCode(91);
        $this->storage->migrations('not_dd');
    }
}
