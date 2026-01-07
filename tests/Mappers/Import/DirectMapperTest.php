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
                'test_plex.options.' . Options::DISABLE_MARK_UNPLAYED => true
            ]
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
            'With DISABLE_MARK_UNPLAYED flag enabled, item should remain watched'
        );

        $userContextNoFlag = $this->createUserContext(
            name: 'test_plex_no_flag',
            data: []
        );

        $this->testMovie[iState::COLUMN_VIA] = 'test_plex_no_flag';
        $this->testMovie = ag_set(
            $this->testMovie,
            'metadata.test_plex_no_flag',
            $this->testMovie['metadata']['test_plex']
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
            'Without DISABLE_MARK_UNPLAYED flag, item should be marked as unwatched'
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
            'Progress event callback should be called when progress changes significantly'
        );

        $this->mapper->reset()->loadData();
        $stored = $this->mapper->get($updatedMovie);
        $storedProgress = (int)ag($stored->getMetadata($testMovie->via), iState::COLUMN_META_DATA_PROGRESS, 0);
        $this->assertSame(
            150,
            $storedProgress,
            'Progress should be saved to database when progress event is triggered'
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
            Options::STATE_PROGRESS_EVENT => $progressCallback
        ]);
        $this->mapper->commit();

        $this->assertSame(
            1,
            $progressEventCallCount,
            'Progress event should be called exactly once when progress increases significantly'
        );

        $this->mapper->reset()->loadData();
        $stored = $this->mapper->get($testMovie);
        $storedProgress = (int)ag($stored->getMetadata($testMovie->via), iState::COLUMN_META_DATA_PROGRESS, 0);
        $this->assertSame(
            50,
            $storedProgress,
            'Progress should be saved to database'
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
            Options::ON_SKIP_STATE => $skipStateCallback
        ]);
        $this->mapper->commit();

        $this->assertSame(
            1,
            $skipStateCallCount,
            'ON_SKIP_STATE callback should be called exactly once when SKIP_STATE is auto-set by DirectMapper'
        );

        $this->assertNotNull($skipStateEntity, 'Entity should be passed to ON_SKIP_STATE callback');
        $this->assertTrue($skipStateEntity->isTainted(), 'Entity should be marked as tainted');
        $this->assertSame(0, $skipStateEntity->watched, 'Entity watch state should be 0 (unwatched)');

        $this->assertSame(
            0,
            $progressEventCallCount,
            'Progress event should NOT be called in SKIP_STATE scenario (no progress change + SKIP_STATE blocks events)'
        );

        $this->mapper->reset()->loadData();
        $stored = $this->mapper->get($testMovie);
        $this->assertNotNull($stored, 'Item should be stored in the database');

        $this->assertSame(
            1,
            $stored->watched,
            'Watch state should remain unchanged (1) because tainted processing only updates metadata'
        );

        $storedMetadata = $stored->getMetadata($testMovie->via);
        $this->assertNotEmpty(
            $storedMetadata,
            'Metadata should exist for the backend'
        );

        $this->assertArrayHasKey(
            iState::COLUMN_META_DATA_PLAYED_AT,
            $storedMetadata,
            'PLAYED_AT should be set in metadata during SKIP_STATE scenario'
        );
    }
}
