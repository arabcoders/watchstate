<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\Guid;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use RuntimeException;

class StateEntityTest extends TestCase
{
    private array $testMovie = [];
    private array $testEpisode = [];

    protected function setUp(): void
    {
        $this->testMovie = require __DIR__ . '/../Fixtures/MovieEntity.php';
        $this->testEpisode = require __DIR__ . '/../Fixtures/EpisodeEntity.php';
        $logger = new Logger('logger', processors: [new LogMessageProcessor()]);
        $this->handler = new TestHandler();
        $logger->pushHandler($this->handler);
        Guid::setLogger($logger);
    }

    public function test_init_bad_type(): void
    {
        $this->testMovie[iState::COLUMN_TYPE] = 'oi';

        $this->checkException(
            closure: fn() => new StateEntity($this->testMovie),
            reason: 'When new instance of StateEntity is called with invalid type, exception is thrown',
            exception: RuntimeException::class,
        );
        $this->checkException(
            closure: fn() => StateEntity::fromArray($this->testMovie),
            reason: 'When ::fromArray is called with invalid type, exception is thrown',
            exception: RuntimeException::class,
        );
    }

    public function test_init_bad_data(): void
    {
        $entityEmpty = new StateEntity(['bad_key' => 'foo']);
        $entity = $entityEmpty::fromArray(['bad_key' => 'foo']);
        $this->assertSame(
            $entityEmpty->getAll(),
            $entity->getAll(),
            'When new instance of StateEntity is called with total invalid data, getAll() return empty array'
        );
    }

    public function test_diff_direct_param(): void
    {
        $entity = new StateEntity($this->testEpisode);
        $entity->watched = 0;
        $this->assertSame(
            [
                iState::COLUMN_WATCHED => [
                    'old' => 1,
                    'new' => 0
                ]
            ],
            $entity->diff(),
            'When object directly modified and diff() is called, only modified fields are returned in format [field => [old => old_value, new => new_value]]'
        );
    }

    public function test_diff_array_param(): void
    {
        $entity = new StateEntity($this->testEpisode);
        $entity->setMetadata([
            iState::COLUMN_META_DATA_PLAYED_AT => 4,
            'test' => ['foo' => 'bar'],
        ]);

        $arr = [];
        $arr = ag_set($arr, 'metadata.test_plex.played_at.old', 2);
        $arr = ag_set($arr, 'metadata.test_plex.played_at.new', 4);
        $arr = ag_set($arr, 'metadata.test_plex.test.old', 'None');
        $arr = ag_set($arr, 'metadata.test_plex.test.new', ['foo' => 'bar']);

        $this->assertSame(
            $arr,
            $entity->diff(),
            'When array parameter is updated and diff() is called, only modified fields are returned in format [field => [old => old_value, new => new_value]]'
        );
    }

    public function test_getName_as_movie(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertSame(
            'Movie Title (2020)',
            $entity->getName(),
            'When entity is movie, getName() returns title and year'
        );
        $this->assertSame(
            'Movie Title (2020)',
            $entity->getName(asMovie: true),
            'When getName() called with asMovie parameter set to true, getName() returns title and year'
        );

        $data = $this->testMovie;

        unset($data[iState::COLUMN_TITLE]);
        unset($data[iState::COLUMN_YEAR]);

        $entity = $entity::fromArray($data);
        $this->assertSame(
            '?? (0000)',
            $entity->getName(),
            'When no title and year is set, getName() returns ?? (0000)'
        );

        $entity = new StateEntity($this->testMovie);
        $entity->title = '';
        $entity->year = 2000;
        $this->assertSame(
            'Movie Title (2020)',
            $entity->getName(),
            'getName() should reference initial data, not current object data'
        );
    }

