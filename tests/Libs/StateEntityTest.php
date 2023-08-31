<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\TestCase;
use RuntimeException;

class StateEntityTest extends TestCase
{
    private array $testMovie = [];
    private array $testEpisode = [];

    protected function setUp(): void
    {
        $this->testMovie = require __DIR__ . '/../Fixtures/MovieEntity.php';
        $this->testEpisode = require __DIR__ . '/../Fixtures/EpisodeEntity.php';
    }

    public function test_init_bad_type(): void
    {
        $this->testMovie[iState::COLUMN_TYPE] = 'oi';

        try {
            new StateEntity($this->testMovie);
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(RuntimeException::class, $e);
        }

        try {
            StateEntity::fromArray($this->testMovie);
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(RuntimeException::class, $e);
        }
    }

    public function test_init_bad_data(): void
    {
        $entityEmpty = new StateEntity([]);
        $entity = $entityEmpty::fromArray(['bad_key' => 'foo']);
        $this->assertSame($entityEmpty->getAll(), $entity->getAll());
    }

    public function test_diff_direct_param(): void
    {
        $entity = new StateEntity($this->testEpisode);
        $entity->watched = 0;
        $this->assertSame([iState::COLUMN_WATCHED => ['old' => 1, 'new' => 0]], $entity->diff());
    }

    public function test_diff_array_param(): void
    {
        $entity = new StateEntity($this->testEpisode);
        $entity->setMetadata([iState::COLUMN_META_DATA_PLAYED_AT => 4]);

        $arr = [];
        $arr = ag_set($arr, 'metadata.home_plex.played_at.old', 2);
        $arr = ag_set($arr, 'metadata.home_plex.played_at.new', 4);

        $this->assertSame($arr, $entity->diff());
    }

    public function test_getName_as_movie(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertSame('Movie Title (2020)', $entity->getName());
        $this->assertSame('Movie Title (2020)', $entity->getName(asMovie: true));

        $data = $this->testMovie;

        unset($data[iState::COLUMN_TITLE]);
        unset($data[iState::COLUMN_YEAR]);

        $entity = $entity::fromArray($data);
        $this->assertSame('?? (0000)', $entity->getName());

        $entity = new StateEntity($this->testMovie);
        $entity->title = '';
        $entity->year = 2000;
        $this->assertSame('Movie Title (2020)', $entity->getName());
    }

    public function test_getName_as_episode(): void
    {
        $entity = new StateEntity($this->testEpisode);
        $this->assertSame('Series Title (2020) - 01x002', $entity->getName());
        $this->assertSame('Series Title (2020)', $entity->getName(asMovie: true));

        $data = $this->testEpisode;

        unset($data[iState::COLUMN_EPISODE]);
        unset($data[iState::COLUMN_SEASON]);
        unset($data[iState::COLUMN_TITLE]);
        unset($data[iState::COLUMN_YEAR]);

        $entity = $entity::fromArray($data);
        $this->assertSame('?? (0000) - 00x000', $entity->getName());

        $entity = new StateEntity($this->testEpisode);
        $entity->episode = 0;
        $entity->season = 0;
        $entity->title = '';
        $entity->year = 2000;
        $this->assertSame('Series Title (2020) - 01x002', $entity->getName());
    }

    public function test_getAll(): void
    {
        $base = new StateEntity($this->testEpisode);

        $data = $this->testEpisode;
        $data['not_real'] = 1;
        $entity = $base::fromArray($data);

        $this->assertSame($base->getAll(), $entity->getAll());
    }

    public function test_isChanged(): void
    {
        $entity = new StateEntity($this->testEpisode);
        $entity->watched = 0;

        $this->assertTrue($entity->isChanged());
        $this->assertTrue($entity->isChanged([iState::COLUMN_WATCHED]));
        $this->assertFalse($entity->isChanged([iState::COLUMN_UPDATED]));
    }

    public function test_hasGuids(): void
    {
        $entity = new StateEntity($this->testMovie);

        $this->assertTrue($entity->hasGuids());

        $data = $this->testMovie;
        $data[iState::COLUMN_GUIDS] = ['guid_non' => '121'];

        $entity = $entity::fromArray($data);
        $this->assertFalse($entity->hasGuids());
        $this->assertSame($data[iState::COLUMN_GUIDS], $entity->getGuids());
    }

