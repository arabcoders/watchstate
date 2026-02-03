<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\GetImagesUrl;
use App\Backends\Plex\Action\GetMetaData;
use App\Libs\Container;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class GetImagesUrlTest extends PlexTestCase
{
    public function test_get_images_url_success(): void
    {
        $payload = $this->fixture('library_movie_get_200');

        Container::add(GetMetaData::class, function () use ($payload) {
            $http = $this->makeHttpClient($this->makeResponse($payload['response']['body']));
            return new GetMetaData($http, $this->logger, new Psr16Cache(new ArrayAdapter()));
        });

        $context = $this->makeContext();

        $action = new GetImagesUrl();
        $result = $action($context, '1');

        $this->assertTrue($result->isSuccessful());
        $this->assertStringContainsString('/library/metadata/1/thumb', (string) $result->response['poster']);
        $this->assertStringContainsString('/library/metadata/1/art', (string) $result->response['background']);
    }

    public function test_get_images_url_error(): void
    {
        Container::add(GetMetaData::class, fn() => new class() {
            public function __invoke(\App\Backends\Common\Context $context, string|int $id, array $opts = []): \App\Backends\Common\Response
            {
                return new \App\Backends\Common\Response(status: false);
            }
        });

        $context = $this->makeContext();
        $action = new GetImagesUrl();
        $result = $action($context, '1');

        $this->assertFalse($result->isSuccessful());
    }
}
