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
use PDO;
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

        $this->storage = new PDOAdapter(new CliLogger($this->output), new PDO('sqlite::memory:'));
        $this->storage->migrations('up');
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
        $this->assertNull($this->storage->get($item));

        // -- insert and return object and assert it's the same
        $modified = $this->storage->insert(clone $item);

        $this->assertSame($modified->getAll(), $this->storage->get($item)->getAll());

        // -- look up based on id
        $this->assertSame($modified->getAll(), $this->storage->get($modified)->getAll());
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

    public function test_update_call_without_id_exception(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionCode(51);
        $item = new StateEntity($this->testEpisode);

        $this->storage->update($item);
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

        $item = $this->storage->insert(new StateEntity($test));
        $item->guids['guid_plex'] = StateInterface::TYPE_EPISODE . '/1000';

        $updatedItem = $this->storage->update($item);

        $this->assertSame($item, $updatedItem);
        $this->assertSame($updatedItem->getAll(), $this->storage->get($item)->getAll());
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

    public function test_commit_conditions(): void
    {
        $item1 = new StateEntity($this->testEpisode);
        $item2 = new StateEntity($this->testMovie);

        $this->assertSame(
            ['added' => 2, 'updated' => 0, 'failed' => 0],
            $this->storage->commit([$item1, $item2])
        );

        $item1->guids['guid_anidb'] = StateInterface::TYPE_EPISODE . '/1';
        $item2->guids['guid_anidb'] = StateInterface::TYPE_MOVIE . '/1';

        $this->assertSame(
            ['added' => 0, 'updated' => 2, 'failed' => 0],
            $this->storage->commit([$item1, $item2])
        );
    }

    public function test_migrations_call_with_wrong_direction_exception(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionCode(91);
        $this->storage->migrations('not_dd');
    }
}
