<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\GetIdentifier;

class GetIdentifierTest extends PlexTestCase
{
    public function test_get_identifier_success(): void
    {
        $payload = $this->fixture('server_info_get_200');
        $response = $this->makeResponse($payload['response']['body']);
        $http = $this->makeHttpClient($response);
        $context = $this->makeContext();

        $action = new GetIdentifier($http, $this->logger);
        $result = $action($context);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('plex-server-1', $result->response);
    }
}