    public function test_getName_as_episode(): void
    {
        $entity = new StateEntity($this->testEpisode);
        $this->assertSame(
            'Series Title (2020) - 01x002',
            $entity->getName(),
            'When entity is episode, getName() returns series title, year, season and episode in format of Series Title (2020) - SSxEEE'
        );
        $this->assertSame(
            'Series Title (2020)',
            $entity->getName(asMovie: true),
            'When entity is episode, and getName() called with asMovie parameter set to true, getName() returns series title and year'
        );

        $data = $this->testEpisode;

        unset($data[iState::COLUMN_EPISODE]);
        unset($data[iState::COLUMN_SEASON]);
        unset($data[iState::COLUMN_TITLE]);
        unset($data[iState::COLUMN_YEAR]);

        $entity = $entity::fromArray($data);
        $this->assertSame(
            '?? (0000) - 00x000',
            $entity->getName(),
            'When no title, year, season and episode is set, getName() returns ?? (0000) - 00x000'
        );

        $entity = new StateEntity($this->testEpisode);
        $entity->episode = 0;
        $entity->season = 0;
        $entity->title = '';
        $entity->year = 2000;
        $this->assertSame(
            'Series Title (2020) - 01x002',
            $entity->getName(),
            'getName() should reference initial data, not current object data'
        );
    }

    public function test_getAll(): void
    {
        $base = new StateEntity($this->testEpisode);

        $data = $this->testEpisode;
        $data['not_real'] = 1;
        $entity = $base::fromArray($data);

        $this->assertSame(
            $base->getAll(),
            $entity->getAll(),
            'When new instance of StateEntity is called with invalid data, getAll() return only valid data'
        );
    }

    public function test_isChanged(): void
    {
        $entity = new StateEntity($this->testEpisode);
        $entity->watched = 0;

        $this->assertTrue(
            $entity->isChanged(),
            'When object directly modified and isChanged() is called, returns true'
        );
        $this->assertTrue(
            $entity->isChanged([iState::COLUMN_WATCHED]),
            'When object directly modified and isChanged() is called with fields that contain changed keys, it returns true'
        );
        $this->assertFalse(
            $entity->isChanged([iState::COLUMN_UPDATED]),
            'When object directly modified and isChanged() is called with fields that do not contain changed keys, it returns false'
        );
    }

    public function test_hasGuids(): void
    {
        $entity = new StateEntity($this->testMovie);

        $this->assertTrue(
            $entity->hasGuids(),
            'When entity has supported GUIDs, hasGuids() returns true'
        );

        $data = $this->testMovie;
        $data[iState::COLUMN_GUIDS] = ['guid_non' => '121'];

        $entity = $entity::fromArray($data);
        $this->assertFalse(
            $entity->hasGuids(),
            'When entity does not have supported GUIDs, hasGuids() returns false'
        );
        $this->assertSame(
            $data[iState::COLUMN_GUIDS],
            $entity->getGuids(),
            'getGuids() returns list of all keys including unsupported ones'
        );
    }

    public function test_getGuids(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertSame(
            $this->testMovie[iState::COLUMN_GUIDS],
            $entity->getGuids(),
            'When entity has GUIDs, getGuids() returns list of all GUIDs'
        );

        $data = $this->testMovie;
        unset($data[iState::COLUMN_GUIDS]);
        $entity = $entity::fromArray($data);

        $this->assertSame([],
            $entity->getGuids(),
            'When entity does not have GUIDs, getGuids() returns empty array'
        );
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

        $this->assertSame(
            $pointers,
            $entity->getPointers(),
            'When entity has supported GUIDs, getPointers() returns list of all GUIDs in format of guid_<provider>://<id>'
        );

        $data = $this->testMovie;
        $data[iState::COLUMN_GUIDS] = [
            'guid_foo' => '123',
            'guid_bar' => '456',
        ];
        $entity = $entity::fromArray($data);

        $this->assertSame(
            [],
            $entity->getPointers(),
            'When entity does not have GUIDs or supported ones, getPointers() returns empty array'
        );
    }

    public function test_hasParentGuid(): void
    {
        $entity = new StateEntity($this->testEpisode);

        $this->assertTrue(
            $entity->hasParentGuid(),
            'When entity has supported parent GUIDs, hasParentGuid() returns true'
        );

        $data = $this->testEpisode;
        $data[iState::COLUMN_PARENT] = ['guid_non' => '121'];

        $entity = $entity::fromArray($data);
        $this->assertFalse(
            $entity->hasParentGuid(),
            'When entity does not have supported parent GUIDs, hasParentGuid() returns false'
        );

        $data = $this->testEpisode;
        $data[iState::COLUMN_PARENT]['guid_non'] = '121';
        $entity = $entity::fromArray($data);
        $this->assertTrue(
            $entity->hasParentGuid(),
            'When entity has parent supported GUIDs even if contains unsupported ones, hasParentGuid() returns true'
        );
    }

