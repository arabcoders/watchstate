<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetUsersList as EmbyGetUsersList;
use App\Backends\Jellyfin\Action\GetUser;
use App\Backends\Jellyfin\Action\GetUsersList as JellyfinGetUsersList;
use App\Backends\Common\Response;
use App\Libs\Container;
use App\Libs\Options;

class GetUsersListLimitedTokenTest extends MediaBrowserTestCase
{
    public function test_get_users_list_limited_token_uses_get_user(): void
    {
        Container::add(GetUser::class, fn() => new class() {
            public function __invoke(): Response
            {
                return new Response(status: true, response: [
                    'id' => 'user-1',
                    'name' => 'Test User',
                ]);
            }
        });

        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $http = $this->makeHttpClient();
            $context = $this->makeContext($clientName, [Options::IS_LIMITED_TOKEN => true]);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context);

            $this->assertTrue($result->isSuccessful());
            $this->assertCount(1, $result->response);
            $this->assertSame('user-1', $result->response[0]['id']);
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
