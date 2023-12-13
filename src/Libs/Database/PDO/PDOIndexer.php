<?php

declare(strict_types=1);

namespace App\Libs\Database\PDO;

use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\Options;
use PDO;
use Psr\Log\LoggerInterface as iLogger;

final class PDOIndexer
{
    /**
     * A constant array that defines the columns to be ignored in the index.
     *
     * The array contains the predefined constants defined in the iState class,
     * representing different columns such as COLUMN_ID, COLUMN_PARENT,
     * COLUMN_GUIDS, COLUMN_EXTRA, and COLUMN_META_DATA.
     *
     * @var array
     */
    private const INDEX_IGNORE_ON = [
        iState::COLUMN_ID,
        iState::COLUMN_PARENT,
        iState::COLUMN_GUIDS,
        iState::COLUMN_EXTRA,
        iState::COLUMN_META_DATA,
    ];

    /**
     * BACKEND_INDEXES constant representing an array of backend indexes.
     *
     * This constant contains backend indexes which are used to represent specific columns in the backend.
     * It is an array of constants defined in the iState interface.
     * The indexes in this array are used to access specific columns in the backend.
     *
     * @var array
     */
    private const BACKEND_INDEXES = [
        iState::COLUMN_ID,
        iState::COLUMN_META_SHOW,
        iState::COLUMN_META_LIBRARY,
    ];

    /**
     * Class constructor.
     *
     * @param PDO $db The PDO object used for database connections and queries.
     * @param iLogger $logger The logger object used for logging information.
     */
    public function __construct(private PDO $db, private iLogger $logger)
    {
    }

    /**
     * Ensures the existence of required indexes in the "state" table.
     *
     * @param array $opts An optional array of additional options.
     *   Supported options:
     *     - force-reindex: Whether to force reindexing (default: false).
     *
     * @return bool Returns true if the indexes are successfully created or updated, false otherwise.
     */
    public function ensureIndex(array $opts = []): bool
    {
        $queries = [];

        $drop = 'DROP INDEX IF EXISTS "${name}"';
        $insert = 'CREATE INDEX IF NOT EXISTS "${name}" ON "state" (${expr});';

        $reindex = (bool)ag($opts, 'force-reindex');
        $inDryRunMode = (bool)ag($opts, Options::DRY_RUN);

        if (true === $reindex) {
            $this->logger->debug('Force reindex is called.');
            $sql = "select name FROM sqlite_master WHERE tbl_name = 'state' AND type = 'index';";

            foreach ($this->db->query($sql) as $row) {
                $name = ag($row, 'name');
                $query = r(
                    text: $drop,
                    context: [
                        'name' => $name
                    ],
                    opts: [
                        'tag_left' => '${',
                        'tag_right' => '}'
                    ]
                );
                $this->logger->debug('Dropping Index [{index}].', [
                    'index' => $name,
                    'query' => $query,
                ]);

                $queries[] = $query;
            }
        }

        foreach (iState::ENTITY_KEYS as $column) {
            if (true === in_array($column, self::INDEX_IGNORE_ON)) {
                continue;
            }

            $query = r(
                text: $insert,
                context: [
                    'name' => sprintf('state_%s', $column),
                    'expr' => sprintf('"%s"', $column),
                ],
                opts: [
                    'tag_left' => '${',
                    'tag_right' => '}'
                ]
            );

            $this->logger->debug('Generating index on [{column}].', [
                'column' => $column,
                'query' => $query,
            ]);

            $queries[] = $query;
        }

        // -- Ensure main parent/guids sub keys are indexed.
        foreach (array_keys(Guid::getSupported()) as $subKey) {
            foreach ([iState::COLUMN_PARENT, iState::COLUMN_GUIDS] as $column) {
                $query = r(
                    text: $insert,
                    context: [
                        'name' => sprintf('state_%s_%s', $column, $subKey),
                        'expr' => sprintf("JSON_EXTRACT(%s,'$.%s')", $column, $subKey),
                    ],
                    opts: ['tag_left' => '${', 'tag_right' => '}']
                );

                $this->logger->debug('Generating index on {column} column [{key}] key.', [
                    'column' => $column,
                    'key' => $subKey,
                    'query' => $query,
                ]);

                $queries[] = $query;
            }
        }

        // -- Ensure backends metadata.id,metadata.show are indexed
        foreach (array_keys(Config::get('servers', [])) as $backend) {
            foreach (self::BACKEND_INDEXES as $subKey) {
                $query = r(
                    text: $insert,
                    context: [
                        'name' => sprintf('state_%s_%s_%s', iState::COLUMN_META_DATA, $backend, $subKey),
                        'expr' => sprintf("JSON_EXTRACT(%s,'$.%s.%s')", iState::COLUMN_META_DATA, $backend, $subKey),
                    ],
                    opts: ['tag_left' => '${', 'tag_right' => '}']
                );

                $this->logger->debug('Generating index on [{backend}] metadata column [{key}] key.', [
                    'backend' => $backend,
                    'key' => $subKey,
                    'query' => $query,
                ]);

                $queries[] = $query;
            }
        }

        if (true === $inDryRunMode) {
            return false;
        }

        $startedTransaction = false;

        if (!$this->db->inTransaction()) {
            $startedTransaction = true;
            $this->db->beginTransaction();
        }

        foreach ($queries as $query) {
            $this->logger->debug('Running query.', [
                'query' => $query,
                'start' => makeDate(),
            ]);
            $this->db->query($query);
        }

        if ($startedTransaction && $this->db->inTransaction()) {
            $this->db->commit();
        }

        if (true === $reindex) {
            $this->db->query('VACUUM;');
        }

        return true;
    }
}
