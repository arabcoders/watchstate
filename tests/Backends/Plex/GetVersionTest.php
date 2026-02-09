<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\GetVersion;

class GetVersionTest extends PlexTestCase
{
    public function test_get_version_success(): void
    {
        $payload = $this->fixture('server_info_get_200');
        $response = $this->makeResponse($payload['response']['body']);
        $http = $this->makeHttpClient($response);
        $context = $this->makeContext();

        $action = new GetVersion($http, $this->logger);
        $result = $action($context);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('1.40.0.0', $result->response);
    }
}
