<?php

declare(strict_types=1);

namespace App\Libs\Database\PDO;

use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\DBLayer;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\DBAdapterException as DBException;
use App\Libs\Options;
use Closure;
use DateInterval;
use DateTimeInterface;
use Generator;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Throwable;

/**
 * Class PDOAdapter
 *
 * This class implements the iDB interface and provides functionality for interacting with a database using PDO.
 */
final class PDOAdapter implements iDB
{
    /**
     * @var bool Whether the current operation is in a transaction.
     */
    private bool $viaTransaction = false;

    /**
     * @var array<array-key, PDOStatement> Prepared statements.
     */
    private array $stmt = [
        'insert' => null,
        'update' => null,
    ];

    /**
     * Creates a new instance of the class.
     *
     * @param iLogger $logger The logger object used for logging.
     * @param DBLayer $db The PDO object used for database connections.
     */
    public function __construct(
        private iLogger $logger,
        private readonly DBLayer $db,
        private array $options = [],
    ) {}

    public function with(?iLogger $logger = null, ?DBLayer $db = null, ?array $options = null): self
    {
        if (null === $logger && null === $db && null === $options) {
            return $this;
        }
        return new self($logger ?? $this->logger, $db ?? $this->db, $options ?? $this->options);
    }

    public function withOptions(array $options): self
    {
        return $this->with(options: $options);
    }

    /**
     * @inheritdoc
     */
    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function duplicates(iState $entity, iCache $cache): array
    {
        if (null === ($path = $entity->getMeta(iState::COLUMN_META_PATH, null))) {
            return [];
        }

        $cacheKey = 'dic_' . md5($path);

        if (null !== ($item = $cache->get($cacheKey, null))) {
            return $item;
        }

        $sql = <<<SQL
                    SELECT
                        s.id, json_extract(value, '$.path') AS file_path
                    FROM
                        "state" s, json_each(s.metadata)
                    WHERE
                        json_extract(value, '$.path') = :file_path
                    AND
                        COALESCE(json_extract(value, '$.multi'), 0) = 0
                    ORDER BY
                        s.updated
                    DESC;
            SQL;

        $stmt = $this->db->query($sql, ['file_path' => $path]);

        $items = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (true === array_key_exists((int) $row['id'], $items)) {
                continue;
            }

            if (null === ($item = $this->get($entity::fromArray([iState::COLUMN_ID => $row['id']])))) {
                continue;
            }

            $items[$item->id] = $item;
        }

        $cache->set($cacheKey, $items, new DateInterval('PT5M'));