    public function test_getParentGuids(): void
    {
        $entity = new StateEntity($this->testEpisode);
        $this->assertSame(
            $this->testEpisode[iState::COLUMN_PARENT],
            $entity->getParentGuids(),
            'When entity has parent GUIDs, getParentGuids() returns list of all GUIDs'
        );

        $data = $this->testEpisode;
        unset($data[iState::COLUMN_PARENT]);
        $entity = $entity::fromArray($data);

        $this->assertSame(
            [],
            $entity->getParentGuids(),
            'When entity does not have parent GUIDs, getParentGuids() returns empty array'
        );

        $data = $this->testEpisode;
        $data[iState::COLUMN_PARENT]['guid_foo'] = '123';
        $entity = $entity::fromArray($data);

        $this->assertSame(
            $data[iState::COLUMN_PARENT],
            $entity->getParentGuids(),
            'When entity has parent GUIDs, getParentGuids() returns list of all GUIDs including unsupported ones'
        );
    }

    public function test_isMovie_isEpisode(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertTrue($entity->isMovie(), 'When entity is movie, isMovie() returns true');
        $this->assertFalse($entity->isEpisode(), 'When entity is movie, isEpisode() returns false');

        $entity = new StateEntity($this->testEpisode);
        $this->assertFalse($entity->isMovie(), 'When entity is episode, isMovie() returns false');
        $this->assertTrue($entity->isEpisode(), 'When entity is episode, isEpisode() returns true');
    }

    public function test_isWatched(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertTrue($entity->isWatched(), 'When entity is watched, isWatched() returns true');

        $data = $this->testMovie;
        $data[iState::COLUMN_WATCHED] = 0;
        $entity = $entity::fromArray($data);

        $this->assertFalse($entity->isWatched(), 'When entity is not watched, isWatched() returns false');
    }

    public function test_hasRelativeGuid(): void
    {
        $this->assertTrue(
            (new StateEntity($this->testEpisode))->hasRelativeGuid(),
            'When entity is episode, and only if has supported GUIDs hasRelativeGuid() returns true'
        );
        $this->assertFalse(
            (new StateEntity($this->testMovie))->hasRelativeGuid(),
            'When entity is movie, hasRelativeGuid() returns false regardless'
        );

        $data = $this->testEpisode;
        unset($data[iState::COLUMN_SEASON]);
        $entity = StateEntity::fromArray($data);
        $this->assertFalse(
            $entity->hasRelativeGuid(),
            'When entity is episode, and does not have season, hasRelativeGuid() returns false'
        );

        $data = $this->testEpisode;
        unset($data[iState::COLUMN_EPISODE]);
        $entity = StateEntity::fromArray($data);
        $this->assertFalse(
            $entity->hasRelativeGuid(),
            'When entity is episode, and does not have episode, hasRelativeGuid() returns false'
        );

        $data = $this->testEpisode;
        unset($data[iState::COLUMN_PARENT]);
        $entity = StateEntity::fromArray($data);
        $this->assertFalse(
            $entity->hasRelativeGuid(),
            'When entity is episode, and does not have parent GUIDs, hasRelativeGuid() returns false'
        );
    }

