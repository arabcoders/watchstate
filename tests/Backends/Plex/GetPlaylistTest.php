<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\GetPlaylist;

final class GetPlaylistTest extends PlexTestCase
{
    public function test_get_playlist_with_items(): void
    {
        $detail = $this->fixture('playlist_get_200');
        $items = $this->fixture('playlist_items_get_200');

        $http = $this->makeHttpClient(
            $this->makeResponse($detail['response']['body']),
            $this->makeResponse($items['response']['body']),
        );

        $action = new GetPlaylist($http, $this->logger);
        $result = $action($this->makeContext(), '9001');

        self::assertTrue($result->isSuccessful());
        self::assertSame('9001', $result->response['id']);
        self::assertSame('Weekend Movies', $result->response['title']);
        self::assertTrue($result->response['editable']);
        self::assertCount(1, $result->response['items']);
        self::assertSame('movie', $result->response['items'][0]['type']);
    }
}
