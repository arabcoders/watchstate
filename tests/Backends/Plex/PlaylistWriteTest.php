<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\CreatePlaylist;
use App\Backends\Plex\Action\DeletePlaylist;
use App\Libs\Extends\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PlaylistWriteTest extends PlexTestCase
{
    public function test_create_playlist_query(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = compact('method', 'url', 'options');

            return new MockResponse(
                json_encode([
                    'MediaContainer' => [
                        'Metadata' => [
                            ['ratingKey' => 'new-plex-playlist'],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
                ['http_code' => 200],
            );
        });

        $action = new CreatePlaylist($http, $this->logger);
        $result = $action($this->makeContext(), 'Weekend Movies', ['11', '22']);

        self::assertTrue($result->isSuccessful());
        self::assertSame('new-plex-playlist', $result->response['id']);
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertStringContainsString('/playlists?', $requests[0]['url']);
        self::assertStringContainsString('title=Weekend+Movies', $requests[0]['url']);
        self::assertStringContainsString('smart=0', $requests[0]['url']);
        self::assertStringContainsString('type=video', $requests[0]['url']);
        self::assertStringContainsString(
            'uri=server%3A%2F%2Fplex-server-1%2Fcom.plexapp.plugins.library%2Flibrary%2Fmetadata%2F11%2C22',
            $requests[0]['url'],
        );
    }

    public function test_delete_playlist_endpoint(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = compact('method', 'url', 'options');

            return new MockResponse('', ['http_code' => 204]);
        });

        $action = new DeletePlaylist($http, $this->logger);
        $result = $action($this->makeContext(), '9001');

        self::assertTrue($result->isSuccessful());
        self::assertSame('DELETE', $requests[0]['method']);
        self::assertStringEndsWith('/playlists/9001', $requests[0]['url']);
    }
}
