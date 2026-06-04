<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\GetUsersList;
use App\Libs\Container;
use App\Libs\Options;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

class GetUsersListTest extends PlexTestCase
{
    public function test_users_list_external(): void
    {
        $externalUsersXml = '<MediaContainer><User id="1" username="TestUser" thumb="/users/uuid-1/avatar" home="0" restricted="0" protected="0" /></MediaContainer>';
        $sharedServersXml = '<MediaContainer><SharedServer userID="1" accessToken="token-1" invitedAt="2024-01-01T00:00:00Z" /><SharedServer userID="2" accessToken="token-2" /></MediaContainer>';

        $http = new \App\Libs\Extends\MockHttpClient(function (string $method, string $url) use ($externalUsersXml, $sharedServersXml) {
            if (str_contains($url, '/api/servers/')) {
                return new MockResponse($sharedServersXml, ['http_code' => 200]);
            }

            return new MockResponse($externalUsersXml, ['http_code' => 200]);
        });

        $context = $this->makeContext();
        $action = new GetUsersList($http, $this->logger);
        $result = $action($context, [Options::PLEX_EXTERNAL_USER => true, Options::GET_TOKENS => true]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('token-1', $result->response[0]['token']);
    }

    public function test_users_list_merges(): void
    {
        $externalUsersXml = '<MediaContainer><User id="2" username="Invited User" thumb="/users/uuid-2/avatar" home="0" restricted="0" protected="0" /></MediaContainer>';
        $homeUsersJson = json_encode([
            'users' => [
                [
                    'id' => 1,
                    'uuid' => 'uuid-1',
                    'friendlyName' => 'Home User',
                    'admin' => true,
                    'guest' => false,
                    'restricted' => false,
                    'protected' => false,
                    'updatedAt' => '2024-01-01T00:00:00Z',
                ],
            ],
        ]);

        $http = new \App\Libs\Extends\MockHttpClient(function (string $method, string $url) use ($externalUsersXml, $homeUsersJson) {
            if (str_contains($url, '/api/v2/home/users/')) {
                return new MockResponse($homeUsersJson, ['http_code' => 200]);
            }

            if (str_contains($url, '/api/users/')) {
                return new MockResponse($externalUsersXml, ['http_code' => 200]);
            }

            return new MockResponse('not-found', ['http_code' => 404]);
        });

        $context = $this->makeContext();
        $action = new GetUsersList($http, $this->logger);
        $result = $action($context, [Options::NO_CACHE => true]);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(2, $result->response);
        $this->assertSame('H', $result->response[0]['type']);
        $this->assertSame('E', $result->response[1]['type']);
        $this->assertSame('invited_user', $result->response[1]['name']);
        $this->assertTrue($result->response[1]['guest']);
    }

    public function test_users_list_dedup(): void
    {
        $externalUsersXml =
            '<MediaContainer>'
            . '<User id="3003" username="Invited Guest" thumb="/users/external-uuid-3/avatar" home="0" restricted="0" protected="0" />'
            . '<User id="2002" username="Shared Member" thumb="/users/shared-uuid-2/avatar" home="1" restricted="1" protected="1" />'
            . '</MediaContainer>';
        $homeUsersJson = json_encode([
            'users' => [
                [
                    'id' => 1001,
                    'uuid' => 'home-uuid-1',
                    'friendlyName' => 'Owner User',
                    'admin' => true,
                    'guest' => false,
                    'restricted' => false,
                    'protected' => false,
                    'updatedAt' => '2025-04-23T21:15:41Z',
                ],
                [
                    'id' => 2002,
                    'uuid' => 'shared-uuid-2',
                    'friendlyName' => 'Shared Member',
                    'admin' => false,
                    'guest' => false,
                    'restricted' => true,
                    'protected' => true,
                    'updatedAt' => '2026-03-23T14:21:38Z',
                    'pin' => 'pin-required',
                ],
            ],
        ]);

        $http = new \App\Libs\Extends\MockHttpClient(function (string $method, string $url) use ($externalUsersXml, $homeUsersJson) {
            if (str_contains($url, '/api/v2/home/users/')) {
                return new MockResponse($homeUsersJson, ['http_code' => 200]);
            }

            if (str_contains($url, '/api/users/')) {
                return new MockResponse($externalUsersXml, ['http_code' => 200]);
            }

            return new MockResponse('not-found', ['http_code' => 404]);
        });

        $context = $this->makeContext();
        $action = new GetUsersList($http, $this->logger);
        $result = $action($context, [Options::NO_CACHE => true]);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(3, $result->response);
        $this->assertSame(['owner_user', 'shared_member', 'invited_guest'], array_column($result->response, 'name'));
        $this->assertSame(['H', 'H', 'E'], array_column($result->response, 'type'));
    }

    public function test_users_list_home(): void
    {
        $homeUsersJson = json_encode([
            'users' => [
                [
                    'id' => 1,
                    'uuid' => 'uuid-1',
                    'friendlyName' => 'Test User',
                    'admin' => true,
                    'guest' => false,
                    'restricted' => false,
                    'protected' => false,
                    'updatedAt' => '2024-01-01T00:00:00Z',
                ],
            ],
        ]);

        $http = new \App\Libs\Extends\MockHttpClient(function (string $method, string $url) use ($homeUsersJson) {
            if (str_contains($url, '/api/v2/home/users/')) {
                return new MockResponse($homeUsersJson, ['http_code' => 200]);
            }

            return new MockResponse('not-found', ['http_code' => 404]);
        });

        $context = $this->makeContext();
        $action = new GetUsersList($http, $this->logger);
        $result = $action($context);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('test_user', $result->response[0]['name'], 'Name normalization failed');
        $this->assertTrue($result->response[0]['admin']);
    }

    public function test_users_list_target(): void
    {
        $homeUsersJson = json_encode([
            'users' => [
                [
                    'id' => 1,
                    'uuid' => 'uuid-1',
                    'friendlyName' => 'Test User 1',
                    'admin' => true,
                    'guest' => false,
                    'restricted' => false,
                    'protected' => false,
                    'updatedAt' => '2024-01-01T00:00:00Z',
                ],
                [
                    'id' => 2,
                    'uuid' => 'uuid-2',
                    'friendlyName' => 'Test User 2',
                    'admin' => false,
                    'guest' => false,
                    'restricted' => false,
                    'protected' => false,
                    'updatedAt' => '2024-01-02T00:00:00Z',
                ],
            ],
        ]);
        $switchJson = json_encode(['authToken' => 'temp-token']);
        $resourcesJson = json_encode([
            [
                'clientIdentifier' => 'plex-server-1',
                'accessToken' => 'token-uuid-2',
                'provides' => 'server',
                'name' => 'Plex Server',
            ],
        ]);

        $requests = [];
        $http = new \App\Libs\Extends\MockHttpClient(function (string $method, string $url) use (
            &$requests,
            $homeUsersJson,
            $switchJson,
            $resourcesJson,
        ) {
            $requests[] = $url;
            if (str_contains($url, '/api/v2/home/users/') && str_contains($url, '/switch')) {
                if (str_contains($url, '/api/v2/home/users/uuid-2/switch')) {
                    return new MockResponse($switchJson, ['http_code' => 201]);
                }

                return new MockResponse('denied', ['http_code' => 403]);
            }

            if (str_contains($url, '/api/v2/resources')) {
                return new MockResponse($resourcesJson, ['http_code' => 200]);
            }

            if (str_contains($url, '/api/v2/home/users/')) {
                return new MockResponse($homeUsersJson, ['http_code' => 200]);
            }

            return new MockResponse('not-found', ['http_code' => 404]);
        });

        Container::add(iHttp::class, fn() => $http);

        $context = $this->makeContext();
        $action = new GetUsersList($http, $this->logger);
        $result = $action($context, [Options::GET_TOKENS => true, Options::TARGET_USER => 'uuid-2']);

        $this->assertTrue($result->isSuccessful());
        $this->assertNull($result->response[0]['token'] ?? null);
        $this->assertSame('token-uuid-2', $result->response[1]['token'] ?? null);
        $this->assertFalse(
            array_reduce(
                $requests,
                static fn(bool $found, string $url) => $found || str_contains($url, '/api/v2/home/users/uuid-1/switch'),
                false,
            ),
            'Unexpected token request for non-target user.',
        );
    }

    public function test_users_list_error(): void
    {
        $http = new \App\Libs\Extends\MockHttpClient([
            new MockResponse('error', ['http_code' => 500]),
        ]);

        $context = $this->makeContext();
        $action = new GetUsersList($http, $this->logger);
        $result = $action($context, [Options::NO_CACHE => true]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }
}
