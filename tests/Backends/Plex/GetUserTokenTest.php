<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Common\Response;
use App\Backends\Plex\Action\GetUserToken;
use App\Backends\Plex\Action\GetUsersList;
use App\Libs\Container;
use App\Libs\Options;
use Symfony\Component\HttpClient\Response\MockResponse;

class GetUserTokenTest extends PlexTestCase
{
    public function test_get_user_token_external_user(): void
    {
        Container::add(GetUsersList::class, fn() => new class() {
            public function __invoke(\App\Backends\Common\Context $context, array $opts = []): Response
            {
                return new Response(status: true, response: [
                    ['id' => 'user-1', 'uuid' => 'user-1', 'token' => 'token-1'],
                ]);
            }
        });

        $context = $this->makeContext();
        $action = new GetUserToken($this->makeHttpClient(), $this->logger);
        $result = $action($context, 'user-1', 'Test User', [Options::PLEX_EXTERNAL_USER => true]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('token-1', $result->response);
    }

    public function test_get_user_token_external_user_missing(): void
    {
        Container::add(GetUsersList::class, fn() => new class() {
            public function __invoke(\App\Backends\Common\Context $context, array $opts = []): Response
            {
                return new Response(status: true, response: []);
            }
        });

        $context = $this->makeContext();
        $action = new GetUserToken($this->makeHttpClient(), $this->logger);
        $result = $action($context, 'user-1', 'Test User', [Options::PLEX_EXTERNAL_USER => true]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }

    public function test_get_user_token_success(): void
    {
        $http = new \App\Libs\Extends\MockHttpClient([
            new MockResponse(json_encode(['authToken' => 'temp-token']), ['http_code' => 201]),
            new MockResponse(json_encode([
                [
                    'provides' => 'server',
                    'clientIdentifier' => 'plex-server-1',
                    'accessToken' => 'perm-token',
                ],
            ]), ['http_code' => 200]),
        ]);

        $context = $this->makeContext();
        $action = new GetUserToken($http, $this->logger);
        $result = $action($context, 1, 'Test User');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('perm-token', $result->response);
    }

    public function test_get_user_token_missing_server(): void
    {
        $http = new \App\Libs\Extends\MockHttpClient([
            new MockResponse(json_encode(['authToken' => 'temp-token']), ['http_code' => 201]),
            new MockResponse(json_encode([
                [
                    'provides' => 'server',
                    'clientIdentifier' => 'other-server',
                    'accessToken' => 'perm-token',
                ],
            ]), ['http_code' => 200]),
        ]);

        $context = $this->makeContext();
        $action = new GetUserToken($http, $this->logger);
        $result = $action($context, 1, 'Test User');

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }

    public function test_get_user_token_request_error(): void
    {
        $http = new \App\Libs\Extends\MockHttpClient([
            new MockResponse(json_encode(['error' => 'nope']), ['http_code' => 500]),
        ]);

        $context = $this->makeContext();
        $action = new GetUserToken($http, $this->logger);
        $result = $action($context, 1, 'Test User');

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }
}
