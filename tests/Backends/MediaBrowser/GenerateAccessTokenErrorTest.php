<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GenerateAccessToken as EmbyGenerateAccessToken;
use App\Backends\Jellyfin\Action\GenerateAccessToken as JellyfinGenerateAccessToken;

class GenerateAccessTokenErrorTest extends MediaBrowserTestCase
{
    public function test_generate_access_token_error_status(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $response = $this->makeResponse(['error' => 'nope'], 401);
            $http = $this->makeHttpClient($response);
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context, 'user', 'bad');

            $this->assertFalse($result->isSuccessful());
            $this->assertNotNull($result->error);
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
