<?php

declare(strict_types=1);

namespace Tests\Mappers\Import;

use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Options;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Psr16Cache;

class DirectMapperTest extends MapperAbstract
{
    protected function setupMapper(): ImportInterface
    {
        $mapper = new DirectMapper($this->logger, $this->db, cache: new Psr16Cache(new NullAdapter()));
        $mapper->setOptions(options: ['class' => new StateEntity([])]);
        return $mapper;
    }

    public function test_mapper_with_disable_mark_unplayed_option(): void
    {
        $testMovie = new StateEntity($this->testMovie);
        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        $obj = $this->mapper->get($testMovie);
        $this->assertSame(1, $obj->watched, 'Initial state: item should be watched');

        $userContext = $this->createUserContext(
            name: 'test_plex',
            data: [
                'test_plex.options.' . Options::DISABLE_MARK_UNPLAYED => true,
            ],
        );

        $mapperWithContext = $this->mapper->withUserContext($userContext);

        $testMovie->watched = 0;
        $mapperWithContext->add($testMovie, ['after' => new \DateTimeImmutable('now')]);
        $mapperWithContext->commit();
        $mapperWithContext->reset()->loadData();

        $obj = $mapperWithContext->get($testMovie);
        $this->assertSame(
            1,
            $obj->watched,
            'With DISABLE_MARK_UNPLAYED flag enabled, item should remain watched',
        );

        $userContextNoFlag = $this->createUserContext(
            name: 'test_plex_no_flag',
            data: [],
        );

        $this->testMovie[iState::COLUMN_VIA] = 'test_plex_no_flag';
        $this->testMovie = ag_set(
            $this->testMovie,
            'metadata.test_plex_no_flag',
            $this->testMovie['metadata']['test_plex'],
        );
        unset($this->testMovie['metadata']['test_plex']);

        $testMovie2 = new StateEntity($this->testMovie);
        $mapperNoFlag = $this->mapper->withUserContext($userContextNoFlag);
        $mapperNoFlag->add($testMovie2);
        $mapperNoFlag->commit();
        $mapperNoFlag->reset()->loadData();

        $testMovie2->watched = 0;
        $mapperNoFlag->add($testMovie2, ['after' => new \DateTimeImmutable('now')]);
        $mapperNoFlag->commit();
        $mapperNoFlag->reset()->loadData();

        $obj2 = $mapperNoFlag->get($testMovie2);
        $this->assertSame(
            0,
            $obj2->watched,
            'Without DISABLE_MARK_UNPLAYED flag, item should be marked as unwatched',
        );
    }

    public function test_skip_state_prevents_progress_events(): void
    {
        $this->testMovie[iState::COLUMN_WATCHED] = 0;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_META_DATA_PROGRESS] = 100;
        $testMovie = new StateEntity($this->testMovie);

        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        $progressEventCalled = false;
        $progressCallback = function () use (&$progressEventCalled) {
            $progressEventCalled = true;
        };

        $updatedMovie = clone $testMovie;
        $updatedMovie->watched = 0;
        $metadata = $updatedMovie->getMetadata();
        $metadata[$testMovie->via][iState::COLUMN_META_DATA_PROGRESS] = 150;
        $updatedMovie->metadata = $metadata;
        $updatedMovie->setIsTainted(true);

        $this->mapper->add($updatedMovie, [Options::STATE_PROGRESS_EVENT => $progressCallback]);
        $this->mapper->commit();

        $this->assertTrue(
            $progressEventCalled,
            'Progress event callback should be called when progress changes significantly',
        );

