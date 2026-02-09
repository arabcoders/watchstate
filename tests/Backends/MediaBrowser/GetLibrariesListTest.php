<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetLibrariesList as EmbyGetLibrariesList;
use App\Backends\Jellyfin\Action\GetLibrariesList as JellyfinGetLibrariesList;
use App\Libs\Extends\MockHttpClient;

class GetLibrariesListTest extends MediaBrowserTestCase
{
    public function test_get_libraries_list_success(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $expectedFragment]) {
            $response = $this->makeResponse($this->fixture('libraries'));
            $http = new MockHttpClient($response);
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context);

            $this->assertTrue($result->isSuccessful());
            $this->assertCount(2, $result->response);
            $this->assertStringContainsString($expectedFragment, $result->response[0]['webUrl']);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinGetLibrariesList::class, '/movies.html?topParentId=lib-1'],
            ['Emby', EmbyGetLibrariesList::class, '!/videos?serverId=backend-1&parentId=lib-1'],
        ];
    }
}
