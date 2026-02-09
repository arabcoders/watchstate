<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetMetaData as EmbyGetMetaData;
use App\Backends\Jellyfin\Action\GetMetaData as JellyfinGetMetaData;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class GetMetaDataErrorTest extends MediaBrowserTestCase
{
    public function test_get_metadata_error_status(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $response = $this->makeResponse(['error' => 'nope'], 500);
            $http = $this->makeHttpClient($response);
            $cache = new Psr16Cache(new ArrayAdapter());
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger, $cache);
            $result = $action($context, 'item-1');

            $this->assertFalse($result->isSuccessful());
            $this->assertNotNull($result->error);
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
