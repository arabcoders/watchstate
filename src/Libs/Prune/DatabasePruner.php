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

        $this->logger->debug('Scanning for expired database records.', [
            'event_name' => 'prune.database.scan_started',
            'subsystem' => 'prune',
            'operation' => 'prune_expired_db_records',
            'outcome' => 'started',
            'execute' => $execute,
        ]);

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

            $playlistsStmt = $this->db->query(
                'SELECT COUNT(*) AS count FROM playlists WHERE deleted_at IS NOT NULL AND deleted_at < :before',
                ['before' => $playlistBefore],
            );
            $playlistCount = (int) ($playlistsStmt->fetchColumn() ?? 0);

            if (1 > $eventsCount && 1 > $playlistCount) {
                $this->logger->debug('No expired database records found.', [
                    'event_name' => 'prune.database.skipped',
                    'subsystem' => 'prune',
                    'operation' => 'prune_expired_db_records',
                    'outcome' => 'skipped',
                    'reason' => 'no_expired_records',
                ]);
                return;
            }

            $this->logger->info(
                "Found '{events}' expired events and '{playlists}' deleted playlist snapshots.",
                [
                    'event_name' => 'prune.database.completed',
                    'subsystem' => 'prune',
                    'operation' => 'prune_expired_db_records',
                    'outcome' => 'dry_run',
                    'events' => $eventsCount,
                    'playlists' => $playlistCount,
                ],
            );

            return;
        }

        $eventsStmt = $this->db->query(
            'DELETE FROM ' . EventsTable::TABLE_NAME . ' WHERE ' . EventsTable::COLUMN_CREATED_AT . ' < datetime(:before)',
            ['before' => $before->format('Y-m-d')],
        );

        $eventsCount = $eventsStmt->rowCount();

        $playlistStmt = $this->db->query(
            'DELETE FROM playlists WHERE deleted_at IS NOT NULL AND deleted_at < :before',
            ['before' => $playlistBefore],
        );

        $playlistCount = $playlistStmt->rowCount();

        if (1 > $eventsCount && 1 > $playlistCount) {
            $this->logger->debug('No expired database records to remove.', [
                'event_name' => 'prune.database.skipped',
                'subsystem' => 'prune',
                'operation' => 'prune_expired_db_records',
                'outcome' => 'skipped',
                'reason' => 'no_expired_records',
            ]);
            return;
        }

        $this->logger->info(
            "Pruned '{events}' expired events and '{playlists}' deleted playlist snapshots.",
            [
                'event_name' => 'prune.database.completed',
                'subsystem' => 'prune',
                'operation' => 'prune_expired_db_records',
                'outcome' => 'completed',
                'events' => $eventsCount,
                'playlists' => $playlistCount,
            ],
        );
    }
}
