<?php

declare(strict_types=1);

namespace App\Libs\Prune;

use App\Libs\Attributes\Cli\Prune;
use App\Libs\Database\DBLayer;
use App\Model\Events\EventsTable;
use Psr\Log\LoggerInterface as iLogger;

#[Prune(name: 'Database Pruner', cron: '0 */12 * * *', desc: 'Remove expired db records.')]
final class DatabasePruner
{
    public function __construct(
        private readonly iLogger $logger,
        private readonly DBLayer $db,
    ) {}

    public function __invoke(bool $execute): void
    {
        $before = make_date(strtotime('-7 DAYS'));
        $playlistBefore = strtotime('-90 DAYS');

        if (true !== $execute) {
            $eventsStmt = $this->db->query(
                'SELECT COUNT(*) AS count FROM '
                . EventsTable::TABLE_NAME
                . ' WHERE '
                . EventsTable::COLUMN_CREATED_AT
                . ' < datetime(:before)',
                ['before' => $before->format('Y-m-d')],
            );
            $eventsCount = (int) ($eventsStmt->fetchColumn() ?? 0);
            if ($eventsCount > 0) {
                $this->logger->info("Pruned '{count}' events.", ['count' => $eventsCount]);
            }

            $playlistsStmt = $this->db->query(
                'SELECT COUNT(*) AS count FROM playlists WHERE deleted_at IS NOT NULL AND deleted_at < :before',
                ['before' => $playlistBefore],
            );
            $playlistCount = (int) ($playlistsStmt->fetchColumn() ?? 0);
            if ($playlistCount > 0) {
                $this->logger->info("Pruned '{count}' deleted playlist snapshots.", ['count' => $playlistCount]);
            }

            return;
        }

        $eventsStmt = $this->db->query(
            'DELETE FROM ' . EventsTable::TABLE_NAME . ' WHERE ' . EventsTable::COLUMN_CREATED_AT . ' < datetime(:before)',
            ['before' => $before->format('Y-m-d')],
        );

        $eventsCount = $eventsStmt->rowCount();
        if ($eventsCount > 0) {
            $this->logger->info("Pruned '{count}' events.", ['count' => $eventsCount]);
        }

        $playlistStmt = $this->db->query(
            'DELETE FROM playlists WHERE deleted_at IS NOT NULL AND deleted_at < :before',
            ['before' => $playlistBefore],
        );

        $playlistCount = $playlistStmt->rowCount();
        if ($playlistCount > 0) {
            $this->logger->info("Pruned '{count}' deleted playlist snapshots.", ['count' => $playlistCount]);
        }
    }
}
