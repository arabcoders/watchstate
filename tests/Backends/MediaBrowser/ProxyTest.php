<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\Proxy as EmbyProxy;
use App\Backends\Jellyfin\Action\Proxy as JellyfinProxy;
use App\Libs\Enums\Http\Method;
use App\Libs\Uri;

class ProxyTest extends MediaBrowserTestCase
{
    public function test_proxy_returns_api_response(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $response = $this->makeResponse('ok');
            $http = $this->makeHttpClient($response);
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context, Method::GET, new Uri('http://mediabrowser.test/health'));

            $this->assertTrue($result->isSuccessful());
            $this->assertTrue($result->response->hasStream());
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinProxy::class],
            ['Emby', EmbyProxy::class],
        ];
    }
}