    public function test_getGuids(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertSame($this->testMovie[iState::COLUMN_GUIDS], $entity->getGuids());

        $data = $this->testMovie;
        unset($data[iState::COLUMN_GUIDS]);
        $entity = $entity::fromArray($data);

        $this->assertSame([], $entity->getGuids());
    }

    public function test_getPointers(): void
    {
        $data = $this->testMovie;
        $data[iState::COLUMN_GUIDS]['guid_foo'] = '123';

        $entity = new StateEntity($data);

        $pointers = [
            'guid_imdb://tt1100',
            'guid_tvdb://1200',
            'guid_tmdb://1300',
            'guid_tvmaze://1400',
            'guid_tvrage://1500',
            'guid_anidb://1600',
        ];

        $this->assertSame($pointers, $entity->getPointers());
    }

    public function test_hasParentGuid(): void
    {
        $entity = new StateEntity($this->testEpisode);

        $this->assertTrue($entity->hasParentGuid());

        $data = $this->testEpisode;
        $data[iState::COLUMN_PARENT] = ['guid_non' => '121'];

        $entity = $entity::fromArray($data);
        $this->assertFalse($entity->hasParentGuid());

        $data = $this->testEpisode;
        $data[iState::COLUMN_PARENT]['guid_non'] = '121';
        $entity = $entity::fromArray($data);
        $this->assertTrue($entity->hasParentGuid());
    }

    public function test_getParentGuids(): void
    {
        $entity = new StateEntity($this->testEpisode);
        $this->assertSame($this->testEpisode[iState::COLUMN_PARENT], $entity->getParentGuids());

        $data = $this->testEpisode;
        unset($data[iState::COLUMN_PARENT]);
        $entity = $entity::fromArray($data);

        $this->assertSame([], $entity->getParentGuids());

        $data = $this->testEpisode;
        $data[iState::COLUMN_PARENT]['guid_foo'] = '123';
        $entity = $entity::fromArray($data);

        $this->assertSame($data[iState::COLUMN_PARENT], $entity->getParentGuids());
    }

    public function test_isMovie_isEpisode(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertTrue($entity->isMovie());
        $this->assertFalse($entity->isEpisode());

        $entity = new StateEntity($this->testEpisode);
        $this->assertFalse($entity->isMovie());
        $this->assertTrue($entity->isEpisode());
    }

    public function test_isWatched(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertTrue($entity->isWatched());

        $data = $this->testMovie;
        $data[iState::COLUMN_WATCHED] = 0;
        $entity = $entity::fromArray($data);

        $this->assertFalse($entity->isEpisode());
    }

    public function test_hasRelativeGuid(): void
    {
        $this->assertTrue((new StateEntity($this->testEpisode))->hasRelativeGuid());
        $this->assertFalse((new StateEntity($this->testMovie))->hasRelativeGuid());

        $data = $this->testEpisode;
        unset($data[iState::COLUMN_SEASON]);
        $entity = StateEntity::fromArray($data);
        $this->assertFalse($entity->hasRelativeGuid());

        $data = $this->testEpisode;
        unset($data[iState::COLUMN_EPISODE]);
        $entity = StateEntity::fromArray($data);
        $this->assertFalse($entity->hasRelativeGuid());

        $data = $this->testEpisode;
        unset($data[iState::COLUMN_PARENT]);
        $entity = StateEntity::fromArray($data);
        $this->assertFalse($entity->hasRelativeGuid());
    }

    public function test_getRelativeGuids(): void
    {
        $this->assertSame([], (new StateEntity($this->testMovie))->getRelativeGuids());
        $this->assertSame([
            'guid_imdb' => 'tt510/1/2',
            'guid_tvdb' => '520/1/2'
        ], (new StateEntity($this->testEpisode))->getRelativeGuids());

        $data = $this->testEpisode;
        unset($data[iState::COLUMN_PARENT]);
        $entity = new StateEntity($data);
        $this->assertSame([], $entity->getRelativeGuids());

        $data = $this->testEpisode;
        $data[iState::COLUMN_PARENT]['guid_foo'] = '123';
        $entity = $entity::fromArray($data);
        $this->assertSame([
            'guid_imdb' => 'tt510/1/2',
            'guid_tvdb' => '520/1/2'
        ], $entity->getRelativeGuids());
    }

