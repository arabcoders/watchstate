<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetLibrariesList as EmbyGetLibrariesList;
use App\Backends\Emby\Action\GetLibrary as EmbyGetLibrary;
use App\Backends\Emby\EmbyGuid;
use App\Backends\Jellyfin\Action\GetLibrariesList as JellyfinGetLibrariesList;
use App\Backends\Jellyfin\Action\GetLibrary as JellyfinGetLibrary;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Container;
use App\Libs\Extends\MockHttpClient;

class GetLibraryTest extends MediaBrowserTestCase
{
    public function test_get_library_success(): void
    {
        foreach ($this->provideBackends() as [$clientName, $getLibraryClass, $getLibrariesListClass, $guidClass]) {
            $this->registerLibrariesListAction($getLibrariesListClass);

            $response = $this->makeResponse($this->fixture('library_items'));
            $http = new MockHttpClient($response);
            $context = $this->makeContext($clientName);
            $guid = new $guidClass($this->logger);

            $action = new $getLibraryClass($http, $this->logger);
            $result = $action($context, $guid, 'lib-1');

            $this->assertTrue($result->isSuccessful());
            $this->assertCount(1, $result->response);
            $this->assertSame('Test Movie', $result->response[0]['title']);
        }
    }

    private function registerLibrariesListAction(string $actionClass): void
    {
        Container::reinitialize();

        $response = $this->makeResponse($this->fixture('libraries'));
        $http = new MockHttpClient($response);

        Container::add($actionClass, fn() => new $actionClass($http, $this->logger));
        Container::add(\App\Backends\Jellyfin\Action\GetLibrariesList::class, fn() => new \App\Backends\Jellyfin\Action\GetLibrariesList($http, $this->logger));
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinGetLibrary::class, JellyfinGetLibrariesList::class, JellyfinGuid::class],
            ['Emby', EmbyGetLibrary::class, EmbyGetLibrariesList::class, EmbyGuid::class],
        ];
    }
}
