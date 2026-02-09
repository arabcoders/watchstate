<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetUser as EmbyGetUser;
use App\Backends\Jellyfin\Action\GetUser as JellyfinGetUser;
use App\Libs\Extends\MockHttpClient;

class GetUserTest extends MediaBrowserTestCase
{
    public function test_get_user_success(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $fixtureKey]) {
            $response = $this->makeResponse($this->fixture($fixtureKey));
            $http = new MockHttpClient($response);
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context);

            $this->assertTrue($result->isSuccessful());
            $responseData = $result->response;
            if (is_array($responseData) && isset($responseData[0])) {
                $responseData = $responseData[0];
            }

            $this->assertSame('user-1', $responseData['id']);
            $this->assertSame('Test User', $responseData['name']);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinGetUser::class, 'user'],
            ['Emby', EmbyGetUser::class, 'users'],
        ];
    }
}