        $this->mapper->reset()->loadData();
        $stored = $this->mapper->get($updatedMovie);
        $storedProgress = (int) ag($stored->getMetadata($testMovie->via), iState::COLUMN_META_DATA_PROGRESS, 0);
        $this->assertSame(
            150,
            $storedProgress,
            'Progress should be saved to database when progress event is triggered',
        );
    }

    public function test_progress_event_triggered_with_tainted_update(): void
    {
        $currentTime = time();
        $this->testMovie[iState::COLUMN_WATCHED] = 0;
        $this->testMovie[iState::COLUMN_UPDATED] = $currentTime - 7200;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_META_DATA_PROGRESS] = 0;
        $testMovie = new StateEntity($this->testMovie);

        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        $progressEventCallCount = 0;
        $progressCallback = function () use (&$progressEventCallCount) {
            $progressEventCallCount++;
        };

        $testMovie->watched = 0;
        $testMovie->updated = $currentTime - 1800;
        $metadata = $testMovie->getMetadata();
        $metadata[$testMovie->via][iState::COLUMN_META_DATA_PROGRESS] = 50;
        $testMovie->metadata = $metadata;
        $testMovie->setIsTainted(true);

        $this->mapper->add($testMovie, [
            Options::STATE_PROGRESS_EVENT => $progressCallback,
        ]);
        $this->mapper->commit();

        $this->assertSame(
            1,
            $progressEventCallCount,
            'Progress event should be called exactly once when progress increases significantly',
        );

        $this->mapper->reset()->loadData();
        $stored = $this->mapper->get($testMovie);
        $storedProgress = (int) ag($stored->getMetadata($testMovie->via), iState::COLUMN_META_DATA_PROGRESS, 0);
        $this->assertSame(
            50,
            $storedProgress,
            'Progress should be saved to database',
        );
    }

    /**
     * @throws \DateMalformedStringException
     */
    public function test_skip_state_scenario_with_watch_state_conflict(): void
    {
        $currentTime = time();
        $this->testMovie[iState::COLUMN_WATCHED] = 1;
        $this->testMovie[iState::COLUMN_UPDATED] = $currentTime - 7200;
        $testMovie = new StateEntity($this->testMovie);

        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        $progressEventCallCount = 0;
        $progressCallback = function () use (&$progressEventCallCount) {
            $progressEventCallCount++;
        };

        $skipStateCallCount = 0;
        $skipStateEntity = null;
        $skipStateCallback = function (iState $entity) use (&$skipStateCallCount, &$skipStateEntity) {
            $skipStateCallCount++;
            $skipStateEntity = $entity;
        };

        $testMovie->watched = 0;
        $testMovie->updated = $currentTime - 3600;
        $afterDate = new \DateTimeImmutable('@' . ($currentTime - 1800));

        $this->mapper->add($testMovie, [
            'after' => $afterDate,
            Options::STATE_PROGRESS_EVENT => $progressCallback,
            Options::ON_SKIP_STATE => $skipStateCallback,
        ]);
        $this->mapper->commit();

        $this->assertSame(
            1,
            $skipStateCallCount,
            'ON_SKIP_STATE callback should be called exactly once when SKIP_STATE is auto-set by DirectMapper',
        );

        $this->assertNotNull($skipStateEntity, 'Entity should be passed to ON_SKIP_STATE callback');
        $this->assertTrue($skipStateEntity->isTainted(), 'Entity should be marked as tainted');
        $this->assertSame(0, $skipStateEntity->watched, 'Entity watch state should be 0 (unwatched)');

        $this->assertSame(
            0,
            $progressEventCallCount,
            'Progress event should NOT be called in SKIP_STATE scenario (no progress change + SKIP_STATE blocks events)',
        );

        $this->mapper->reset()->loadData();
        $stored = $this->mapper->get($testMovie);
        $this->assertNotNull($stored, 'Item should be stored in the database');

        $this->assertSame(
            1,
            $stored->watched,
            'Watch state should remain unchanged (1) because tainted processing only updates metadata',
        );

        $storedMetadata = $stored->getMetadata($testMovie->via);
        $this->assertNotEmpty(
            $storedMetadata,
            'Metadata should exist for the backend',
        );

        $this->assertArrayHasKey(
            iState::COLUMN_META_DATA_PLAYED_AT,
            $storedMetadata,
            'PLAYED_AT should be set in metadata during SKIP_STATE scenario',
        );
    }

    /**
     * Test force-full mode bypasses timestamp check and updates watch state
     *
     * @throws \DateMalformedStringException
     */
    public function test_force_full_updates_state_despite_old_timestamp(): void
    {
        $currentTime = time();
        // Local DB has item as unplayed, updated recently
        $this->testMovie[iState::COLUMN_WATCHED] = 0;
        $this->testMovie[iState::COLUMN_UPDATED] = $currentTime - 1800; // 30 mins ago
        $testMovie = new StateEntity($this->testMovie);

        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        // Backend reports item as played, but with OLD timestamp (1 year ago)
        $testMovie->watched = 1;
        $testMovie->updated = $currentTime - 31536000; // 1 year ago
        $metadata = $testMovie->getMetadata();
        $metadata[$testMovie->via][iState::COLUMN_META_DATA_PLAYED_AT] = $testMovie->updated;
        $testMovie->metadata = $metadata;

        // Import with FORCE_FULL option (as backend would pass it)
        $this->mapper->add($testMovie, [Options::FORCE_FULL => true]);
        $this->mapper->commit();

        $this->mapper->reset()->loadData();
        $stored = $this->mapper->get($testMovie);
        $this->assertNotNull($stored, 'Item should be stored in the database');

        $this->assertSame(
            1,
            $stored->watched,
            'Watch state should be updated to 1 (played) in force-full mode despite old remote timestamp',
        );

        $storedMetadata = $stored->getMetadata($testMovie->via);
        $this->assertArrayHasKey(
            iState::COLUMN_META_DATA_PLAYED_AT,
            $storedMetadata,
            'PLAYED_AT should be set in metadata',
        );
        $this->assertSame(
            $testMovie->updated,
            $storedMetadata[iState::COLUMN_META_DATA_PLAYED_AT],
            'PLAYED_AT should match the remote timestamp',
        );
    }

    /**
     * Test that add() flows to handleUntaintedEntity() and triggers shouldMarkAsUnplayed()
     *
     * Flow: add() -> handleUntaintedEntity() -> shouldMarkAsUnplayed() -> markAsUnplayed()
     */
    public function test_add_flows_to_handleUntaintedEntity_shouldMarkAsUnplayed_true(): void
    {
        $currentTime = time();

        $this->testMovie[iState::COLUMN_WATCHED] = 1;
        $this->testMovie[iState::COLUMN_UPDATED] = $currentTime;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_ID] = 121;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_WATCHED] = 1;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_META_DATA_ADDED_AT] = $currentTime;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_META_DATA_PLAYED_AT] =
            $currentTime - 100;

        $testMovie = new StateEntity($this->testMovie);

        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        $stored = $this->mapper->get($testMovie);
        $this->assertSame(1, $stored->watched, 'Initial state should be watched');

        $MAUCallCount = 0;
        $MAUTriggered = null;
        $MAULocal = null;
        $TMAUCallBack = function ($triggered, $local) use (&$MAUCallCount, &$MAUTriggered, &$MAULocal) {
            $MAUCallCount++;
            $MAUTriggered = $triggered;
            $MAULocal = $local;
        };

        // Create unwatched update entity with updated time matching added_at
        // This satisfies condition 7 of shouldMarkAsUnplayed()
        $updateMovie = clone $testMovie;
        $updateMovie->watched = 0;
        $updateMovie->updated = $currentTime; // Matches added_at, triggers shouldMarkAsUnplayed condition 7

        // Update metadata to ensure changes are detected
        $metadata = $updateMovie->getMetadata();
        $metadata[$updateMovie->via][iState::COLUMN_WATCHED] = 0; // Backend reports unwatched
        $metadata[$updateMovie->via][iState::COLUMN_META_DATA_PLAYED_AT] = $currentTime; // Update played_at
        $updateMovie->metadata = $metadata;

        $this->handler->clear();

        // Call add() WITHOUT 'after' date - this routes to handleUntaintedEntity()
        // NOT tainted, NOT metadata_only, entity exists in DB
        $this->mapper->add($updateMovie, [
            'test_mark_as_unplayed' => $TMAUCallBack,
        ]);
        $this->mapper->commit();

        $this->assertSame(1, $MAUCallCount, 'test_mark_as_unplayed callback should be called exactly once.');
        $this->assertTrue($MAUTriggered, 'First parameter should be true');
        $this->assertSame(0, $MAULocal->watched, 'Item should be unwatched.');

        // Verify final state in database
        $this->mapper->reset()->loadData();
        $result = $this->mapper->get($updateMovie);
        $this->assertSame(0, $result->watched, 'Item should be marked as unwatched');
    }

    /**
     * Test that add() flows to handleUntaintedEntity() but shouldMarkAsUnplayed() returns false
     * when DISABLE_MARK_UNPLAYED flag is set, preventing STATE_UPDATE_EVENT from being called.
     */
    public function test_add_flows_to_handleUntaintedEntity_but_shouldMarkAsUnplayed_blocked_by_disable_flag(): void
    {
        $currentTime = time();

        $this->testMovie[iState::COLUMN_WATCHED] = 1;
        $this->testMovie[iState::COLUMN_UPDATED] = $currentTime;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_ID] = 121;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_WATCHED] = 1;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_META_DATA_ADDED_AT] = $currentTime;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_META_DATA_PLAYED_AT] =
            $currentTime - 100;

        $testMovie = new StateEntity($this->testMovie);
        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        $userContext = $this->createUserContext(
            name: 'test_plex',
            data: [
                'test_plex.options.' . Options::DISABLE_MARK_UNPLAYED => true,
            ],
        );

        $mapperWithContext = $this->mapper->withUserContext($userContext);

        $MAUCallCount = 0;
        $TMAUCallBack = function ($triggered, $local) use (&$MAUCallCount) {
            $MAUCallCount++;
        };

        // Create unwatched update
        $updateMovie = clone $testMovie;
        $updateMovie->watched = 0;
        $updateMovie->updated = $currentTime;

        // Update metadata to ensure changes are detected
        $metadata = $updateMovie->getMetadata();
        $metadata[$updateMovie->via][iState::COLUMN_WATCHED] = 0;
        $metadata[$updateMovie->via][iState::COLUMN_META_DATA_PLAYED_AT] = $currentTime;
        $updateMovie->metadata = $metadata;

        $this->handler->clear();
        $mapperWithContext->add($updateMovie, ['test_mark_as_unplayed' => $TMAUCallBack]);
        $mapperWithContext->commit();

        $this->assertSame(
            0,
            $MAUCallCount,
            'test_mark_as_unplayed callback should NOT be called when DISABLE_MARK_UNPLAYED flag makes shouldMarkAsUnplayed return false',
        );

        $mapperWithContext->reset()->loadData();
        $result = $mapperWithContext->get($updateMovie);
        $this->assertSame(1, $result->watched, 'Item should remain watched when DISABLE_MARK_UNPLAYED flag is set');
    }

    /**
     * Verify that add() routes to handleOldEntity (not handleUntaintedEntity) when 'after' date
     * is newer than entity.updated. This is a control test to show path differentiation.
     * @throws \DateMalformedStringException
     */
    public function test_add_routes_to_handleOldEntity_not_handleUntaintedEntity_when_old_date(): void
    {
        $currentTime = time();

        // Setup initial watched movie
        $this->testMovie[iState::COLUMN_WATCHED] = 1;
        $this->testMovie[iState::COLUMN_UPDATED] = $currentTime - 3600;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_ID] = 121;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_WATCHED] = 1;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_META_DATA_ADDED_AT] =
            $currentTime - 3600;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_META_DATA_PLAYED_AT] =
            $currentTime - 3600;

        $testMovie = new StateEntity($this->testMovie);
        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        $MAUCallCount = 0;
        $MAULocal = null;
        $TMAUCallBack = function ($triggered, $local) use (&$MAUCallCount, &$MAULocal) {
            $MAUCallCount++;
            $MAULocal = $local;
        };

        $updateMovie = clone $testMovie;
        $updateMovie->watched = 0;
        $updateMovie->updated = $currentTime - 3600; // Matches added_at

        $this->handler->clear();
        $this->mapper->add($updateMovie, [
            'after' => new \DateTimeImmutable('@' . ($currentTime - 1800)),
            'test_mark_as_unplayed' => $TMAUCallBack,
        ]);
        $this->mapper->commit();

        $this->assertSame(1, $MAUCallCount, 'test_mark_as_unplayed callback should be called');
        $this->assertSame(0, $MAULocal->watched, 'Local entity should be unwatched after markAsUnplayed');
        $this->mapper->reset()->loadData();
        $result = $this->mapper->get($updateMovie);
        $this->assertSame(0, $result->watched, 'Item should be marked as unwatched via handleOldEntity path');
    }
}
