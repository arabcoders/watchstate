<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetInfo as EmbyGetInfo;
use App\Backends\Jellyfin\Action\GetInfo as JellyfinGetInfo;

class GetInfoEmptyResponseTest extends MediaBrowserTestCase
{
    public function test_get_info_empty_response(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $response = $this->makeResponse('');
            $http = $this->makeHttpClient($response);
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context);

            $this->assertFalse($result->isSuccessful());
            $this->assertNotNull($result->error);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinGetInfo::class],
            ['Emby', EmbyGetInfo::class],
        ];
    }
}
