<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetUsersList as EmbyGetUsersList;
use App\Backends\Jellyfin\Action\GetUsersList as JellyfinGetUsersList;
use App\Libs\Extends\MockHttpClient;

class GetUsersListTest extends MediaBrowserTestCase
{
    public function test_get_users_list_success(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $response = $this->makeResponse($this->fixture('users'));
            $http = new MockHttpClient($response);
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context);

            $this->assertTrue($result->isSuccessful());
            $this->assertCount(1, $result->response);
            $this->assertSame('user-1', $result->response[0]['id']);
            $this->assertSame('Test User', $result->response[0]['name']);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinGetUsersList::class],
            ['Emby', EmbyGetUsersList::class],
        ];
    }
}
