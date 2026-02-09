<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\SearchId as EmbySearchId;
use App\Backends\Emby\EmbyGuid;
use App\Backends\Jellyfin\Action\GetMetaData;
use App\Backends\Jellyfin\Action\SearchId as JellyfinSearchId;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Container;
use App\Libs\Database\DBLayer;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Extends\MockHttpClient;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use PDO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class SearchIdTest extends MediaBrowserTestCase
{
    public function test_search_id_success(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $this->registerMetadataAction();

            $context = $this->makeContext($clientName);
            $http = new MockHttpClient();
            $db = new PDOAdapter($this->logger, new DBLayer(new PDO('sqlite::memory:')));
            $db->migrations('up');

            $guid = new $guidClass($this->logger);
            $action = new $actionClass($http, $this->logger, $guid, $db);
            $result = $action($context, 'item-1');

            $this->assertTrue($result->isSuccessful());
            $this->assertSame('Test Movie', $result->response['title']);
        }
    }

    private function registerMetadataAction(): void
    {
        Container::reinitialize();
        Container::add(StateInterface::class, fn() => new StateEntity([]));
        Container::add(LoggerInterface::class, fn() => $this->logger);

        $response = $this->makeResponse($this->fixture('metadata'));
        $http = $this->makeHttpClient($response);
        $cache = new Psr16Cache(new ArrayAdapter());

        Container::add(GetMetaData::class, fn() => new GetMetaData($http, $this->logger, $cache));
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinSearchId::class, JellyfinGuid::class],
            ['Emby', EmbySearchId::class, EmbyGuid::class],
        ];
    }
}
