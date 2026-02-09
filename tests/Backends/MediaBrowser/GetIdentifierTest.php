<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetIdentifier as EmbyGetIdentifier;
use App\Backends\Jellyfin\Action\GetIdentifier as JellyfinGetIdentifier;

class GetIdentifierTest extends MediaBrowserTestCase
{
    public function test_get_identifier_success(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $response = $this->makeResponse($this->fixture('info'));
            $http = $this->makeHttpClient($response);
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame('server-1', $result->response);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinGetIdentifier::class],
            ['Emby', EmbyGetIdentifier::class],
        ];
    }
}
