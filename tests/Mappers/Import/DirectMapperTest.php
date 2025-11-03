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
        // -- Setup: Create a watched movie and save it to the database
        $testMovie = new StateEntity($this->testMovie);
        $this->mapper->add($testMovie);
        $this->mapper->commit();
        $this->mapper->reset()->loadData();

        // -- Verify item is watched
        $obj = $this->mapper->get($testMovie);
        $this->assertSame(1, $obj->watched, 'Initial state: item should be watched');

        // -- Create UserContext with DISABLE_MARK_UNPLAYED flag enabled
        $userContext = $this->createUserContext(
            name: 'test_plex',
            data: [
                'test_plex.options.' . Options::DISABLE_MARK_UNPLAYED => true
            ]
        );

        // -- Update the mapper to use the UserContext
        $mapperWithContext = $this->mapper->withUserContext($userContext);

        // -- Try to mark as unwatched
        $testMovie->watched = 0;
        $mapperWithContext->add($testMovie, ['after' => new \DateTimeImmutable('now')]);
        $mapperWithContext->commit();
        $mapperWithContext->reset()->loadData();

        // -- Verify item remains watched due to DISABLE_MARK_UNPLAYED flag
        $obj = $mapperWithContext->get($testMovie);
        $this->assertSame(
            1,
            $obj->watched,
            'With DISABLE_MARK_UNPLAYED flag enabled, item should remain watched'
        );

        // -- Now test without the flag (normal behavior)
        $userContextNoFlag = $this->createUserContext(
            name: 'test_plex_no_flag',
            data: []
        );

        // -- Reset and add the watched movie again
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

        // -- Try to mark as unwatched without the flag
        $testMovie2->watched = 0;
        $mapperNoFlag->add($testMovie2, ['after' => new \DateTimeImmutable('now')]);
        $mapperNoFlag->commit();
        $mapperNoFlag->reset()->loadData();

        // -- Verify item is now unwatched (normal behavior)
        $obj2 = $mapperNoFlag->get($testMovie2);
        $this->assertSame(
            0,
            $obj2->watched,
            'Without DISABLE_MARK_UNPLAYED flag, item should be marked as unwatched'
        );
    }
}