    public function test_getRelativeGuids(): void
    {
        $this->assertSame(
            [],
            (new StateEntity($this->testMovie))->getRelativeGuids(),
            'When entity is movie, getRelativeGuids() returns empty array regardless'
        );
        $this->assertSame(
            [
                'guid_imdb' => 'tt510/1/2',
                'guid_tvdb' => '520/1/2'
            ],
            (new StateEntity($this->testEpisode))->getRelativeGuids(),
            'When entity is episode, and has supported GUIDs, getRelativeGuids() returns list of all supported GUIDs'
        );

        $data = $this->testEpisode;
        unset($data[iState::COLUMN_PARENT]);
        $entity = new StateEntity($data);
        $this->assertSame([],
            $entity->getRelativeGuids(),
            'When entity is episode, and does not have parent GUIDs, getRelativeGuids() returns empty array'
        );

        $data = $this->testEpisode;
        $data[iState::COLUMN_PARENT]['guid_foo'] = '123';
        $entity = $entity::fromArray($data);
        $this->assertSame(
            [
                'guid_imdb' => 'tt510/1/2',
                'guid_tvdb' => '520/1/2'
            ],
            $entity->getRelativeGuids(),
            'When entity is episode, and has supported GUIDs, getRelativeGuids() returns list of all supported GUIDs excluding unsupported ones'
        );
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

        $po = $entity->getRelativePointers();
        $this->assertSame(
            $pointers,
            $po,
            'When entity is episode, and has supported GUIDs, getRelativePointers() returns list of all supported GUIDs in format of rguid_<provider>://<id>/<season>/<episode>'
        );

        $this->assertSame([],
            (new StateEntity($this->testMovie))->getRelativePointers(),
            'When entity is movie, getRelativePointers() returns empty array regardless.'
        );

        $data = $this->testEpisode;
        $data[iState::COLUMN_PARENT]['guid_foo'] = '123';
        $entity = $entity::fromArray($data);

        $this->assertSame(
            $pointers,
            $entity->getRelativePointers(),
            'When entity is episode, and has supported GUIDs, getRelativePointers() returns list of all supported GUIDs in format of rguid_<provider>://<id>/<season>/<episode> excluding unsupported ones'
        );
    }

    public function test_apply(): void
    {
        $this->testMovie[iState::COLUMN_WATCHED] = 0;

        $entity = new StateEntity($this->testMovie);
        $updated = $entity::fromArray($entity->getAll());
        $this->assertSame(
            $updated->getAll(),
            $entity->getAll(),
            'When entity is updated with itself, nothing should change'
        );

        $entity = new StateEntity($this->testMovie);
        $updated->title = 'Test';
        $entity->apply($updated, [iState::COLUMN_VIA]);
        $this->assertSame(
            [],
            $entity->diff(),
            'When apply() called with fields that do not contain changed keys, diff() returns empty array'
        );

        $entity = new StateEntity($this->testMovie);
        $updated = $entity::fromArray($entity->getAll());
        $updated->watched = 1;
        $updated->updated = 105;
        $entity->apply($updated);
        $this->assertSame(
            [
                iState::COLUMN_UPDATED => [
                    'old' => 1,
                    'new' => 105,
                ],
                iState::COLUMN_WATCHED => [
                    'old' => 0,
                    'new' => 1,
                ],
            ],
            $entity->diff(),
            'When apply() is called with no fields set, the updated fields from given entity are applied to current entity.'
        );

        $entity = new StateEntity($this->testMovie);
        $updated = $entity::fromArray($entity->getAll());
        $updated->title = 'Test';
        $updated->year = 2021;
        $updated->setMetadata([iState::COLUMN_ID => 1234]);

        $entity->apply($updated, [iState::COLUMN_TITLE, iState::COLUMN_META_DATA]);
        $this->assertSame(
            [
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
            ],
            $entity->diff(),
            'When apply() is called with fields that contain changed keys, only those fields are applied to current entity.'
        );

        $data1 = $this->testMovie;
        $data1[iState::COLUMN_ID] = 1;
        $data2 = $this->testMovie;
        $data2[iState::COLUMN_ID] = 2;

        $id1 = new StateEntity($data1);
        $id2 = new StateEntity($data2);

        $this->assertSame(1, $id1->apply($id2)->id, 'When apply() should not alter the object ID.');
    }

    public function test_updateOriginal(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertSame(
            $entity->getOriginalData(),
            $entity->getAll(),
            'When entity is created, getOriginalData() returns same data as getAll()'
        );

        $entity->watched = 0;
        $entity->updateOriginal();
        $this->assertSame(
            $entity->getOriginalData(),
            $entity->getAll(),
            'When entity is updated, and updateOriginal() is called getOriginalData() returns same data as getAll()'
        );
    }

