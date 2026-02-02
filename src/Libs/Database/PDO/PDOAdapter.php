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
    public function insert(iState $entity): iState
    {
        try {
            if (null !== ($entity->id ?? null)) {
                throw new DBException(
                    r("PDOAdapter: Unable to insert item that has primary key already defined. '#{id}'.", [
                        'id' => $entity->id,
                    ]),
                    21,
                );
            }

            if (true === $entity->isEpisode() && $entity->episode < 1) {
                throw new DBException(
                    r(
                        "PDOAdapter: Unexpected episode number '{number}' was given for '{via}: {title}'.",
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
                        "PDOAdapter: Unexpected content type '{type}' was given for '{via}: {title}'. Expecting '{types}'.",
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

            $this->db->query($this->stmt['insert'], $data, options: [
                'on_failure' => function (Throwable $e) use ($entity) {
                    if (false === str_contains($e->getMessage(), '21 bad parameter or other API misuse')) {
                        throw $e;
                    }
                    $this->stmt['insert'] = null;
                    return $this->insert($entity);
                },
            ]);

            $entity->id = (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            $this->stmt['insert'] = null;
            if (false === $this->viaTransaction) {
                $this->logger->error(
                    message: "PDOAdapter: Exception '{error.kind}' was thrown unhandled. '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'entity' => $entity->getAll(),
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'exception' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'trace' => $e->getTrace(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'last' => $this->db->getLastStatement(),
                    ],
                );
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
            $this->logger->debug("PDOAdapter: Looking for '{name}'.", ['name' => $entity->getName()]);
        }

        if (null !== $entity->id) {
            $stmt = $this->db->query('SELECT * FROM state WHERE id = :id', ['id' => (int) $entity->id]);

            if (false !== ($item = $stmt->fetch(PDO::FETCH_ASSOC))) {
                $item = $entity::fromArray($item);

                if ($inTraceMode) {
                    $this->logger->debug("PDOAdapter: Found '{name}' using direct id match.", [
                        'name' => $item->getName(),
                        iState::COLUMN_ID => $entity->id,
                    ]);
                }

                return $item;
            }
        }

        if (null !== ($item = $this->findByExternalId($entity))) {
            if ($inTraceMode) {
                $this->logger->debug("PDOAdapter: Found '{name}' using external id match.", [
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
            $this->logger->debug("PDOAdapter: Selecting fields '{fields}'.", [
                'fields' => array_to_string($opts['fields'] ?? ['all']),
            ]);
        }

        $sql = "SELECT {$fields} FROM state";

        if (null !== $date) {
            $sql .= ' WHERE ' . iState::COLUMN_UPDATED . ' > ' . $date->getTimestamp();
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
    public function update(iState $entity): iState
    {
        try {
            if (null === ($entity->id ?? null)) {
                throw new DBException(r("PDOAdapter: Unable to update '{title}' without primary key defined.", [
                    'title' => $entity->getName() ?? 'Unknown',
                ]), 51);
            }

            if (true === $entity->isEpisode() && $entity->episode < 1) {
                throw new DBException(
                    r(
                        "PDOAdapter: Unexpected episode number '{number}' was given for '#{id}' '{via}: {title}'.",
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

            $this->db->query($this->stmt['update'], $data, options: [
                'on_failure' => function (Throwable $e) use ($entity) {
                    if (false === str_contains($e->getMessage(), '21 bad parameter or other API misuse')) {
                        throw $e;
                    }
                    $this->stmt['update'] = null;
                    return $this->update($entity);
                },
            ]);
        } catch (PDOException $e) {
            $this->stmt['update'] = null;
            if (false === $this->viaTransaction) {
                $this->logger->error(
                    message: "PDOAdapter: Exception '{error.kind}' was thrown unhandled. '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'entity' => $entity->getAll(),
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'exception' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'trace' => $e->getTrace(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'last' => $this->db->getLastStatement(),
                    ],
                );
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
            $this->logger->error(
                message: "PDOAdapter: Exception '{error.kind}' was thrown unhandled. '{error.message}' at '{error.file}:{error.line}'.",
                context: [
                    'entity' => $entity->getAll(),
                    'error' => [
                        'kind' => $e::class,
                        'line' => $e->getLine(),
                        'message' => $e->getMessage(),
                        'file' => after($e->getFile(), ROOT_PATH),
                    ],
                    'exception' => [
                        'kind' => $e::class,
                        'line' => $e->getLine(),
                        'trace' => $e->getTrace(),
                        'message' => $e->getMessage(),
                        'file' => after($e->getFile(), ROOT_PATH),
                    ],
                ],
            );
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
                    $this->logger->error(
                        message: "PDOAdapter: Exception '{error.kind}' was thrown unhandled. '{error.message}' at '{error.file}:{error.line}'.",
                        context: [
                            'entity' => $entity->getAll(),
                            'error' => [
                                'kind' => $e::class,
                                'line' => $e->getLine(),
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                            'exception' => [
                                'kind' => $e::class,
                                'line' => $e->getLine(),
                                'trace' => $e->getTrace(),
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                        ],
                    );
                }
            }

            return $actions;
        });
    }

    /**
     * @inheritdoc
     */
    public function migrations(string $dir, array $opts = []): mixed
    {
        $class = new PDOMigrations($this->db, $this->logger);

        return match (strtolower($dir)) {
            iDB::MIGRATE_UP => $class->up(),
            iDB::MIGRATE_DOWN => $class->down(),
            default => throw new DBException(r("PDOAdapter: Unknown migration direction '{dir}' was given.", [
                'name' => $dir,
            ]), 91),
        };
    }

    /**
     * @inheritdoc
     */
    public function ensureIndex(array $opts = []): mixed
    {
        return new PDOIndexer($this->db, $this->logger)->ensureIndex($opts);
    }

    /**
     * @inheritdoc
     */
    public function migrateData(string $version, ?iLogger $logger = null): mixed
    {
        return new PDODataMigration($this->db, $logger ?? $this->logger)->automatic();
    }

    /**
     * @inheritdoc
     */
    public function isMigrated(): bool
    {
        return new PDOMigrations($this->db, $this->logger)->isMigrated();
    }

    /**
     * @inheritdoc
     */
    public function makeMigration(string $name, array $opts = []): mixed
    {
        return new PDOMigrations($this->db, $this->logger)->make($name);
    }

    /**
     * @inheritdoc
     */
    public function maintenance(array $opts = []): mixed
    {
        return new PDOMigrations($this->db, $this->logger)->runMaintenance();
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
                'SELECT name FROM sqlite_master WHERE "type" = "table" AND "name" NOT LIKE "sqlite_%"',
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
        if (true === $this->db->inTransaction()) {
            $this->viaTransaction = true;
            $result = $callback($this);
            $this->viaTransaction = false;
            return $result;
        }

        try {
            $this->db->start();

            $this->viaTransaction = true;
            $result = $callback($this);
            $this->viaTransaction = false;

            $this->db->commit();

            return $result;
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        } finally {
            $this->viaTransaction = false;
        }
    }

    /**
     * @inheritdoc
     */
    public function fetch(array $opts = []): Generator
    {
        $fromClass = $this->options['class'] ?? null;
        if (null === ($fromClass ?? null) || false === $fromClass instanceof iState) {
            $class = Container::get(iState::class);
        } else {
            $class = $fromClass;
        }

        $stmt = $this->db->query('SELECT * FROM state');
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
     * This method is called when the object is destroyed. It checks if a transaction is in progress and commits it
     * if necessary. It also clears the statement list array.
     *
     * @return void
     */
    public function __destruct()
    {
        if (true === $this->db->inTransaction()) {
            $this->db->commit();
        }

        $this->stmt = [];
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
}
