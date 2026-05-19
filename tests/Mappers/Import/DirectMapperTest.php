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

    public function test_skip_state_no_progress(): void
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

    /**
     * @throws \DateMalformedStringException
     */
    public function test_skip_state_conflict(): void
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
    public function test_force_full_old_ts(): void
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

    public function test_untainted_unwatch_update(): void
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

        $updateMovie = clone $testMovie;
        $updateMovie->watched = 0;
        $updateMovie->updated = $currentTime;

        $metadata = $updateMovie->getMetadata();
        $metadata[$updateMovie->via][iState::COLUMN_WATCHED] = 0;
        $metadata[$updateMovie->via][iState::COLUMN_META_DATA_PLAYED_AT] = $currentTime;
        $updateMovie->metadata = $metadata;

        $this->mapper->add($updateMovie);
        $this->mapper->commit();

        $this->mapper->reset()->loadData();
        $result = $this->mapper->get($updateMovie);
        $this->assertSame(0, $result->watched, 'Item should be marked as unwatched');
    }

    public function test_force_metadata_change(): void
    {
        $this->testMovie[iState::COLUMN_WATCHED] = 0;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_WATCHED] = 0;
        $this->testMovie[iState::COLUMN_META_DATA]['test_jellyfin'] = [
            iState::COLUMN_ID => 777,
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_WATCHED => 0,
            iState::COLUMN_META_DATA_PROGRESS => 65000,
        ];

        $testMovie = new StateEntity($this->testMovie);
        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        $updatedMovie = clone $testMovie;
        $metadata = $updatedMovie->getMetadata();
        unset($metadata[$updatedMovie->via][iState::COLUMN_META_DATA_PROGRESS]);
        unset($metadata[$updatedMovie->via][iState::COLUMN_YEAR]);
        $updatedMovie->metadata = $metadata;

        $progressEventCalled = false;
        $progressValue = null;
        $progressCallback = function (iState $entity) use (&$progressEventCalled, &$progressValue) {
            $progressEventCalled = true;
            $progressValue = $entity->getContext(Options::STATE_PROGRESS_VALUE);
        };

        $this->mapper->add($updatedMovie, [
            Options::FORCE_REPLACE_METADATA => true,
            Options::STATE_PROGRESS_EVENT => $progressCallback,
        ]);
        $this->mapper->commit();

        $this->assertTrue($progressEventCalled, 'Progress reset should queue progress event');
        $this->assertSame(0, $progressValue, 'Progress reset should export zero progress');

        $this->mapper->reset()->loadData();
        $stored = $this->mapper->get($updatedMovie);
        $storedMetadata = $stored->getMetadata($updatedMovie->via);

        $this->assertArrayNotHasKey(iState::COLUMN_META_DATA_PROGRESS, $storedMetadata);
        $this->assertArrayNotHasKey(iState::COLUMN_YEAR, $storedMetadata);
        $this->assertSame(65000, ag($stored->getMetadata('test_jellyfin'), iState::COLUMN_META_DATA_PROGRESS));
    }

    public function test_force_progress_change(): void
    {
        $this->testMovie[iState::COLUMN_WATCHED] = 0;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_WATCHED] = 0;

        $testMovie = new StateEntity($this->testMovie);
        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        $updatedMovie = clone $testMovie;
        $metadata = $updatedMovie->getMetadata();
        $metadata[$updatedMovie->via][iState::COLUMN_META_DATA_PROGRESS] = 50000;
        $updatedMovie->metadata = $metadata;

        $progressEventCalled = false;
        $progressValue = null;
        $progressCallback = function (iState $entity) use (&$progressEventCalled, &$progressValue) {
            $progressEventCalled = true;
            $progressValue = $entity->getContext(Options::STATE_PROGRESS_VALUE);
        };

        $this->mapper->add($updatedMovie, [
            Options::FORCE_REPLACE_METADATA => true,
            Options::STATE_PROGRESS_EVENT => $progressCallback,
        ]);
        $this->mapper->commit();

        $this->assertTrue($progressEventCalled, 'Forced progress change should queue progress event');
        $this->assertSame(50000, $progressValue, 'Forced progress change should export source progress');

        $this->mapper->reset()->loadData();
        $stored = $this->mapper->get($updatedMovie);

        $this->assertSame(50000, ag($stored->getMetadata($updatedMovie->via), iState::COLUMN_META_DATA_PROGRESS));
    }

    public function test_force_progress_same(): void
    {
        $this->testMovie[iState::COLUMN_WATCHED] = 0;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_WATCHED] = 0;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_META_DATA_PROGRESS] = 0;

        $testMovie = new StateEntity($this->testMovie);
        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        $updatedMovie = clone $testMovie;
        $metadata = $updatedMovie->getMetadata();
        unset($metadata[$updatedMovie->via][iState::COLUMN_YEAR]);
        $updatedMovie->metadata = $metadata;

        $progressEventCalled = false;
        $progressCallback = function () use (&$progressEventCalled) {
            $progressEventCalled = true;
        };

        $this->mapper->add($updatedMovie, [
            Options::FORCE_REPLACE_METADATA => true,
            Options::STATE_PROGRESS_EVENT => $progressCallback,
        ]);
        $this->mapper->commit();

        $this->assertFalse($progressEventCalled, 'Unchanged zero progress should not queue progress event');

        $this->mapper->reset()->loadData();
        $stored = $this->mapper->get($updatedMovie);

        $this->assertArrayNotHasKey(iState::COLUMN_YEAR, $stored->getMetadata($updatedMovie->via));
    }

    public function test_no_force_metadata_change(): void
    {
        $this->testMovie[iState::COLUMN_WATCHED] = 0;
        $this->testMovie[iState::COLUMN_META_DATA][$this->testMovie[iState::COLUMN_VIA]][iState::COLUMN_WATCHED] = 0;

        $testMovie = new StateEntity($this->testMovie);
        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        $updatedMovie = clone $testMovie;
        $metadata = $updatedMovie->getMetadata();
        unset($metadata[$updatedMovie->via][iState::COLUMN_META_DATA_PROGRESS]);
        unset($metadata[$updatedMovie->via][iState::COLUMN_YEAR]);
        $updatedMovie->metadata = $metadata;

        $this->mapper->add($updatedMovie);
        $this->mapper->commit();

        $this->mapper->reset()->loadData();
        $stored = $this->mapper->get($updatedMovie);
        $storedMetadata = $stored->getMetadata($updatedMovie->via);

        $this->assertArrayHasKey(iState::COLUMN_META_DATA_PROGRESS, $storedMetadata);
        $this->assertArrayHasKey(iState::COLUMN_YEAR, $storedMetadata);
    }
}
