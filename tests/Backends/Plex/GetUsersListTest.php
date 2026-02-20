<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\GetUsersList;
use App\Libs\Options;
use Symfony\Component\HttpClient\Response\MockResponse;

class GetUsersListTest extends PlexTestCase
{
    public function test_get_users_list_external_tokens(): void
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

    public function test_get_users_list_home_users(): void
    {
        $externalUsersXml = '<MediaContainer><User id="1" username="TestUser" thumb="/users/uuid-1/avatar" home="0" restricted="0" protected="0" /></MediaContainer>';
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

        $http = new \App\Libs\Extends\MockHttpClient(function (string $method, string $url) use ($externalUsersXml, $homeUsersJson) {
            if (str_contains($url, '/api/v2/home/users/')) {
                return new MockResponse($homeUsersJson, ['http_code' => 200]);
            }

            return new MockResponse($externalUsersXml, ['http_code' => 200]);
        });

        $context = $this->makeContext();
        $action = new GetUsersList($http, $this->logger);
        $result = $action($context);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('test_user', $result->response[0]['name'], 'Name normalization failed');
        $this->assertTrue($result->response[0]['admin']);
    }

    public function test_get_users_list_error_status(): void
    {
        $http = new \App\Libs\Extends\MockHttpClient([
            new MockResponse('error', ['http_code' => 500]),
        ]);

        $context = $this->makeContext();
        $action = new GetUsersList($http, $this->logger);
        $result = $action($context, [Options::PLEX_EXTERNAL_USER => true, Options::NO_CACHE => true]);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }
}