    public function test_getOriginalData(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertSame(
            $entity->getOriginalData(),
            $entity->getAll(),
            'When entity is created, getOriginalData() returns same data as getAll()'
        );

        $entity->watched = 0;
        $this->assertNotSame(
            $entity->getOriginalData(),
            $entity->getAll(),
            'When entity is updated, getOriginalData() returns different data than getAll()'
        );
    }

    public function test_setIsTainted(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertFalse(
            $entity->isTainted(),
            'When entity is created, isTainted() returns false'
        );
        $entity->setIsTainted(true);
        $this->assertTrue(
            $entity->isTainted(),
            'When setIsTainted() is called with true, isTainted() returns true'
        );

        $this->checkException(
            closure: function () use ($entity) {
                /** @noinspection PhpStrictTypeCheckingInspection */
                return $entity->setIsTainted('foo');
            },
            reason: 'When setIsTainted() is called with invalid type, exception is thrown',
            exception: \TypeError::class,
        );
    }

    public function test_isTainted(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertFalse($entity->isTainted(), 'When entity is created, isTainted() returns false');

        $entity->setIsTainted(true);
        $this->assertTrue($entity->isTainted(), 'When setIsTainted() is called with true, isTainted() returns true');
    }

    public function test_getMetadata(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertSame(
            $this->testMovie[iState::COLUMN_META_DATA],
            $entity->getMetadata(),
            'getMetadata() returns all stored metadata in format of [ via => [ key => mixed ], via2 => [ key => mixed ] ]'
        );
        $this->assertSame(
            $this->testMovie[iState::COLUMN_META_DATA][$entity->via],
            $entity->getMetadata($entity->via),
            'getMetadata() called with via parameter returns metadata for that via in format of [ key => mixed ]'
        );
        $this->assertSame(
            [],
            $entity->getMetadata('not_set'),
            'getMetadata() called with via parameter that does not exist returns empty array'
        );
    }

    public function test_setMetadata(): void
    {
        $entity = new StateEntity($this->testMovie);
        $metadata = $entity->getMetadata($entity->via);
        $metadata[iState::COLUMN_META_DATA_PLAYED_AT] = 10;
        $entity->setMetadata([iState::COLUMN_META_DATA_PLAYED_AT => 10]);
        $this->assertSame(
            $metadata,
            $entity->getMetadata($entity->via),
            'setMetadata() Should recursively replace given metadata with existing metadata for given via'
        );
        $entity->setMetadata([]);
        $this->assertSame([],
            $entity->getMetadata($entity->via),
            'if setMetadata() called with empty array, getMetadata() returns empty array'
        );

        unset($this->testMovie[iState::COLUMN_VIA]);
        $entity = new StateEntity($this->testMovie);

        $this->checkException(
            closure: fn() => $entity->setMetadata([]),
            reason: 'When setMetadata() called with empty array, an exception is thrown',
            exception: RuntimeException::class,
        );
    }

    public function test_getExtra(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertSame(
            $this->testMovie[iState::COLUMN_EXTRA],
            $entity->getExtra(),
            'When getExtra() called with no via parameter, returns all stored extra data in format of [ via => [ key => mixed ], via2 => [ key => mixed ] ]'
        );
        $this->assertSame(
            $this->testMovie[iState::COLUMN_EXTRA][$entity->via],
            $entity->getExtra($entity->via),
            'When getExtra() called with via parameter, returns extra data for that via in format of [ key => mixed ]'
        );
        $this->assertSame(
            [],
            $entity->getMetadata('not_set'),
            'When getExtra() called with via parameter that does not exist, returns empty array'
        );
    }

    public function test_setExtra(): void
    {
        $entity = new StateEntity($this->testMovie);
        $extra = $entity->getExtra($entity->via);
        $extra[iState::COLUMN_EXTRA_EVENT] = 'foo';
        $entity->setExtra([iState::COLUMN_EXTRA_EVENT => 'foo']);
        $this->assertSame(
            $extra,
            $entity->getExtra($entity->via),
            'setExtra() Should recursively replace given extra data with existing extra data for given via'
        );

        $entity->setExtra([]);
        $this->assertSame(
            [],
            $entity->getExtra($entity->via),
            'if setExtra() called with empty array, getExtra() returns empty array'
        );

        unset($this->testMovie[iState::COLUMN_VIA]);
        $entity = new StateEntity($this->testMovie);
        $this->checkException(
            closure: fn() => $entity->setExtra([]),
            reason: 'When setExtra() called with empty array, an exception is thrown',
            exception: RuntimeException::class,
        );
    }

