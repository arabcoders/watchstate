<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetMetaData as EmbyGetMetaData;
use App\Backends\Jellyfin\Action\GetMetaData as JellyfinGetMetaData;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class GetMetaDataTest extends MediaBrowserTestCase
{
    public function test_get_metadata_uses_cache(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $response = $this->makeResponse($this->fixture('metadata'));
            $http = $this->makeHttpClient($response);
            $cache = new Psr16Cache(new ArrayAdapter());
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger, $cache);
            $result = $action($context, 'item-1');
            $cached = $action($context, 'item-1');

            $this->assertTrue($result->isSuccessful());
            $this->assertFalse($result->extra['cached']);
            $this->assertTrue($cached->extra['cached']);
            $this->assertSame('item-1', $result->response['Id']);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinGetMetaData::class],
            ['Emby', EmbyGetMetaData::class],
        ];
    }
}
