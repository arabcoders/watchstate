<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\GetMetaData;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class GetMetaDataTest extends PlexTestCase
{
    public function test_get_metadata_cached(): void
    {
        $payload = $this->fixture('library_movie_get_200');
        $response = $this->makeResponse($payload['response']['body']);
        $http = $this->makeHttpClient($response);
        $cache = new Psr16Cache(new ArrayAdapter());
        $context = $this->makeContext();

        $action = new GetMetaData($http, $this->logger, $cache);
        $first = $action($context, '1');
        $second = $action($context, '1');

        $this->assertTrue($first->isSuccessful());
        $this->assertFalse($first->extra['cached']);
        $this->assertTrue($second->extra['cached']);
    }

    public function test_get_metadata_error_status(): void
    {
        $response = $this->makeResponse(['error' => 'nope'], 500);
        $http = $this->makeHttpClient($response);
        $cache = new Psr16Cache(new ArrayAdapter());
        $context = $this->makeContext();

        $action = new GetMetaData($http, $this->logger, $cache);
        $result = $action($context, '1');

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }
}