    public function test_shouldMarkAsUnplayed(): void
    {
        $data = $this->testMovie;
        $data[iState::COLUMN_WATCHED] = 0;
        $entity = new StateEntity($this->testMovie);
        // -- Condition 1: db entity not marked as watched.
        $this->assertFalse(
            $entity->shouldMarkAsUnplayed($entity),
            'When entity is not watched, shouldMarkAsUnplayed() returns false'
        );

        $entity = new StateEntity($this->testMovie);
        // -- Condition 2: backend entity not marked as unwatched.
        $this->assertFalse(
            $entity->shouldMarkAsUnplayed($entity),
            'When entity is watched, and backend entity is not marked as unwatched, shouldMarkAsUnplayed() returns false'
        );

        $entity = new StateEntity($this->testMovie);
        $data = $this->testMovie;
        $data[iState::COLUMN_WATCHED] = 0;
        unset($data[iState::COLUMN_META_DATA]);
        $updater = new StateEntity($data);
        // -- Condition 3: No metadata was set previously on records.
        $this->assertFalse(
            $entity->shouldMarkAsUnplayed($updater),
            'When entity is watched, and backend entity is marked as unwatched, and no metadata was set previously on records, shouldMarkAsUnplayed() returns false'
        );

        // -- Condition 4: Required metadata fields is missing.
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

        $this->assertFalse(
            StateEntity::fromArray($d[0])->shouldMarkAsUnplayed($updater),
            'When metadata id is missing, shouldMarkAsUnplayed() returns false'
        );
        $this->assertFalse(
            StateEntity::fromArray($d[1])->shouldMarkAsUnplayed($updater),
            'When metadata watched is missing, shouldMarkAsUnplayed() returns false'
        );
        $this->assertFalse(
            StateEntity::fromArray($d[2])->shouldMarkAsUnplayed($updater),
            'When metadata added date is missing, shouldMarkAsUnplayed() returns false'
        );
        $this->assertFalse(
            StateEntity::fromArray($d[3])->shouldMarkAsUnplayed($updater),
            'When metadata played date is missing, shouldMarkAsUnplayed() returns false'
        );

        // -- Condition 3: no metadata for via.
        $data1 = $this->testMovie;
        $data = $this->testMovie;
        $data[iState::COLUMN_VIA] = 'not_set';
        $data[iState::COLUMN_WATCHED] = 0;
        $this->assertFalse(
            StateEntity::fromArray($data1)->shouldMarkAsUnplayed(StateEntity::fromArray($data)),
            'When no metadata set for a backend, shouldMarkAsUnplayed() returns false'
        );

        // -- Condition 5: metadata played is false.
        $data = $this->testMovie;
        $data[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_WATCHED] = 0;
        $this->assertFalse(
            StateEntity::fromArray($data)->shouldMarkAsUnplayed($updater),
            'When metadata watched is false, shouldMarkAsUnplayed() returns false'
        );

        // -- Condition 7: metadata added date not equal to updated.
        $data = $this->testMovie;
        $data[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_META_DATA_ADDED_AT] = 124;
        $this->assertFalse(
            StateEntity::fromArray($data)->shouldMarkAsUnplayed($updater),
            'When metadata added date is not equal to updated, shouldMarkAsUnplayed() returns false'
        );

        // -- Finally, should update.
        $this->assertTrue(
            StateEntity::fromArray($this->testMovie)->shouldMarkAsUnplayed($updater),
            'When all 7 conditions are met shouldMarkAsUnplayed() returns true'
        );
    }

