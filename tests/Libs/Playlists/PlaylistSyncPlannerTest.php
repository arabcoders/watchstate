<?php

declare(strict_types=1);

namespace Tests\Libs\Playlists;

use App\Libs\Playlists\PlaylistSyncPlanner;

final class PlaylistSyncPlannerTest extends \PHPUnit\Framework\TestCase
{
    public function test_select_winner_prefers_latest_remote_timestamp(): void
    {
        $planner = new PlaylistSyncPlanner();

        $winner = $planner->selectWinner([
            [
                'backend' => 'plex',
                'remote_updated_at' => 100,
                'content_hash' => 'a',
                'metadata' => ['sync' => ['eligible' => true]],
                'deleted_at' => null,
            ],
            [
                'backend' => 'jellyfin',
                'remote_updated_at' => 200,
                'content_hash' => 'b',
                'metadata' => ['sync' => ['eligible' => true]],
                'deleted_at' => null,
            ],
        ]);

        self::assertNotNull($winner);
        self::assertSame('jellyfin', $winner['backend']);
    }

    public function test_deleted_winner_beats_same_timestamp_live_row(): void
    {
        $planner = new PlaylistSyncPlanner();

        $winner = $planner->selectWinner([
            [
                'backend' => 'plex',
                'remote_updated_at' => 200,
                'content_hash' => 'a',
                'metadata' => ['sync' => ['eligible' => true]],
                'deleted_at' => 200,
            ],
            [
                'backend' => 'jellyfin',
                'remote_updated_at' => 200,
                'content_hash' => 'a',
                'metadata' => ['sync' => ['eligible' => true]],
                'deleted_at' => null,
            ],
        ]);

        self::assertNotNull($winner);
        self::assertSame('plex', $winner['backend']);
        self::assertSame(200, $winner['deleted_at']);
    }

    public function test_should_sync_uses_content_hash_for_live_targets(): void
    {
        $planner = new PlaylistSyncPlanner();

        $winner = [
            'backend' => 'plex',
            'content_hash' => 'same',
            'metadata' => ['sync' => ['eligible' => true]],
            'deleted_at' => null,
        ];

        self::assertFalse($planner->shouldSync([
            'backend' => 'jellyfin',
            'content_hash' => 'same',
            'metadata' => ['sync' => ['eligible' => true]],
            'deleted_at' => null,
        ], $winner));

        self::assertTrue($planner->shouldSync([
            'backend' => 'jellyfin',
            'content_hash' => 'different',
            'metadata' => ['sync' => ['eligible' => true]],
            'deleted_at' => null,
        ], $winner));
    }
}