        return $items;
    }

    /**
     * @inheritdoc
     */
    public function insert(iState $entity, array $opts = []): iState
    {
        try {
            if (null !== ($entity->id ?? null)) {
                throw new DBException(
                    r("Unable to insert item: primary key already defined for '#{id}'.", [
                        'id' => $entity->id,
                    ]),
                    21,
                );
            }

            if (true === $entity->isEpisode() && $entity->episode < 1) {
                throw new DBException(
                    r(
                        "Unexpected episode number '{number}' was given for '{via}: {title}'.",
                        [
                            'via' => $entity->via,
                            'title' => $entity->getName(),
                            'number' => $entity->episode,
                        ],
                    ),
                );
            }

            if (false === in_array($entity->type, [iState::TYPE_MOVIE, iState::TYPE_EPISODE], true)) {
                throw new DBException(
                    r(
                        "Unexpected content type '{type}' was given for '{via}: {title}'. Expected '{types}'.",
                        [
                            'type' => $entity->type,
                            'types' => implode(', ', [iState::TYPE_MOVIE, iState::TYPE_EPISODE]),
                            'id' => $entity->via,
                            'title' => $entity->getName(),
                        ],
                    ),
                    22,
                );
            }

            $data = $entity->getAll();

            if (0 === (int) ag($data, iState::COLUMN_CREATED_AT, 0)) {
                $data[iState::COLUMN_CREATED_AT] = time();
            }
            if (0 === (int) ag($data, iState::COLUMN_UPDATED_AT, 0)) {
                $data[iState::COLUMN_UPDATED_AT] = $data[iState::COLUMN_CREATED_AT];
            }

            unset($data[iState::COLUMN_ID]);

            // -- @TODO i dont like this section, And this should not happen here.
            if (false === $entity->isWatched()) {
                foreach ($data[iState::COLUMN_META_DATA] ?? [] as $via => $metadata) {
                    $data[iState::COLUMN_META_DATA][$via][iState::COLUMN_WATCHED] = '0';
                    if (null === ($metadata[iState::COLUMN_META_DATA_PLAYED_AT] ?? null)) {
                        continue;
                    }
                    unset($data[iState::COLUMN_META_DATA][$via][iState::COLUMN_META_DATA_PLAYED_AT]);
                }
            }

            foreach (iState::ENTITY_ARRAY_KEYS as $key) {
                if (!(null !== ($data[$key] ?? null) && true === is_array($data[$key]))) {
                    continue;
                }

                ksort($data[$key]);
                $data[$key] = json_encode($data[$key], flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            if (null === ($this->stmt['insert'] ?? null)) {
                $this->stmt['insert'] = $this->db->prepare(
                    $this->pdoInsert('state', iState::ENTITY_KEYS),
                );
            }

            $queryOptions = array_replace_recursive($this->options, $opts, [
                'on_failure' => fn(Throwable $e) => $this->retryPreparedWrite(
                    key: 'insert',
                    sql: $this->pdoInsert('state', iState::ENTITY_KEYS),
                    data: $data,
                    opts: $opts,
                    e: $e,
                ),
            ]);

            $this->db->query($this->stmt['insert'], $data, options: $queryOptions);

            $entity->id = (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            $this->resetPreparedWrites();
            $failFast = true === (bool) ($opts[Options::FAIL_FAST_ON_LOCK] ?? $this->options[Options::FAIL_FAST_ON_LOCK] ?? false);
            if (false === $this->viaTransaction) {
                if (true === $failFast) {
                    throw $e;
                }
                $this->logDatabaseFailure('insert', $entity, $e);
                return $entity;
            }
            throw $e;
        }

        return $entity->updateOriginal();
    }

    /**
     * @inheritdoc
     */
    public function get(iState $entity): ?iState
    {
        $inTraceMode = true === (bool) ($this->options[Options::DEBUG_TRACE] ?? false);

        if ($inTraceMode) {
            $this->logger->debug("Looking up '{name}' in the local database.", [
                'event_name' => 'database.state.lookup.started',
                'subsystem' => 'database',
                'operation' => 'lookup',
                'outcome' => 'started',
                'state_id' => null === $entity->id ? null : (string) $entity->id,
                'item_title' => $entity->getName(),
            ]);
        }

        if (null !== $entity->id) {
            $stmt = $this->db->query('SELECT * FROM state WHERE id = :id', ['id' => (int) $entity->id]);

            if (false !== ($item = $stmt->fetch(PDO::FETCH_ASSOC))) {
                $item = $entity::fromArray($item);

                if ($inTraceMode) {
                    $this->logger->debug("Found '{name}' using direct id match.", [
                        'event_name' => 'database.state.lookup.completed',
                        'subsystem' => 'database',
                        'operation' => 'lookup',
                        'outcome' => 'completed',
                        'matched' => true,
                        'match_strategy' => 'direct_id',
                        'name' => $item->getName(),
                        iState::COLUMN_ID => $entity->id,
                    ]);
                }

                return $item;
            }
        }

        if (null !== ($item = $this->findByExternalId($entity))) {
            if ($inTraceMode) {
                $this->logger->debug("Found '{name}' using external id match.", [
                    'event_name' => 'database.state.lookup.completed',
                    'subsystem' => 'database',
                    'operation' => 'lookup',
                    'outcome' => 'completed',
                    'matched' => true,
                    'match_strategy' => 'external_id',
                    'name' => $item->getName(),
                    iState::COLUMN_GUIDS => $entity->getGuids(),
                ]);
            }
            return $item;
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function getAll(?DateTimeInterface $date = null, array $opts = []): array
    {
        $arr = [];

        if (true === array_key_exists('fields', $opts)) {
            $fields = implode(', ', $opts['fields']);
        } else {
            $fields = '*';
        }

        if (true === (bool) ($this->options[Options::DEBUG_TRACE] ?? false)) {
            $this->logger->debug("Selecting database fields '{fields}'.", [
                'event_name' => 'database.state.query.started',
                'subsystem' => 'database',
                'operation' => 'select',
                'outcome' => 'started',
                'fields' => array_to_string($opts['fields'] ?? ['all']),
            ]);
        }

        $sql = "SELECT {$fields} FROM state";

        if (null !== $date) {
            $sql .= ' WHERE ' . $this->dateColumn($opts) . ' > ' . $date->getTimestamp();
        }

        $fromClass = $opts['class'] ?? $this->options['class'] ?? null;
        if (null === ($fromClass ?? null) || false === $fromClass instanceof iState) {
            $class = Container::get(iState::class);
        } else {
            $class = $fromClass;
        }

        foreach ($this->db->query($sql) as $row) {
            $arr[] = $class::fromArray($row);
        }

        return $arr;
    }

    /**
     * @inheritdoc
     */
    public function find(iState ...$items): array
    {
        $list = [];

        foreach ($items as $item) {
            /** @var iState $item */
            if (null === ($entity = $this->get($item))) {
                continue;
            }

            $list[$entity->id] = $entity;
        }

        return $list;
    }

    /**
     * @inheritdoc
     */
    public function findByBackendId(string $backend, int|string $id, ?string $type = null): ?iState
    {
        $key = $backend . '.' . iState::COLUMN_ID;
        $cond = [];

        $type_sql = '';
        if (null !== $type) {
            $type_sql = iState::COLUMN_TYPE . ' = :type AND ';
            $cond['type'] = $type;
        }

        $sql = "SELECT * FROM state WHERE {$type_sql} JSON_EXTRACT(" . iState::COLUMN_META_DATA . ",'$.{$key}') = {id} LIMIT 1";
        $stmt = $this->db->query(r($sql, ['id' => is_int($id) ? $id : $this->db->quote($id)]), $cond);

        if (false === ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            return null;
        }

        $fromClass = $this->options['class'] ?? null;
        if (null === ($fromClass ?? null) || false === $fromClass instanceof iState) {
            $class = Container::get(iState::class);
        } else {
            $class = $fromClass;
        }

        return $class::fromArray($row);
    }

    /**
     * @inheritdoc
     */
    public function update(iState $entity, array $opts = []): iState
    {
        try {
            if (null === ($entity->id ?? null)) {
                throw new DBException(r("Unable to update '{title}' without primary key defined.", [
                    'title' => $entity->getName() ?? 'Unknown',
                ]), 51);
            }

            if (true === $entity->isEpisode() && $entity->episode < 1) {
                throw new DBException(
                    r(
                        "Unexpected episode number '{number}' was given for '#{id}' '{via}: {title}'.",
                        [
                            'id' => $entity->id,
                            'via' => $entity->via,
                            'title' => $entity->getName(),
                            'number' => $entity->episode,
                        ],
                    ),
                );
            }

            $data = $entity->getAll();
            $data[iState::COLUMN_UPDATED_AT] = time();

            // -- @TODO i dont like this block, And this should not happen here.
            if (false === $entity->isWatched()) {
                foreach ($data[iState::COLUMN_META_DATA] ?? [] as $via => $metadata) {
                    $data[iState::COLUMN_META_DATA][$via][iState::COLUMN_WATCHED] = '0';
                    if (null === ($metadata[iState::COLUMN_META_DATA_PLAYED_AT] ?? null)) {
                        continue;
                    }
                    unset($data[iState::COLUMN_META_DATA][$via][iState::COLUMN_META_DATA_PLAYED_AT]);
                }
            }

            foreach (iState::ENTITY_ARRAY_KEYS as $key) {
                if (!(null !== ($data[$key] ?? null) && true === is_array($data[$key]))) {
                    continue;
                }

                ksort($data[$key]);
                $data[$key] = json_encode($data[$key], flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            if (null === ($this->stmt['update'] ?? null)) {
                $this->stmt['update'] = $this->db->prepare($this->pdoUpdate('state', iState::ENTITY_KEYS));
            }

            $queryOptions = array_replace_recursive($this->options, $opts, [
                'on_failure' => fn(Throwable $e) => $this->retryPreparedWrite(
                    key: 'update',
                    sql: $this->pdoUpdate('state', iState::ENTITY_KEYS),
                    data: $data,
                    opts: $opts,
                    e: $e,
                ),
            ]);

            $this->db->query($this->stmt['update'], $data, options: $queryOptions);
        } catch (PDOException $e) {
            $this->resetPreparedWrites();
            $failFast = true === (bool) ($opts[Options::FAIL_FAST_ON_LOCK] ?? $this->options[Options::FAIL_FAST_ON_LOCK] ?? false);
            if (false === $this->viaTransaction) {
                if (true === $failFast) {
                    throw $e;
                }
                $this->logDatabaseFailure('update', $entity, $e);
                return $entity;
            }
            throw $e;
        }

        return $entity->updateOriginal();
    }

    /**
     * @inheritdoc
     */
    public function remove(iState $entity): bool
    {
        if (null === $entity->id && !$entity->hasGuids() && $entity->hasRelativeGuid()) {
            return false;
        }

        try {
            if (null === $entity->id) {
                if (null === ($dbEntity = $this->get($entity))) {
                    return false;
                }
                $id = $dbEntity->id;
            } else {
                $id = $entity->id;
            }

            $this->db->query('DELETE FROM state WHERE id = :id', ['id' => (int) $id]);
        } catch (PDOException $e) {
            $this->logDatabaseFailure('remove', $entity, $e);
            return false;
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function commit(array $entities, array $opts = []): array
    {
        return $this->transactional(function () use ($entities) {
            $actions = [
                'added' => 0,
                'updated' => 0,
                'failed' => 0,
            ];

            foreach ($entities as $entity) {
                try {
                    if (null === $entity->id) {
                        $this->insert($entity);
                        $actions['added']++;
                    } else {
                        $this->update($entity);
                        $actions['updated']++;
                    }
                } catch (PDOException $e) {
                    $actions['failed']++;
                    $this->logDatabaseFailure(null === $entity->id ? 'insert' : 'update', $entity, $e);
                }
            }

            return $actions;
        });
    }

    /**
     * @inheritdoc
     * @noinspection SqlWithoutWhere
     */
    public function reset(): bool
    {
        $this->db->transactional(static function (DBLayer $db) {
            /** @noinspection SqlResolve */
            $tables = $db->query(
                'SELECT
                    "name"
                FROM
                    sqlite_master
                WHERE
                    "type" = "table"
                AND
                    "name" NOT LIKE "sqlite_%"
                AND
                    "name" NOT LIKE "migration_%"
                    ',
            );

            foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $db->exec('DELETE FROM "' . $table . '"');
                $db->exec('DELETE FROM sqlite_sequence WHERE "name" = "' . $table . '"');
            }
        });

        $this->db->exec('VACUUM');

        return true;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(iLogger $logger): iDB
    {
        $this->logger = $logger;

        return $this;
    }

    public function getDBLayer(): DBLayer
    {
        return $this->db;
    }

    /**
     * @inheritdoc
     */
    public function transactional(Closure $callback): mixed
    {
        return $this->db->transactional(function () use ($callback) {
            $this->viaTransaction = true;

            try {
                return $callback($this);
            } finally {
                $this->viaTransaction = false;
            }
        });
    }

    /**
     * @inheritdoc
     */
    public function fetch(array $opts = []): Generator
    {
        $fromClass = $opts['class'] ?? $this->options['class'] ?? null;
        if (null === ($fromClass ?? null) || false === $fromClass instanceof iState) {
            $class = Container::get(iState::class);
        } else {
            $class = $fromClass;
        }

        $fields = '*';
        if (true === array_key_exists('fields', $opts)) {
            $fields = implode(', ', $opts['fields']);
        }

        $sql = "SELECT {$fields} FROM state";
        $bind = [];

        if (true === array_key_exists(Options::AFTER, $opts) && null !== $opts[Options::AFTER]) {
            $after = $opts[Options::AFTER];
            $timestamp = $after instanceof DateTimeInterface ? $after->getTimestamp() : (int) $after;
            $sql .= ' WHERE ' . $this->dateColumn($opts) . ' > :after';
            $bind['after'] = $timestamp;
        }

        $stmt = $this->db->query($sql, $bind);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $class::fromArray($row);
        }
    }

    /**
     * @inheritdoc
     */
    public function getTotal($opts = []): int
    {
        $stmt = $this->db->query('SELECT COUNT(*) FROM state');
        if (false === ($row = $stmt->fetch(PDO::FETCH_NUM))) {
            return 0;
        }

        return (int) $row[0];
    }

    /**
     * Class Destructor
     *
     * This method is called when the object is destroyed. It clears cached prepared statements only.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->resetPreparedWrites();
    }

    private function dateColumn(array $opts): string
    {
        $column = (string) ($opts[Options::DATE_COLUMN] ?? iState::COLUMN_UPDATED);

        if (
            false === in_array(
                $column,
                [
                    iState::COLUMN_UPDATED,
                    iState::COLUMN_CREATED_AT,
                    iState::COLUMN_UPDATED_AT,
                ],
                true,
            )
        ) {
            return iState::COLUMN_UPDATED;
        }

        return $column;
    }

    /**
     * Inserts data into the specified table using PDO.
     *
     * @param string $table The name of the table to insert the data into.
     * @param array $columns An associative array containing the column names and their values.
     *
     * @return string The generated SQL query for the insert operation.
     */
    private function pdoInsert(string $table, array $columns): string
    {
        $queryString = "INSERT INTO {$table} ({columns}) VALUES({values})";

        $sql_columns = $sql_placeholder = [];

        foreach ($columns as $column) {
            if (iState::COLUMN_ID === $column) {
                continue;
            }

            $sql_columns[] = $column;
            $sql_placeholder[] = ':' . $column;
        }

        $queryString = str_replace(
            ['{columns}', '{values}'],
            [implode(', ', $sql_columns), implode(', ', $sql_placeholder)],
            $queryString,
        );

        return trim($queryString);
    }

    /**
     * Generate SQL update statement.
     *
     * @param string $table Table name.
     * @param array $columns Columns to update.
     *
     * @return string SQL update statement.
     */
    private function pdoUpdate(string $table, array $columns): string
    {
        /** @noinspection SqlWithoutWhere */
        $queryString = "UPDATE {$table} SET {place} = {holder} WHERE " . iState::COLUMN_ID . ' = :id';

        $placeholders = [];

        foreach ($columns as $column) {
            if (iState::COLUMN_ID === $column) {
                continue;
            }
            $placeholders[] = r('{column} = :{column}', ['column' => $column]);
        }

        return trim(str_replace('{place} = {holder}', implode(', ', $placeholders), $queryString));
    }

    /**
     * Retry a cached write statement once when sqlite reports prepared statement misuse.
     *
     * @param string $key Cached statement key.
     * @param string $sql SQL statement to prepare.
     * @param array $data Bound statement values.
     * @param Throwable $e Triggering exception.
     */
    private function retryPreparedWrite(string $key, string $sql, array $data, array $opts, Throwable $e): PDOStatement
    {
        if (false === str_contains($e->getMessage(), '21 bad parameter or other API misuse')) {
            throw $e;
        }

        $this->resetPreparedWrites();

        $statement = $this->db->prepare($sql);
        $this->stmt[$key] = $statement;

        return $this->db->query($statement, $data, array_replace_recursive($this->options, $opts));
    }

    private function resetPreparedWrites(): void
    {
        $this->stmt = [
            'insert' => null,
            'update' => null,
        ];
    }

    /**
     * Find db entity using external id.
     * External id format is: (db_name)://(id)
     *
     * @param iState $entity Entity get external ids from.
     *
     * @return iState|null Entity if found, null otherwise.
     */
    private function findByExternalId(iState $entity): ?iState
    {
        $guids = [];
        $cond = [
            'type' => $entity->type,
        ];

        $sqlEpisode = '';

        if (true === $entity->isEpisode()) {
            if (null !== $entity->season) {
                $sqlEpisode .= ' AND ' . iState::COLUMN_SEASON . ' = :season ';
                $cond['season'] = $entity->season;
            }

            if (null !== $entity->episode) {
                $sqlEpisode .= ' AND ' . iState::COLUMN_EPISODE . ' = :episode ';
                $cond['episode'] = $entity->episode;
            }

            foreach ($entity->getParentGuids() as $key => $val) {
                if (empty($val)) {
                    continue;
                }

                $guids[] = 'JSON_EXTRACT(' . iState::COLUMN_PARENT . ",'$.{$key}') = :p_{$key}";
                $cond['p_' . $key] = $val;
            }
        }

        foreach ($entity->getGuids() as $key => $val) {
            if (empty($val)) {
                continue;
            }

            $guids[] = 'JSON_EXTRACT(' . iState::COLUMN_GUIDS . ",'$.{$key}') = :g_{$key}";
            $cond['g_' . $key] = $val;
        }

        if (null !== ($backendId = $entity->getMetadata($entity->via)[iState::COLUMN_ID] ?? null)) {
            $key = $entity->via . '.' . iState::COLUMN_ID;
            $guids[] = 'JSON_EXTRACT(' . iState::COLUMN_META_DATA . ",'$.{$key}') = :m_bid";
            $cond['m_bid'] = $backendId;
        }

        if (empty($guids)) {
            return null;
        }

        $sqlGuids = ' AND ( ' . implode(' OR ', $guids) . ' ) ';

        $sql = 'SELECT * FROM state WHERE ' . iState::COLUMN_TYPE . " = :type {$sqlEpisode} {$sqlGuids} LIMIT 1";
        $stmt = $this->db->query($sql, $cond);

        if (false === ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            return null;
        }

        return $entity::fromArray($row);
    }

    private function logDatabaseFailure(string $operation, iState $entity, PDOException $e): void
    {
        $this->logger->error('Database {operation} failed for {item_type} {item_label}.', [
            'event_name' => 'database.state.operation_failed',
            'subsystem' => 'database',
            'operation' => $operation,
            'outcome' => 'failed',
            'table' => 'state',
            'state_id' => null === $entity->id ? null : (string) $entity->id,
            'item_type' => $entity->type,
            'item_title' => $entity->getName(),
            'item_label' => null !== $entity->id
                ? r("'#{id}: {title}'", [
                    'id' => $entity->id,
                    'title' => $entity->getName(),
                ]) : r("'{title}'", ['title' => $entity->getName()]),
            'entity' => $entity->getAll(),
            'query' => ag($this->db->getLastStatement(), 'sql'),
            'bind' => ag($this->db->getLastStatement(), 'bind', []),
            ...exception_log($e),
        ]);
    }
}
