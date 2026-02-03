<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\GetMetaData;
use App\Backends\Plex\Action\SearchId;
use App\Backends\Plex\PlexGuid;
use App\Libs\Container;
use App\Libs\Database\DBLayer;
use App\Libs\Database\PDO\PDOAdapter;
use PDO;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class SearchIdTest extends PlexTestCase
{
    public function test_search_id_success(): void
    {
        $payload = $this->fixture('library_movie_get_200');

        Container::add(GetMetaData::class, function () use ($payload) {
            $http = $this->makeHttpClient($this->makeResponse($payload['response']['body']));
            return new GetMetaData($http, $this->logger, new Psr16Cache(new ArrayAdapter()));
        });

        $context = $this->makeContext();
        $db = new PDOAdapter($this->logger, new DBLayer(new PDO('sqlite::memory:')));
        $db->migrations('up');

        $action = new SearchId($this->makeHttpClient(), $this->logger, $db, new PlexGuid($this->logger));
        $result = $action($context, '1');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('Ferengi: Rules of Acquisition', $result->response['title']);
    }
}
