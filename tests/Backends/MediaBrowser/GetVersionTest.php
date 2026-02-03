<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetVersion as EmbyGetVersion;
use App\Backends\Jellyfin\Action\GetVersion as JellyfinGetVersion;

class GetVersionTest extends MediaBrowserTestCase
{
    public function test_get_version_success(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $response = $this->makeResponse($this->fixture('info'));
            $http = $this->makeHttpClient($response);
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame('10.9.0', $result->response);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinGetVersion::class],
            ['Emby', EmbyGetVersion::class],
        ];
    }
}
