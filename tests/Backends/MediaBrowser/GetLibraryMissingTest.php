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

class GetLibraryMissingTest extends MediaBrowserTestCase
{
    public function test_get_library_missing_id(): void
    {
        foreach ($this->provideBackends() as [$clientName, $getLibraryClass, $getLibrariesListClass, $guidClass]) {
            $this->registerLibrariesListAction($getLibrariesListClass);

            $response = $this->makeResponse($this->fixture('library_items'));
            $http = new MockHttpClient($response);
            $context = $this->makeContext($clientName);
            $guid = new $guidClass($this->logger);

            $action = new $getLibraryClass($http, $this->logger);
            $result = $action($context, $guid, 'missing-id');

            $this->assertFalse($result->isSuccessful());
            $this->assertNotNull($result->error);
        }
    }

    private function registerLibrariesListAction(string $actionClass): void
    {
        Container::add($actionClass, fn() => new $actionClass(new MockHttpClient($this->makeResponse($this->fixture('libraries'))), $this->logger));
        Container::add(\App\Backends\Jellyfin\Action\GetLibrariesList::class, fn() => new \App\Backends\Jellyfin\Action\GetLibrariesList(new MockHttpClient($this->makeResponse($this->fixture('libraries'))), $this->logger));
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinGetLibrary::class, JellyfinGetLibrariesList::class, JellyfinGuid::class],
            ['Emby', EmbyGetLibrary::class, EmbyGetLibrariesList::class, EmbyGuid::class],
        ];
    }
}
