<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GenerateAccessToken as EmbyGenerateAccessToken;
use App\Backends\Jellyfin\Action\GenerateAccessToken as JellyfinGenerateAccessToken;

class GenerateAccessTokenTest extends MediaBrowserTestCase
{
    public function test_generate_access_token_success(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $response = $this->makeResponse($this->fixture('auth'));
            $http = $this->makeHttpClient($response);
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context, 'test-user', 'secret');

            $this->assertTrue($result->isSuccessful());
            $this->assertSame('access-token-1', $result->response['accesstoken']);
            $this->assertSame('user-1', $result->response['user']);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinGenerateAccessToken::class],
            ['Emby', EmbyGenerateAccessToken::class],
        ];
    }
}
