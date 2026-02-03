<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\Proxy;
use App\Libs\Enums\Http\Method;
use App\Libs\Uri;

class ProxyTest extends PlexTestCase
{
    public function test_proxy_returns_api_response(): void
    {
        $response = $this->makeResponse('ok');
        $http = $this->makeHttpClient($response);
        $context = $this->makeContext();

        $action = new Proxy($http, $this->logger);
        $result = $action($context, Method::GET, new Uri('http://plex.test/health'));

        $this->assertTrue($result->isSuccessful());
        $this->assertTrue($result->response->hasStream());
    }
}