    public function test_markAsUnplayed(): void
    {
        $entity = new StateEntity($this->testMovie);
        $entity->via = 'tester';
        $entity->markAsUnplayed($entity);
        $entity->updated = 105;

        $this->assertFalse($entity->isWatched(), 'When markAsUnplayed() is called, isWatched() returns false');
        $this->assertSame(
            [
                'updated' => [
                    'old' => 1,
                    'new' => 105,
                ],
                'watched' => [
                    'old' => 1,
                    'new' => 0,
                ],
                'via' => [
                    'old' => 'test_plex',
                    'new' => 'tester',
                ],
            ],
            $entity->diff(),
            'When markAsUnplayed() is called, three mandatory fields are updated: (updated, watched and via)'
        );
    }

    public function test_hasPlayProgress(): void
    {
        $entity = new StateEntity($this->testMovie);
        $this->assertFalse(
            $entity->hasPlayProgress(),
            'When hasPlayProgress() when valid play progress is set, but the entity is marked as watched returns false'
        );

        $testData = ag_set($this->testMovie, iState::COLUMN_WATCHED, 0);
        $testData = ag_set($testData, 'metadata.test_plex.watched', 1);
        $entity = new StateEntity($testData);

        $this->assertFalse(
            $entity->hasPlayProgress(),
            'When hasPlayProgress() when valid play progress is set, but the entity server metadata is marked as watched returns false'
        );

        $testData = ag_set($this->testMovie, 'metadata.test_plex.watched', 0);
        $testData = ag_set($testData, 'metadata.test_plex.progress', 999);
        $entity = new StateEntity($testData);
        $this->assertFalse(
            $entity->hasPlayProgress(),
            'When hasPlayProgress() when all conditions are met except play progress is less than 1s, returns false'
        );

        $testData = ag_set($this->testMovie, 'watched', 0);
        $testData = ag_set($testData, 'metadata.test_plex.watched', 0);
        $testData = ag_set($testData, 'metadata.test_plex.progress', 5000);
        $entity = new StateEntity($testData);

        $this->assertTrue(
            $entity->hasPlayProgress(),
            'When hasPlayProgress() when valid play progress is set, returns true'
        );
    }

    public function test_getPlayProgress(): void
    {
        $testData = ag_set($this->testMovie, iState::COLUMN_WATCHED, 0);
        $testData = ag_set($testData, 'metadata.test_plex.watched', 0);
        $entity = new StateEntity($testData);
        $this->assertSame(
            5000,
            $entity->getPlayProgress(),
            'When hasPlayProgress() when valid play progress is set, returns true'
        );

        $testData = ag_set($this->testMovie, iState::COLUMN_WATCHED, 0);
        $testData = ag_set($testData, 'metadata.test_plex.watched', 0);
        $testData = ag_set($testData, 'metadata.test_plex', ag($testData, 'metadata.test_plex', []));
        $testData = ag_set($testData, 'metadata.test.progress', 999);

        $entity = new StateEntity($testData);
        $this->assertSame(
            5000,
            $entity->getPlayProgress(),
            'When hasPlayProgress() when valid play progress is set, returns true'
        );

        $testData[iState::COLUMN_WATCHED] = 1;
        $entity = new StateEntity($testData);
        $this->assertSame(0, $entity->getPlayProgress(), 'When entity is watched, getPlayProgress() returns 0');

        $testData = ag_set($this->testMovie, iState::COLUMN_WATCHED, 0);
        $testData = ag_set($testData, 'metadata.test_plex.watched', 1);
        $testData = ag_set($testData, 'metadata.test_plex', ag($testData, 'metadata.test_plex', []));
        $testData = ag_set($testData, 'metadata.test.progress', 999);
        $entity = new StateEntity($testData);
        $this->assertSame(0, $entity->getPlayProgress(), 'When entity is watched, getPlayProgress() returns 0');
    }

