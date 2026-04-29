<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetPlaylist as EmbyGetPlaylist;
use App\Backends\Jellyfin\Action\GetPlaylist as JellyfinGetPlaylist;

final class GetPlaylistTest extends MediaBrowserTestCase
{
    public function test_get_playlist_supported(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $detailFixture]) {
            $http = $this->makeHttpClient(
                $this->makeResponse($this->fixture($detailFixture)),
                $this->makeResponse($this->fixture('playlist_items')),
            );
            $context = $this->makeContext($clientName);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context, 'playlist-1');

            self::assertTrue($result->isSuccessful());
            self::assertSame('playlist-1', $result->response['id']);
            self::assertSame('Weekend Movies', $result->response['title']);
            self::assertCount(1, $result->response['items']);
        }
    }

    public function test_emby_playlist_editable(): void
    {
        $http = $this->makeHttpClient(
            $this->makeResponse([
                'Id' => 'playlist-emby-sync',
                'Name' => 'Scoped Playlist',
                'Type' => 'Playlist',
                'MediaType' => 'Video',
                'CanDelete' => false,
                'SupportsSync' => true,
            ]),
            $this->makeResponse($this->fixture('playlist_items')),
        );

        $action = new EmbyGetPlaylist($http, $this->logger);
        $result = $action($this->makeContext('Emby'), 'playlist-emby-sync');

        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->response['editable']);
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinGetPlaylist::class, 'playlist_detail_jellyfin'],
            ['Emby', EmbyGetPlaylist::class, 'playlist_detail_emby'],
        ];
    }
}
