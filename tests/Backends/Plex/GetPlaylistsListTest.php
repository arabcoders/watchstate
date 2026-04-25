<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\GetPlaylistsList;

final class GetPlaylistsListTest extends PlexTestCase
{
    public function test_get_playlists_list(): void
    {
        $fixture = $this->fixture('playlists_list_get_200');
        $response = $this->makeResponse($fixture['response']['body']);
        $http = $this->makeHttpClient($response);

        $action = new GetPlaylistsList($http, $this->logger);
        $result = $action($this->makeContext());

        self::assertTrue($result->isSuccessful());
        self::assertCount(2, $result->response);
        self::assertSame('9001', $result->response[0]['id']);
        self::assertTrue($result->response[0]['editable']);
        self::assertFalse($result->response[0]['smart']);
        self::assertSame(1710000000, $result->response[0]['remote_updated_at']);
        self::assertTrue($result->response[1]['smart']);
    }
}
