<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetLibrariesList as EmbyGetLibrariesList;
use App\Backends\Jellyfin\Action\GetLibrariesList as JellyfinGetLibrariesList;
use App\Libs\Extends\MockHttpClient;

class GetLibrariesListEmptyTest extends MediaBrowserTestCase
{
    public function test_get_libraries_list_empty_response(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $response = $this->makeResponse($this->fixture('libraries_empty'));
            $http = new MockHttpClient($response);
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
            ['Jellyfin', JellyfinGetLibrariesList::class],
            ['Emby', EmbyGetLibrariesList::class],
        ];
    }
}