    public function test_context(): void
    {
        $entity = new StateEntity($this->testMovie);

        $ins = $entity->setContext('test', 'context');
        $this->assertSame($ins, $entity, 'When setContext() is called, it returns the same instance');
        $this->assertSame(
            'context',
            $entity->getContext('test'),
            'When getContext() is called, the same value is returned'
        );
        $this->assertSame(
            'iam_default',
            $entity->getContext(null, 'iam_default'),
            'When getContext() is called with default value, and key is null the default value is returned'
        );
        $this->assertSame(
            'iam_default',
            $entity->getContext('not_set', 'iam_default'),
            'When getContext() is called with non-existing key, the default value is returned'
        );
        $this->assertSame(
            ['test' => 'context'],
            $entity->getContext(),
            'When getContext() is called with no parameters, all context data is returned'
        );
        $this->assertTrue(
            $entity->hasContext('test'),
            'When hasContext() is called with existing key, it returns true'
        );
        $this->assertFalse(
            $entity->hasContext('not_set'),
            'When hasContext() is called with non-existing key, it returns false'
        );
    }

    public function test_getMeta(): void
    {
        $real = $this->testEpisode;
        $entity = new StateEntity($real);
        $entity->via = '';
        $this->assertSame(
            '__not_set',
            $entity->getMeta('extra.title', '__not_set'),
            'When no via is set, returns the default value'
        );

        $real = $this->testEpisode;

        unset($real['metadata']['test_jellyfin']);
        $entity = new StateEntity($real);

        $this->assertSame(
            ag($real, 'metadata.test_plex.extra.title'),
            $entity->getMeta('extra.title'),
            'When quorum is not met returns the entity via backend metadata.'
        );

        $entity->via = 'test_emby';
        $this->assertNotSame(
            ag($real, 'metadata.test_plex.extra.title'),
            $entity->getMeta('extra.title'),
            'When quorum is not met returns the entity via backend metadata.'
        );

        $entity = new StateEntity($this->testEpisode);

        $this->assertSame(
            ag($this->testEpisode, 'metadata.test_jellyfin.extra.title'),
            $entity->getMeta('extra.title'),
            'When quorum is met for key return that value instead of the default via metadata.'
        );

        $entity = new StateEntity(
            ag_set($this->testEpisode, 'metadata.test_jellyfin.extra.title', 'random')
        );

        $this->assertSame(
            ag($real, 'metadata.test_plex.extra.title'),
            $entity->getMeta('extra.title'),
            'When no quorum for value reached, return default via metadata.'
        );

        $entity = new StateEntity(
            ag_set($this->testEpisode, 'metadata.test_jellyfin.extra.title', null)
        );

        $this->assertSame(
            ag($real, 'metadata.test_plex.extra.title'),
            $entity->getMeta('extra.title'),
            'Quorum will not be met if one of the values is null.'
        );
    }

    public function test_updated_added_at_columns()
    {
        $data = $this->testMovie;

        $data[iState::COLUMN_CREATED_AT] = 0;
        $data[iState::COLUMN_UPDATED_AT] = 0;

        $entity = new StateEntity($data);

        $this->assertSame(
            $this->testMovie[iState::COLUMN_UPDATED],
            $entity->updated_at,
            'When entity is created with updated_at set to 0, updated_at is set to updated date from metadata'
        );

        $this->assertSame(
            $this->testMovie[iState::COLUMN_UPDATED],
            $entity->created_at,
            'When entity is created with created_at set to 0, created_at is set to updated date from metadata'
        );
    }

    public function test_decoding_array_fields()
    {
        $data = $this->testMovie;

        $data[iState::COLUMN_PARENT] = 'garbage data';
        $data[iState::COLUMN_GUIDS] = 'garbage data';
        $data[iState::COLUMN_META_DATA] = 'garbage data';
        $data[iState::COLUMN_EXTRA] = 'garbage data';

        $entity = new StateEntity($data);

        $this->assertSame([],
            $entity->getMetadata(),
            'When array keys are json decode fails, getMetadata() should returns empty array'
        );

        $this->assertSame([],
            $entity->getGuids(),
            'When array keys are json decode fails, getGuids() should returns empty array'
        );

        $this->assertSame([],
            $entity->getParentGuids(),
            'When array keys are json decode fails, getParentGuids() should returns empty array'
        );

        $this->assertSame([],
            $entity->getExtra(),
            'When array keys are json decode fails, getExtra() should returns empty array'
        );

        $this->assertSame([],
            $entity->getPointers(),
            'When array keys are json decode fails, getPointers() should returns empty array'
        );
    }
}