    public function test_getRelativePointers(): void
    {
        $data = $this->testEpisode;
        $data[iState::COLUMN_PARENT]['guid_foo'] = '123';

        $entity = new StateEntity($data);

        $pointers = [
            'rguid_imdb://tt510/1/2',
            'rguid_tvdb://520/1/2',
        ];

        $this->assertSame($pointers, $entity->getRelativePointers());
        $this->assertSame([], (new StateEntity($this->testMovie))->getRelativePointers());

        $data = $this->testEpisode;
        $data[iState::COLUMN_PARENT]['guid_foo'] = '123';
        $entity = $entity::fromArray($data);

        $this->assertSame($pointers, $entity->getRelativePointers());
    }

    public function test_apply(): void
    {
        $this->testMovie[iState::COLUMN_WATCHED] = 0;

        $entity = new StateEntity($this->testMovie);
        $updated = $entity::fromArray($entity->getAll());
        $this->assertSame($updated->getAll(), $entity->getAll());

        $entity = new StateEntity($this->testMovie);
        $updated->title = 'Test';
        $entity->apply($updated, [iState::COLUMN_VIA]);
        $this->assertSame([], $entity->diff());

        $entity = new StateEntity($this->testMovie);
        $updated = $entity::fromArray($entity->getAll());
        $updated->watched = 1;
        $updated->updated = 105;
        $entity->apply($updated);
        $this->assertSame([
            iState::COLUMN_UPDATED => [
                'old' => 1,
                'new' => 105,
            ],
            iState::COLUMN_WATCHED => [
                'old' => 0,
                'new' => 1,
            ],
        ], $entity->diff());

        $entity = new StateEntity($this->testMovie);
        $updated = $entity::fromArray($entity->getAll());
        $updated->title = 'Test';
        $updated->setMetadata([iState::COLUMN_ID => 1234]);

        $entity->apply($updated, [iState::COLUMN_TITLE, iState::COLUMN_META_DATA]);
        $this->assertSame([
            iState::COLUMN_TITLE => [
                'old' => 'Movie Title',
                'new' => 'Test',
            ],
            iState::COLUMN_META_DATA => [
                $updated->via => [
                    iState::COLUMN_ID => [
                        'old' => 121,
                        'new' => 1234,
                    ]
                ]
            ]
        ], $entity->diff());
    }

    public function test_updateOriginal(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertSame($entity->getOriginalData(), $entity->getAll());

        $entity->watched = 0;
        $entity->updateOriginal();
        $this->assertSame($entity->getOriginalData(), $entity->getAll());
    }

    public function test_getOriginalData(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertSame($entity->getOriginalData(), $entity->getAll());

        $entity->watched = 0;
        $this->assertNotSame($entity->getOriginalData(), $entity->getAll());
    }

    public function test_setIsTainted(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertFalse($entity->isTainted());
        $entity->setIsTainted(true);
        $this->assertTrue($entity->isTainted());

        $this->expectException(\TypeError::class);
        /** @noinspection PhpStrictTypeCheckingInspection */
        $entity->setIsTainted('foo');
    }

    public function test_isTainted(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertFalse($entity->isTainted());

        $entity->setIsTainted(true);
        $this->assertTrue($entity->isTainted());
    }

    public function test_getMetadata(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertSame($this->testMovie[iState::COLUMN_META_DATA], $entity->getMetadata());
        $this->assertSame($this->testMovie[iState::COLUMN_META_DATA][$entity->via], $entity->getMetadata($entity->via));
        $this->assertSame([], $entity->getMetadata('not_set'));
    }

    public function test_setMetadata(): void
    {
        $entity = new StateEntity($this->testMovie);
        $metadata = $entity->getMetadata($entity->via);
        $metadata[iState::COLUMN_META_DATA_PLAYED_AT] = 10;
        $entity->setMetadata([iState::COLUMN_META_DATA_PLAYED_AT => 10]);
        $this->assertSame($metadata, $entity->getMetadata($entity->via));
        $entity->setMetadata([]);
        $this->assertSame([], $entity->getMetadata($entity->via));

        unset($this->testMovie[iState::COLUMN_VIA]);
        $entity = new StateEntity($this->testMovie);
        $this->expectException(RuntimeException::class);
        $entity->setMetadata([]);
    }

