<?php

declare(strict_types=1);

namespace App\Libs\Prune;

use App\Libs\Attributes\Cli\Prune;
use App\Libs\Database\DBLayer;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\UserContext;
use PDO;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

#[Prune(name: 'Backend Metadata', cron: '35 */12 * * *', desc: 'Remove metadata for deleted backends.')]
final class BackendMetadataPruner
{
    public function __construct(
        private readonly iLogger $logger,
        private readonly iImport $mapper,
    ) {}

    public function __invoke(bool $execute): void
    {
        foreach (get_users_context($this->mapper, $this->logger) as $userContext) {
            $this->logger->debug("Scanning deleted backend metadata for user '{identity.user}'.", [
                'operation' => 'prune.metadata',
                'identity' => ['user' => $userContext->name],
                'execute' => $execute,
            ]);

            try {
                $stats = $this->pruneContext($userContext, $execute);
            } catch (Throwable $e) {
                $this->logger->error("Failed to prune deleted backend metadata for user '{identity.user}'.", [
                    'operation' => 'prune.metadata',
                    'error' => 'exception',
                    'identity' => ['user' => $userContext->name],
                    ...exception_log($e),
                ]);
                continue;
            }

            if (1 > $stats['references'] && 1 > $stats['records']) {
                $this->logger->debug("No deleted backend metadata found for user '{identity.user}'.", [
                    'operation' => 'prune.metadata',
                    'error' => 'nothing_to_prune',
                    'identity' => ['user' => $userContext->name],
                    'backends' => $stats['backends'],
                ]);
                continue;
            }

            $this->logger->info(
                true === $execute
                    ? "Pruned '{references}' deleted backend metadata references and '{records}' empty state records for user '{identity.user}'."
                    : "Found '{references}' deleted backend metadata references and '{records}' empty state records for user '{identity.user}'.",
                [
                    'operation' => 'prune.metadata',
                    'identity' => ['user' => $userContext->name],
                    'references' => $stats['references'],
                    'records' => $stats['records'],
                    'backends' => $stats['backends'],
                ],
            );
        }
    }

    /**
     * @return array{backends:array<string>,references:int,records:int}
     */
    private function pruneContext(UserContext $userContext, bool $execute): array
    {
        $db = $userContext->db->getDBLayer();
        $backends = $this->findStaleBackends($userContext);
        $references = 0;

        if ([] === $backends) {
            $this->logger->debug("No stale backend metadata keys found for user '{identity.user}'.", [
                'operation' => 'prune.metadata',
                'error' => 'no_stale_backends',
                'identity' => ['user' => $userContext->name],
                'active_backends' => array_keys($userContext->config->getAll()),
            ]);
        } else {
            $this->logger->debug("Found stale backend metadata keys for user '{identity.user}'.", [
                'operation' => 'prune.metadata',
                'identity' => ['user' => $userContext->name],
                'backends' => $backends,
            ]);
        }

        foreach ($backends as $backend) {
            $references += $this->pruneBackend($db, $backend, $execute);
        }

        $records = true === $execute
            ? $this->deleteEmptyRecords($db)
            : $this->countEmptyRecords($db);

        return [
            'backends' => $backends,
            'references' => $references,
            'records' => $records,
        ];
    }

