<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetSessions as EmbyGetSessions;
use App\Backends\Jellyfin\Action\GetSessions as JellyfinGetSessions;
use App\Libs\Extends\MockHttpClient;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class GetSessionsTest extends MediaBrowserTestCase
{
    public function test_get_sessions_success(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $response = $this->makeResponse($this->fixture('sessions'));
            $http = new MockHttpClient($response);
            $cache = new Psr16Cache(new ArrayAdapter());
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger, $cache);
            $result = $action($context);

            $this->assertTrue($result->isSuccessful());
            $this->assertCount(1, $result->response['sessions']);
            $this->assertSame('item-1', $result->response['sessions'][0]['item_id']);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinGetSessions::class],
            ['Emby', EmbyGetSessions::class],
        ];
    }
}
