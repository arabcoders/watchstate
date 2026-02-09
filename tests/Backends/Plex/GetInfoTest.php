<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\GetInfo;

class GetInfoTest extends PlexTestCase
{
    public function test_get_info_success(): void
    {
        $payload = $this->fixture('server_info_get_200');
        $response = $this->makeResponse($payload['response']['body']);
        $http = $this->makeHttpClient($response);
        $context = $this->makeContext();

        $action = new GetInfo($http, $this->logger);
        $result = $action($context);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('Plex Test', $result->response['name']);
        $this->assertSame('1.40.0.0', $result->response['version']);
        $this->assertSame('plex-server-1', $result->response['identifier']);
    }

    public function test_get_info_empty_response(): void
    {
        $response = $this->makeResponse('', 200);
        $http = $this->makeHttpClient($response);
        $context = $this->makeContext();

        $action = new GetInfo($http, $this->logger);
        $result = $action($context);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }

    public function test_get_info_error_status(): void
    {
        $payload = $this->fixture('server_info_get_500');
        $response = $this->makeResponse($payload['response']['body'], 500);
        $http = $this->makeHttpClient($response);
        $context = $this->makeContext();

        $action = new GetInfo($http, $this->logger);
        $result = $action($context);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }
}
