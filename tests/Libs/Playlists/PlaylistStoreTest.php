<?php

declare(strict_types=1);

namespace Tests\Libs\Playlists;

use App\Libs\Playlists\PlaylistStore;
use App\Libs\TestCase;

final class PlaylistStoreTest extends TestCase
{
    public function test_replace_backend_playlists(): void
    {
        $db = $this->createDb();

        $store = new PlaylistStore($db->getDBLayer());

        $first = $store->replaceBackendPlaylists('test_plex', [
            [
                'id' => 'playlist-1',
                'title' => 'Weekend Movies',
                'type' => 'video',
                'editable' => true,
                'smart' => false,
                'public' => false,
                'metadata' => ['foo' => 'bar'],
                'items' => [
                    [
                        'position' => 0,
                        'state_id' => 10,
                        'backend_item_id' => 'item-1',
                        'backend_entry_id' => 'entry-1',
                        'item_type' => 'movie',
                        'title' => 'Test Movie',
                        'metadata' => ['raw' => ['Id' => 'item-1']],
                    ],
                ],
            ],
        ]);

        self::assertSame(1, $first['added']);
        self::assertSame(0, $first['updated']);
        self::assertSame(1, $first['items']);

        $stored = $store->getByBackend('test_plex');
        self::assertCount(1, $stored);
        self::assertSame('Weekend Movies', $stored[0]['title']);
        self::assertCount(1, $stored[0]['items']);

        $second = $store->replaceBackendPlaylists('test_plex', [
            [
                'id' => 'playlist-1',
                'title' => 'Weekend Rewatch',
                'type' => 'video',
                'editable' => true,
                'smart' => false,
                'public' => false,
                'metadata' => [],
                'items' => [],
            ],
        ]);

        self::assertSame(0, $second['added']);
        self::assertSame(1, $second['updated']);
        self::assertSame(0, $second['removed']);

        $updated = $store->getByBackend('test_plex');
        self::assertSame('Weekend Rewatch', $updated[0]['title']);
        self::assertCount(0, $updated[0]['items']);
    }

    public function test_replace_preserves_syncid(): void
    {
        $db = $this->createDb();

        $store = new PlaylistStore($db->getDBLayer());

        $store->replaceBackendPlaylists('test_plex', [
            [
                'id' => 'playlist-1',
                'sync_id' => 'title:weekend movies',
                'title' => 'Weekend Movies',
                'type' => 'video',
                'editable' => true,
                'smart' => false,
                'public' => false,
                'remote_updated_at' => 1000,
                'metadata' => ['sync' => ['eligible' => true]],
                'items' => [
                    [
                        'position' => 0,
                        'state_id' => 10,
                        'backend_item_id' => 'remote-a',
                        'item_type' => 'movie',
                        'title' => 'Test Movie',
                    ],
                ],
            ],
        ]);

        $removed = $store->replaceBackendPlaylists('test_plex', [], ['playlist-2']);
        self::assertSame(1, $removed['removed']);
        self::assertCount(0, $store->getByBackend('test_plex'));

        $all = $store->getAll();
        self::assertCount(1, $all);
        self::assertSame('title:weekend movies', $all[0]['sync_id']);
        self::assertNotNull($all[0]['deleted_at']);

        $store->replaceBackendPlaylists('test_plex', [
            [
                'id' => 'playlist-2',
                'sync_id' => 'title:weekend movies',
                'title' => 'Weekend Movies',
                'type' => 'video',
                'editable' => true,
                'smart' => false,
                'public' => false,
                'remote_updated_at' => 2000,
                'metadata' => ['sync' => ['eligible' => true]],
                'items' => [
                    [
                        'position' => 0,
                        'state_id' => 10,
                        'backend_item_id' => 'remote-b',
                        'item_type' => 'movie',
                        'title' => 'Test Movie',
                    ],
                ],
            ],
        ], ['playlist-2']);

        $current = $store->getByBackend('test_plex');
        self::assertCount(1, $current);
        self::assertSame('playlist-2', $current[0]['backend_id']);
        self::assertSame('title:weekend movies', $current[0]['sync_id']);
        self::assertNull($current[0]['deleted_at']);
        self::assertSame(1, $current[0]['item_count']);
    }

    public function test_hash_ignores_item_id(): void
    {
        $db = $this->createDb();

        $store = new PlaylistStore($db->getDBLayer());

        $store->replaceBackendPlaylists('test_a', [
            [
                'id' => 'playlist-a',
                'sync_id' => 'title:weekend movies',
                'title' => 'Weekend Movies',
                'type' => 'video',
                'editable' => true,
                'smart' => false,
                'public' => false,
                'remote_updated_at' => 1000,
                'metadata' => ['sync' => ['eligible' => true]],
                'items' => [
                    [
                        'position' => 0,
                        'state_id' => 10,
                        'backend_item_id' => 'backend-a-id',
                        'item_type' => 'movie',
                        'title' => 'Test Movie',
                    ],
                ],
            ],
        ]);

        $store->replaceBackendPlaylists('test_b', [
            [
                'id' => 'playlist-b',
                'sync_id' => 'title:weekend movies',
                'title' => 'Weekend Movies',
                'type' => 'video',
                'editable' => true,
                'smart' => false,
                'public' => false,
                'remote_updated_at' => 1000,
                'metadata' => ['sync' => ['eligible' => true]],
                'items' => [
                    [
                        'position' => 0,
                        'state_id' => 10,
                        'backend_item_id' => 'backend-b-id',
                        'item_type' => 'movie',
                        'title' => 'Test Movie',
                    ],
                ],
            ],
        ]);

        $all = $store->getAll();
        self::assertCount(2, $all);
        self::assertSame($all[0]['content_hash'], $all[1]['content_hash']);
    }
}
