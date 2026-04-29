<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\CreatePlaylist as EmbyCreatePlaylist;
use App\Backends\Emby\Action\DeletePlaylist as EmbyDeletePlaylist;
use App\Backends\Jellyfin\Action\CreatePlaylist as JellyfinCreatePlaylist;
use App\Backends\Jellyfin\Action\DeletePlaylist as JellyfinDeletePlaylist;
use App\Libs\Extends\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PlaylistWriteTest extends MediaBrowserTestCase
{
    public function test_jellyfin_create_json(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = compact('method', 'url', 'options');

            return new MockResponse(json_encode(['Id' => 'playlist-jf-new'], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $action = new JellyfinCreatePlaylist($http, $this->logger);
        $result = $action($this->makeContext('Jellyfin'), 'Weekend Movies', ['item-1', 'item-2']);

        self::assertTrue($result->isSuccessful());
        self::assertSame('playlist-jf-new', $result->response['id']);
        self::assertSame('POST', $requests[0]['method']);
        self::assertStringEndsWith('/Playlists', $requests[0]['url']);
        self::assertSame([
            'Name' => 'Weekend Movies',
            'Ids' => ['item-1', 'item-2'],
            'UserId' => 'user-1',
            'MediaType' => 'Video',
            'IsPublic' => false,
        ], json_decode((string) ($requests[0]['options']['body'] ?? ''), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_emby_create_delete(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = compact('method', 'url', 'options');

            if ('POST' === $method) {
                return new MockResponse(json_encode(['Id' => 'playlist-emby-new'], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            return new MockResponse('', ['http_code' => 200]);
        });

        $context = $this->makeContext('Emby');

        $create = new EmbyCreatePlaylist($http, $this->logger);
        $createResult = $create($context, 'Weekend Movies', ['item-1', 'item-2']);

        $delete = new EmbyDeletePlaylist($http, $this->logger);
        $deleteResult = $delete($context, 'playlist-emby-new');

        self::assertTrue($createResult->isSuccessful());
        self::assertTrue($deleteResult->isSuccessful());
        self::assertSame('playlist-emby-new', $createResult->response['id']);
        self::assertSame('POST', $requests[0]['method']);
        self::assertStringContainsString('/Playlists?', $requests[0]['url']);
        self::assertStringContainsString('Name=Weekend+Movies', $requests[0]['url']);
        self::assertStringContainsString('Ids=item-1%2Citem-2', $requests[0]['url']);
        self::assertStringContainsString('userId=user-1', $requests[0]['url']);
        self::assertStringContainsString('MediaType=Video', $requests[0]['url']);
        self::assertSame('DELETE', $requests[1]['method']);
        self::assertStringEndsWith('/Items/playlist-emby-new', $requests[1]['url']);
    }

    public function test_jellyfin_delete_items(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = compact('method', 'url', 'options');

            return new MockResponse('', ['http_code' => 204]);
        });

        $action = new JellyfinDeletePlaylist($http, $this->logger);
        $result = $action($this->makeContext('Jellyfin'), 'playlist-jf-new');

        self::assertTrue($result->isSuccessful());
        self::assertSame('DELETE', $requests[0]['method']);
        self::assertStringEndsWith('/Items/playlist-jf-new', $requests[0]['url']);
    }
}
