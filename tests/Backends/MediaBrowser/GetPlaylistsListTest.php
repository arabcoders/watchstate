<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetPlaylistsList as EmbyGetPlaylistsList;
use App\Backends\Jellyfin\Action\GetPlaylistsList as JellyfinGetPlaylistsList;
use App\Libs\Extends\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GetPlaylistsListTest extends MediaBrowserTestCase
{
    public function test_get_playlists_list_for_supported_backends(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $http = $this->makeHttpClient($this->makeResponse($this->fixture('playlists')));
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context);

            self::assertTrue($result->isSuccessful());
            self::assertCount(1, $result->response);
            self::assertSame('playlist-1', $result->response[0]['id']);
            self::assertSame('video', $result->response[0]['type']);
            self::assertSame(1704240000, $result->response[0]['remote_updated_at']);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinGetPlaylistsList::class],
            ['Emby',     EmbyGetPlaylistsList::class],
        ];
    }

    public function test_emby_get_playlists_list_omits_media_types_and_filters_non_playlist_items(): void
    {
        $requestUrl = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$requestUrl) {
            $requestUrl = $url;

            return new MockResponse(
                json_encode([
                    'Items' => [
                        [
                            'Id' => 'studio-1',
                            'Name' => 'Example Studio',
                            'Type' => 'Studio',
                            'MediaType' => 'Video',
                        ],
                        [
                            'Id' => 'playlist-1',
                            'Name' => 'Weekend Movies',
                            'Type' => 'Playlist',
                            'CollectionType' => 'playlists',
                            'MediaType' => 'Video',
                            'CanDelete' => true,
                        ],
                        [
                            'Id' => 'boxset-1',
                            'Name' => 'Collection',
                            'Type' => 'BoxSet',
                            'MediaType' => 'Video',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
                ['http_code' => 200],
            );
        });

        $context = $this->makeContext('Emby');
        $action = new EmbyGetPlaylistsList($http, $this->logger);
        $result = $action($context);

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->response);
        self::assertSame('playlist-1', $result->response[0]['id']);
        self::assertIsString($requestUrl);
        self::assertStringContainsString('/Users/user-1/items', $requestUrl);
        self::assertStringContainsString('includeItemTypes=Playlist', $requestUrl);
        self::assertStringNotContainsString('mediaTypes=', $requestUrl);
    }
}
