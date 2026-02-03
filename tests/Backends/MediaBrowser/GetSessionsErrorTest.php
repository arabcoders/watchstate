<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetSessions as EmbyGetSessions;
use App\Backends\Jellyfin\Action\GetSessions as JellyfinGetSessions;
use App\Libs\Extends\MockHttpClient;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class GetSessionsErrorTest extends MediaBrowserTestCase
{
    public function test_get_sessions_empty_response(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $response = $this->makeResponse('', 200);
            $http = new MockHttpClient($response);
            $cache = new Psr16Cache(new ArrayAdapter());
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger, $cache);
            $result = $action($context);

            $this->assertFalse($result->isSuccessful());
            $this->assertNotNull($result->error);
        }
    }

    public function test_get_sessions_error_status(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $response = $this->makeResponse(['error' => 'nope'], 503);
            $http = new MockHttpClient($response);
            $cache = new Psr16Cache(new ArrayAdapter());
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger, $cache);
            $result = $action($context);

            $this->assertFalse($result->isSuccessful());
            $this->assertNotNull($result->error);
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