    public function test_getExtra(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertSame($this->testMovie[iState::COLUMN_EXTRA], $entity->getExtra());
        $this->assertSame($this->testMovie[iState::COLUMN_EXTRA][$entity->via], $entity->getExtra($entity->via));
        $this->assertSame([], $entity->getMetadata('not_set'));
    }

    public function test_setExtra(): void
    {
        $entity = new StateEntity($this->testMovie);
        $extra = $entity->getExtra($entity->via);
        $extra[iState::COLUMN_EXTRA_EVENT] = 'foo';
        $entity->setExtra([iState::COLUMN_EXTRA_EVENT => 'foo']);
        $this->assertSame($extra, $entity->getExtra($entity->via));

        $entity->setExtra([]);
        $this->assertSame([], $entity->getExtra($entity->via));

        unset($this->testMovie[iState::COLUMN_VIA]);
        $entity = new StateEntity($this->testMovie);
        $this->expectException(RuntimeException::class);
        $entity->setExtra([]);
    }

    public function test_shouldMarkAsUnplayed(): void
    {
        $data = $this->testMovie;
        $data[iState::COLUMN_WATCHED] = 0;
        $entity = new StateEntity($this->testMovie);
        // -- Condition 1: db entity not marked as watched.
        $this->assertFalse($entity->shouldMarkAsUnplayed($entity));

        $entity = new StateEntity($this->testMovie);
        // -- Condition 2: backend entity not marked as unwatched.
        $this->assertFalse($entity->shouldMarkAsUnplayed($entity));

        $entity = new StateEntity($this->testMovie);
        $data = $this->testMovie;
        $data[iState::COLUMN_WATCHED] = 0;
        unset($data[iState::COLUMN_META_DATA]);
        $updater = new StateEntity($data);
        // -- Condition 3: No metadata was set previously on records.
        $this->assertFalse($entity->shouldMarkAsUnplayed($updater));

        // -- Condition 4: Required metadata fields.
        $fields = [
            iState::COLUMN_ID,
            iState::COLUMN_WATCHED,
            iState::COLUMN_META_DATA_ADDED_AT,
            iState::COLUMN_META_DATA_PLAYED_AT
        ];

        $d = [];
        $x = 0;
        foreach ($fields as $field) {
            $data = $this->testMovie;
            unset($data[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][$field]);
            $d[$x] = $data;
            $x++;
        }

        $updater = new StateEntity($this->testMovie);
        $updater->watched = 0;

        $this->assertTrue((StateEntity::fromArray($this->testMovie)->shouldMarkAsUnplayed($updater)));
        $this->assertFalse(StateEntity::fromArray($d[0])->shouldMarkAsUnplayed($updater));
        $this->assertFalse(StateEntity::fromArray($d[1])->shouldMarkAsUnplayed($updater));
        $this->assertFalse(StateEntity::fromArray($d[2])->shouldMarkAsUnplayed($updater));
        $this->assertFalse(StateEntity::fromArray($d[3])->shouldMarkAsUnplayed($updater));

        // -- Condition 5: metadata played is false.
        $data = $this->testMovie;
        $data[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_WATCHED] = 0;
        $this->assertFalse(StateEntity::fromArray($data)->shouldMarkAsUnplayed($updater));

        // -- Condition 7: metadata added date not equal to updated.
        $data = $this->testMovie;
        $data[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_META_DATA_ADDED_AT] = 124;
        $this->assertFalse(StateEntity::fromArray($data)->shouldMarkAsUnplayed($updater));

        // -- Finally, should update.
        $this->assertTrue((StateEntity::fromArray($this->testMovie)->shouldMarkAsUnplayed($updater)));
    }

    public function test_markAsUnplayed(): void
    {
        $entity = new StateEntity($this->testMovie);
        $entity->via = 'tester';
        $entity->markAsUnplayed($entity);
        $entity->updated = 105;

        $this->assertFalse($entity->isWatched());
        $this->assertSame([
            'updated' => [
                'old' => 1,
                'new' => 105,
            ],
            'watched' => [
                'old' => 1,
                'new' => 0,
            ],
            'via' => [
                'old' => 'home_plex',
                'new' => 'tester',
            ],
        ], $entity->diff());
    }
}