    /**
     * @return array<string>
     */
    private function findStaleBackends(UserContext $userContext): array
    {
        $active = array_fill_keys(array_keys($userContext->config->getAll()), true);
        $db = $userContext->db->getDBLayer();
        $stmt = $db->query(
            'SELECT DISTINCT backend
             FROM
             (
                 SELECT metadata_entries.key AS backend
                 FROM state, json_each(
                     CASE WHEN json_valid(state.'
            . iState::COLUMN_META_DATA
            . ') THEN state.'
            . iState::COLUMN_META_DATA
            . " ELSE '{}' END
                 ) AS metadata_entries
                 UNION
                 SELECT extra_entries.key AS backend
                 FROM state, json_each(
                     CASE WHEN json_valid(state."
            . iState::COLUMN_EXTRA
            . ') THEN state.'
            . iState::COLUMN_EXTRA
            . " ELSE '{}' END
                 ) AS extra_entries
             )
             WHERE backend IS NOT NULL
             ORDER BY backend ASC",
        );

        $backends = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $backend) {
            $backend = (string) $backend;
            if ('' === $backend || true === isset($active[$backend])) {
                continue;
            }

            $backends[$backend] = $backend;
        }

        return array_values($backends);
    }

    private function pruneBackend(DBLayer $db, string $backend, bool $execute): int
    {
        $path = $this->jsonPath($backend);
        $matches = $this->countBackendRows($db, $path);

        $this->logger->debug("Found '{count}' state records with deleted backend metadata for '{identity.backend}'.", [
            'operation' => 'prune.metadata',
            'identity' => ['backend' => $backend],
            'count' => $matches,
            'execute' => $execute,
            ...(0 < $matches ? [] : ['error' => 'no_matching_records']),
        ]);

        if (true !== $execute || 1 > $matches) {
            return $matches;
        }

        $stmt = $db->query(
            'UPDATE state
             SET
                 '
            . iState::COLUMN_META_DATA
            . ' = CASE
                     WHEN json_valid('
            . iState::COLUMN_META_DATA
            . ') THEN json_remove('
            . iState::COLUMN_META_DATA
            . ', :metadata_path)
                     ELSE '
            . iState::COLUMN_META_DATA
            . '
                 END,
                 '
            . iState::COLUMN_EXTRA
            . ' = CASE
                     WHEN json_valid('
            . iState::COLUMN_EXTRA
            . ') THEN json_remove('
            . iState::COLUMN_EXTRA
            . ', :extra_path)
                     ELSE '
            . iState::COLUMN_EXTRA
            . '
                 END
             WHERE
                 json_type(
                     CASE WHEN json_valid('
            . iState::COLUMN_META_DATA
            . ') THEN '
            . iState::COLUMN_META_DATA
            . " ELSE '{}' END,
                     :metadata_where_path
                 ) IS NOT NULL
             OR
                 json_type(
                     CASE WHEN json_valid("
            . iState::COLUMN_EXTRA
            . ') THEN '
            . iState::COLUMN_EXTRA
            . " ELSE '{}' END,
                     :extra_where_path
                 ) IS NOT NULL",
            [
                'metadata_path' => $path,
                'extra_path' => $path,
                'metadata_where_path' => $path,
                'extra_where_path' => $path,
            ],
        );

        $removed = $stmt->rowCount();

        $this->logger->debug("Removed deleted backend metadata for '{identity.backend}' from '{count}' state records.", [
            'operation' => 'prune.metadata',
            'identity' => ['backend' => $backend],
            'count' => $removed,
        ]);

        return $removed;
    }

    private function countBackendRows(DBLayer $db, string $path): int
    {
        $stmt = $db->query(
            'SELECT COUNT(*)
             FROM state
             WHERE
                 json_type(
                     CASE WHEN json_valid('
            . iState::COLUMN_META_DATA
            . ') THEN '
            . iState::COLUMN_META_DATA
            . " ELSE '{}' END,
                     :metadata_path
                 ) IS NOT NULL
             OR
                 json_type(
                     CASE WHEN json_valid("
            . iState::COLUMN_EXTRA
            . ') THEN '
            . iState::COLUMN_EXTRA
            . " ELSE '{}' END,
                     :extra_path
                 ) IS NOT NULL",
            [
                'metadata_path' => $path,
                'extra_path' => $path,
            ],
        );

        $count = $stmt->fetchColumn();

        return false === $count ? 0 : (int) $count;
    }

    private function deleteEmptyRecords(DBLayer $db): int
    {
        $stmt = $db->query(
            'DELETE FROM state
             WHERE
                 '
            . iState::COLUMN_META_DATA
            . ' IS NULL
             OR
                 (
                     json_valid('
            . iState::COLUMN_META_DATA
            . ')
                 AND
                     NOT EXISTS (
                         SELECT 1
                         FROM json_each(
                             CASE WHEN json_valid(state.'
            . iState::COLUMN_META_DATA
            . ') THEN state.'
            . iState::COLUMN_META_DATA
            . " ELSE '{}' END
                         )
                     )
                 )",
        );

        return $stmt->rowCount();
    }

    private function countEmptyRecords(DBLayer $db): int
    {
        $stmt = $db->query(
            'SELECT COUNT(*)
             FROM state
             WHERE
                 '
            . iState::COLUMN_META_DATA
            . ' IS NULL
             OR
                 (
                     json_valid('
            . iState::COLUMN_META_DATA
            . ')
                 AND
                     NOT EXISTS (
                         SELECT 1
                         FROM json_each(
                             CASE WHEN json_valid(state.'
            . iState::COLUMN_META_DATA
            . ') THEN state.'
            . iState::COLUMN_META_DATA
            . " ELSE '{}' END
                         )
                     )
                 )",
        );

        $count = $stmt->fetchColumn();

        return false === $count ? 0 : (int) $count;
    }

    private function jsonPath(string $backend): string
    {
        return '$.' . json_encode($backend, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
