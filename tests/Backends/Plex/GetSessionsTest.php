<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\GetSessions;

class GetSessionsTest extends PlexTestCase
{
    public function test_get_sessions_success(): void
    {
        $payload = [
            'MediaContainer' => [
                'Metadata' => [
                    [
                        'User' => [
                            'id' => 1,
                            'title' => 'Test User',
                            'thumb' => '/users/uuid-1/avatar',
                        ],
                        'ratingKey' => 11,
                        'title' => 'Test Movie',
                        'type' => 'movie',
                        'viewOffset' => 12000,
                        'Player' => ['state' => 'playing'],
                        'Session' => ['id' => 'sess-1'],
                    ],
                ],
            ],
        ];

        $response = $this->makeResponse($payload);
        $http = $this->makeHttpClient($response);
        $context = $this->makeContext();

        $action = new GetSessions($http, $this->logger);
        $result = $action($context);

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(1, $result->response['sessions']);
        $this->assertSame('uuid-1', $result->response['sessions'][0]['user_uuid']);
    }

    public function test_get_sessions_empty_response(): void
    {
        $response = $this->makeResponse('', 200);
        $http = $this->makeHttpClient($response);
        $context = $this->makeContext();

        $action = new GetSessions($http, $this->logger);
        $result = $action($context);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }

    public function test_get_sessions_error_status(): void
    {
        $response = $this->makeResponse(['error' => 'nope'], 500);
        $http = $this->makeHttpClient($response);
        $context = $this->makeContext();

        $action = new GetSessions($http, $this->logger);
        $result = $action($context);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }
}
