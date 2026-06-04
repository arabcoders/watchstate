<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\SearchQuery as EmbySearchQuery;
use App\Backends\Emby\EmbyGuid;
use App\Backends\Jellyfin\Action\SearchQuery as JellyfinSearchQuery;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Extends\MockHttpClient;

class SearchQueryErrorTest extends MediaBrowserTestCase
{
    public function test_search_query_error_status(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $response = $this->makeResponse(['error' => 'fail'], 500);
            $http = new MockHttpClient($response);
            $context = $this->makeContext($clientName);

            $db = $this->createDb($this->logger);

            $guid = new $guidClass($this->logger);
            $action = new $actionClass($http, $this->logger, $guid, $db);
            $result = $action($context, 'movie');

            $this->assertFalse($result->isSuccessful());
            $this->assertNotNull($result->error);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinSearchQuery::class, JellyfinGuid::class],
            ['Emby',     EmbySearchQuery::class,     EmbyGuid::class],
        ];
    }
}
