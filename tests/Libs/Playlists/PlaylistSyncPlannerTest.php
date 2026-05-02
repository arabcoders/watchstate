<?php

declare(strict_types=1);

namespace Tests\Libs\Playlists;

use App\Libs\Playlists\PlaylistSyncPlanner;
use PHPUnit\Framework\TestCase;

final class PlaylistSyncPlannerTest extends TestCase
{
    public function test_winner_latest_remote(): void
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

    public function test_deleted_winner_beats(): void
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

    public function test_should_sync_hash(): void
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

    public function test_winner_ignores_partial(): void
    {
        $planner = new PlaylistSyncPlanner();

        $winner = $planner->selectWinner([
            [
                'backend' => 'source',
                'remote_updated_at' => 100,
                'content_hash' => 'complete',
                'metadata' => ['sync' => ['eligible' => true]],
                'deleted_at' => null,
            ],
            [
                'backend' => 'target',
                'remote_updated_at' => 200,
                'content_hash' => 'partial',
                'metadata' => ['sync' => [
                    'eligible' => true,
                    'partial' => true,
                    'generated_by_sync' => true,
                ]],
                'deleted_at' => null,
            ],
        ]);

        self::assertNotNull($winner);
        self::assertSame('source', $winner['backend']);
    }

    public function test_winner_allows_newest(): void
    {
        $planner = new PlaylistSyncPlanner();

        $winner = $planner->selectWinner([
            [
                'backend' => 'source',
                'remote_updated_at' => 100,
                'content_hash' => 'complete',
                'metadata' => ['sync' => ['eligible' => true]],
                'deleted_at' => null,
            ],
            [
                'backend' => 'target',
                'remote_updated_at' => 200,
                'content_hash' => 'partial',
                'metadata' => ['sync' => [
                    'eligible' => true,
                    'partial' => true,
                    'generated_by_sync' => false,
                ]],
                'deleted_at' => null,
            ],
        ]);

        self::assertNotNull($winner);
        self::assertSame('target', $winner['backend']);
    }
}
