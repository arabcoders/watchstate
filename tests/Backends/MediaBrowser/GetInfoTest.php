<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetInfo as EmbyGetInfo;
use App\Backends\Jellyfin\Action\GetInfo as JellyfinGetInfo;

class GetInfoTest extends MediaBrowserTestCase
{
    public function test_get_info_success(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $response = $this->makeResponse($this->fixture('info'));
            $http = $this->makeHttpClient($response);
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame('MediaBrowser Test', $result->response['name']);
            $this->assertSame('10.9.0', $result->response['version']);
            $this->assertSame('server-1', $result->response['identifier']);
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
