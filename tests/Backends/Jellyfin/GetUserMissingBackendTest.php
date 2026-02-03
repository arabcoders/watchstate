<?php

declare(strict_types=1);

namespace Tests\Backends\Jellyfin;

use App\Backends\Jellyfin\Action\GetUser;
use App\Backends\Jellyfin\JellyfinClient;
use App\Libs\TestCase;
use App\Libs\Uri;

class GetUserMissingBackendTest extends TestCase
{
    public function test_get_user_requires_backend_user(): void
    {
        $http = new \App\Libs\Extends\MockHttpClient();
        $logger = new \Monolog\Logger('test');
        $action = new GetUser($http, $logger);

        $cache = new \App\Backends\Common\Cache($logger, new \Symfony\Component\Cache\Psr16Cache(new \Symfony\Component\Cache\Adapter\ArrayAdapter()));
        $userContext = $this->createUserContext(JellyfinClient::CLIENT_NAME);
        $context = new \App\Backends\Common\Context(
            clientName: JellyfinClient::CLIENT_NAME,
            backendName: JellyfinClient::CLIENT_NAME,
            backendUrl: new Uri('http://mediabrowser.test'),
            cache: $cache,
            userContext: $userContext,
            backendUser: null,
        );

        $result = $action($context);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }
}
