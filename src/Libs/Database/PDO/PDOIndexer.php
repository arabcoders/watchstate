<?php

declare(strict_types=1);

namespace App\Libs\Database\PDO;

use App\Libs\Database\DBLayer;
use App\Libs\Database\ExternalIndexDiff;
use App\Libs\Database\StateIndexSchema;
use App\Libs\Options;
use App\Libs\UserContext;
use arabcoders\database\Connection;
use PDO;
use Psr\Log\LoggerInterface as iLogger;

/**
 * Class PDOIndexer
 *
 * The PDOIndexer class is responsible for ensuring the existence of required indexes.
 */
final class PDOIndexer
{
    private readonly PDO $pdo;

    /**
     * Class constructor.
     *
     * @param DBLayer|Connection|PDO $db The database layer or package connection used for index operations.
     * @param iLogger $logger The logger object used for logging information.
     */
    public function __construct(
        DBLayer|Connection|PDO $db,
        private iLogger $logger,
        private ?StateIndexSchema $schema = null,
        private ?ExternalIndexDiff $diff = null,
    ) {
        if ($db instanceof DBLayer) {
            $this->pdo = $db->getBackend();
        } else if ($db instanceof Connection) {
            $this->pdo = $db->pdo;
        } else {
            $this->pdo = $db;
        }
    }

    /**
     * Ensures the existence of required indexes in the "state" table.
     *
     * @param array $opts An optional array of additional options.
     *   Supported options:
     *     - force-reindex: Whether to force reindexing (default: false).
     *     - DRY_RUN: Whether to run in dry run mode (default: false).
     *
     * @return bool Returns true if the indexes are successfully created or updated, false otherwise.
     */
    public function ensureIndex(array $opts = []): bool
    {
        $reindex = (bool) ag($opts, 'force-reindex');
        $inDryRunMode = (bool) ag($opts, Options::DRY_RUN);

        if (null !== ($userContext = $opts[UserContext::class] ?? null)) {
            assert($userContext instanceof UserContext, 'Expected UserContext for indexer options.');
        }

        $queries = true === $reindex
            ? $this->diff()->rebuildSql($this->pdo, $this->schema(), $userContext ?? null)
            : $this->diff()->upsertSql($this->pdo, $this->schema(), $userContext ?? null);

        if (true === $inDryRunMode) {
            return false;
        }

        $startedTransaction = false;

        if (!$this->pdo->inTransaction()) {
            $startedTransaction = true;
            $this->pdo->beginTransaction();
        }

        foreach ($queries as $query) {
            $this->logger->debug("PDOIndexer: Running SQL query '{query}'.", [
                'query' => $query,
                'start' => make_date(),
            ]);
            $this->pdo->exec($query);
        }

        if ($startedTransaction && $this->pdo->inTransaction()) {
            $this->pdo->commit();
        }

        if (true === $reindex) {
            $this->pdo->exec('VACUUM;');
        }

        return true;
    }

    private function diff(): ExternalIndexDiff
    {
        return $this->diff ??= new ExternalIndexDiff();
    }

    private function schema(): StateIndexSchema
    {
        return $this->schema ??= new StateIndexSchema();
    }
}
