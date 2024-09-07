<?php

declare(strict_types=1);

namespace App\Libs\Database\PDO;

use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\DBLayer;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\DBAdapterException as DBException;
use App\Libs\Exceptions\DBLayerException;
use App\Libs\Options;
use Closure;
use DateTimeInterface;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use RuntimeException;

/**
 * Class PDOAdapter
 *
 * This class implements the iDB interface and provides functionality for interacting with a database using PDO.
 */
final class PDOAdapter implements iDB
{
    /**
     * @var int The number of times to retry acquiring a lock.
     */
    private const int LOCK_RETRY = 4;

    /**
     * @var bool Whether the current operation is in a transaction.
     */
    private bool $viaTransaction = false;

    /**
     * @var bool Whether the current operation is using a single transaction.
     */
    private bool $singleTransaction = false;

    /**
     * @var array Adapter options.
     */
    private array $options = [];

    /**
     * @var array<array-key, PDOStatement> Prepared statements.
     */
    private array $stmt = [
        'insert' => null,
        'update' => null,
    ];

    /**
     * @var string The database driver to be used.
     */
    private string $driver = 'sqlite';

    /**
     * Creates a new instance of the class.
     *
     * @param LoggerInterface $logger The logger object used for logging.
     * @param DBLayer $db The PDO object used for database connections.
     */
    public function __construct(private LoggerInterface $logger, private readonly DBLayer $db)
    {
        $this->driver = $this->db->getDriver();
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
     * @throws RandomException if an error occurs while generating a random number.
     */
    public function insert(iState $entity): iState
    {
        try {
            if (null !== ($entity->id ?? null)) {
                throw new DBException(
                    r("PDOAdapter: Unable to insert item that has primary key already defined. '#{id}'.", [
                        'id' => $entity->id
                    ]), 21
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
                        ]
                    )
                );
            }

            if (false === in_array($entity->type, [iState::TYPE_MOVIE, iState::TYPE_EPISODE])) {
                throw new DBException(
                    r(
                        "PDOAdapter: Unexpected content type '{type}' was given for '{via}: {title}'. Expecting '{types}'.",
                        [
                            'type' => $entity->type,
                            'types' => implode(', ', [iState::TYPE_MOVIE, iState::TYPE_EPISODE]),
                            'id' => $entity->via,
                            'title' => $entity->getName(),
                        ]
                    ), 22
                );
            }

            $data = $entity->getAll();

            if (0 === (int)ag($data, iState::COLUMN_CREATED_AT, 0)) {
                $data[iState::COLUMN_CREATED_AT] = time();
            }
            if (0 === (int)ag($data, iState::COLUMN_UPDATED_AT, 0)) {
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
                if (null !== ($data[$key] ?? null) && true === is_array($data[$key])) {
                    ksort($data[$key]);
                    $data[$key] = json_encode($data[$key], flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            if (null === ($this->stmt['insert'] ?? null)) {
                $this->stmt['insert'] = $this->db->prepare(
                    $this->pdoInsert('state', iState::ENTITY_KEYS)
                );
            }

            $this->execute($this->stmt['insert'], $data);

            $entity->id = (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            $this->stmt['insert'] = null;
            if (false === $this->viaTransaction && false === $this->singleTransaction) {
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
                    ]
                );
                return $entity;
            }
            throw $e;
        }

        return $entity->updateOriginal();
    }

    /**
     * @inheritdoc
     * @throws RandomException if an error occurs while generating a random number.
     */
    public function get(iState $entity): iState|null
    {
        $inTraceMode = true === (bool)($this->options[Options::DEBUG_TRACE] ?? false);

        if ($inTraceMode) {
            $this->logger->debug("PDOAdapter: Looking for '{name}'.", ['name' => $entity->getName()]);
        }

        if (null !== $entity->id) {
            $stmt = $this->query(
                r(
                    'SELECT * FROM state WHERE ${column} = ${id}',
                    context: [
                        'column' => iState::COLUMN_ID,
                        'id' => (int)$entity->id
                    ],
                    opts: [
                        'tag_left' => '${',
                        'tag_right' => '}'
                    ],
                )
            );

            if (false !== ($item = $stmt->fetch(PDO::FETCH_ASSOC))) {
                $item = $entity::fromArray($item);

                if ($inTraceMode) {
                    $this->logger->debug("PDOAdapter: Found '{name}' using direct id match.", [
                        'name' => $item->getName(),
                        iState::COLUMN_ID => $entity->id
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
     * @throws RandomException if an error occurs while generating a random number.
     */
    public function getAll(DateTimeInterface|null $date = null, array $opts = []): array
    {
        $arr = [];

        if (true === array_key_exists('fields', $opts)) {
            $fields = implode(', ', $opts['fields']);
        } else {
            $fields = '*';
        }

        if (true === (bool)($this->options[Options::DEBUG_TRACE] ?? false)) {
            $this->logger->debug("PDOAdapter: Selecting fields '{fields}'.", [
                'fields' => arrayToString($opts['fields'] ?? ['all'])
            ]);
        }

        $sql = "SELECT {$fields} FROM state";

        if (null !== $date) {
            $sql .= ' WHERE ' . iState::COLUMN_UPDATED . ' > ' . $date->getTimestamp();
        }

        if (null === ($opts['class'] ?? null) || false === ($opts['class'] instanceof iState)) {
            $class = Container::get(iState::class);
        } else {
            $class = $opts['class'];
        }

        foreach ($this->query($sql) as $row) {
            $arr[] = $class::fromArray($row);
        }

        return $arr;
    }

    /**
     * @inheritdoc
     * @throws RandomException if an error occurs while generating a random number.
     */
    public function find(iState ...$items): array
    {
        $list = [];

        foreach ($items as $item) {
            if (null === ($entity = $this->get($item))) {
                continue;
            }

            $list[$entity->id] = $entity;
        }

        return $list;
    }

    /**
     * @inheritdoc
     * @throws RandomException
     */
    public function findByBackendId(string $backend, int|string $id, string|null $type = null): iState|null
    {
        $key = $backend . '.' . iState::COLUMN_ID;
        $cond = [
            'id' => $id
        ];

        $type_sql = '';
        if (null !== $type) {
            $type_sql = iState::COLUMN_TYPE . ' = :type AND ';
            $cond['type'] = $type;
        }

        $sql = "SELECT * FROM state WHERE {$type_sql} JSON_EXTRACT(" . iState::COLUMN_META_DATA . ",'$.{$key}') = :id LIMIT 1";
        $stmt = $this->db->prepare($sql);

        if (false === $this->execute($stmt, $cond)) {
            throw new DBException(
                r("PDOAdapter: Failed to execute sql query. Statement '{sql}', Conditions '{cond}'.", [
                    'sql' => $sql,
                    'cond' => arrayToString($cond),
                ]), 61
            );
        }

        if (false === ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            return null;
        }

        return Container::get(iState::class)::fromArray($row);
    }


    /**
     * @inheritdoc
     * @throws RandomException if an error occurs while generating a random number.
     */
    public function update(iState $entity): iState
    {
        try {
            if (null === ($entity->id ?? null)) {
                throw new DBException(
                    r("PDOAdapter: Unable to update '{title}' without primary key defined.", [
                        'title' => $entity->getName() ?? 'Unknown'
                    ]), 51
                );
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
                        ]
                    )
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
                if (null !== ($data[$key] ?? null) && true === is_array($data[$key])) {
                    ksort($data[$key]);
                    $data[$key] = json_encode($data[$key], flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            if (null === ($this->stmt['update'] ?? null)) {
                $this->stmt['update'] = $this->db->prepare(
                    $this->pdoUpdate('state', iState::ENTITY_KEYS)
                );
            }

            $this->execute($this->stmt['update'], $data);
        } catch (PDOException $e) {
            $this->stmt['update'] = null;
            if (false === $this->viaTransaction && false === $this->singleTransaction) {
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
                        ]
                    ]
                );
                return $entity;
            }
            throw $e;
        }

        return $entity->updateOriginal();
    }

    /**
     * @inheritdoc
     * @throws RandomException if an error occurs while generating a random number.
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

            $this->query(
                r(
                    'DELETE FROM state WHERE ${column} = ${id}',
                    [
                        'column' => iState::COLUMN_ID,
                        'id' => (int)$id
                    ],
                    opts: [
                        'tag_left' => '${',
                        'tag_right' => '}'
                    ]
                )
            );
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
                ]
            );
            return false;
        }

        return true;
    }

    /**
     * @inheritdoc
     * @throws RandomException if an error occurs while generating a random number.
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
                        ]
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
                'name' => $dir
            ]), 91),
        };
    }

    /**
     * @inheritdoc
     */
    public function ensureIndex(array $opts = []): mixed
    {
        return (new PDOIndexer($this->db, $this->logger))->ensureIndex($opts);
    }

    /**
     * @inheritdoc
     */
    public function migrateData(string $version, LoggerInterface|null $logger = null): mixed
    {
        return (new PDODataMigration($this->db, $logger ?? $this->logger))->automatic();
    }

    /**
     * @inheritdoc
     */
    public function isMigrated(): bool
    {
        return (new PDOMigrations($this->db, $this->logger))->isMigrated();
    }

    /**
     * @inheritdoc
     */
    public function makeMigration(string $name, array $opts = []): mixed
    {
        return (new PDOMigrations($this->db, $this->logger))->make($name);
    }

    /**
     * @inheritdoc
     */
    public function maintenance(array $opts = []): mixed
    {
        return (new PDOMigrations($this->db, $this->logger))->runMaintenance();
    }

    /**
     * @inheritdoc
     * @noinspection SqlWithoutWhere
     */
    public function reset(): bool
    {
        $this->db->transactional(function (DBLayer $db) {
            /** @noinspection SqlResolve */
            $tables = $db->query(
                'SELECT name FROM sqlite_master WHERE "type" = "table" AND "name" NOT LIKE "sqlite_%"'
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
    public function setLogger(LoggerInterface $logger): iDB
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
    public function singleTransaction(): bool
    {
        $this->singleTransaction = true;

        if (false === $this->db->inTransaction()) {
            $this->db->start();
        }

        return $this->db->inTransaction();
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
     * Class Destructor
     *
     * This method is called when the object is destroyed. It checks if a transaction is in progress and commits it
     * if necessary. It also clears the statement list array.
     *
     * @return void
     */
    public function __destruct()
    {
        if (true === $this->singleTransaction && true === $this->db->inTransaction()) {
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
            $queryString
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
        $queryString = "UPDATE {$table} SET {place} = {holder} WHERE " . iState::COLUMN_ID . " = :id";

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
     * @throws RandomException if an error occurs while generating a random number.
     */
    private function findByExternalId(iState $entity): iState|null
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

                $guids[] = "JSON_EXTRACT(" . iState::COLUMN_PARENT . ",'$.{$key}') = :p_{$key}";
                $cond['p_' . $key] = $val;
            }
        }

        foreach ($entity->getGuids() as $key => $val) {
            if (empty($val)) {
                continue;
            }

            $guids[] = "JSON_EXTRACT(" . iState::COLUMN_GUIDS . ",'$.{$key}') = :g_{$key}";
            $cond['g_' . $key] = $val;
        }

        if (null !== ($backendId = $entity->getMetadata($entity->via)[iState::COLUMN_ID] ?? null)) {
            $key = $entity->via . '.' . iState::COLUMN_ID;
            $guids[] = "JSON_EXTRACT(" . iState::COLUMN_META_DATA . ",'$.{$key}') = :m_bid";
            $cond['m_bid'] = $backendId;
        }

        if (empty($guids)) {
            return null;
        }

        $sqlGuids = ' AND ( ' . implode(' OR ', $guids) . ' ) ';

        $sql = "SELECT * FROM state WHERE " . iState::COLUMN_TYPE . " = :type {$sqlEpisode} {$sqlGuids} LIMIT 1";

        $stmt = $this->db->prepare($sql);

        if (false === $this->execute($stmt, $cond)) {
            throw new DBException(
                r("PDOAdapter: Failed to execute sql query. Statement '{sql}', Conditions '{cond}'.", [
                    'sql' => $sql,
                    'cond' => arrayToString($cond),
                ]), 61
            );
        }

        if (false === ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            return null;
        }

        return $entity::fromArray($row);
    }

    /**
     * Executes a prepared SQL statement with optional parameters.
     *
     * @param PDOStatement $stmt The prepared statement to execute.
     * @param array $cond An optional array of parameters to bind to the statement.
     * @return bool True if the statement was successfully executed, false otherwise.
     *
     * @throws PDOException if an error occurs during the execution of the statement.
     * @throws RandomException if an error occurs while generating a random number.
     */
    private function execute(PDOStatement $stmt, array $cond = []): bool
    {
        return $this->wrap(fn(PDOAdapter $adapter) => $stmt->execute($cond));
    }

    /**
     * Executes a SQL query on the database.
     *
     * @param string $sql The SQL query to be executed.
     *
     * @return PDOStatement|false The result of the query as a PDOStatement object.
     *                            It will return false if the query fails.
     *
     * @throws PDOException If an error occurs while executing the query.
     * @throws RandomException If an error occurs while generating a random number.
     */
    private function query(string $sql): PDOStatement|false
    {
        return $this->wrap(fn(PDOAdapter $adapter) => $adapter->db->query($sql));
    }

    /**
     * FOR DEBUGGING AND DISPLAY PURPOSES ONLY.
     *
     * @note Do not use it for anything.
     * @param string $sql
     * @param array $parameters
     * @return string
     *
     * @internal This is for debugging purposes only.
     */
    public function getRawSQLString(string $sql, array $parameters): string
    {
        $replacer = [];

        foreach ($parameters as $key => $val) {
            $replacer['/(\:' . preg_quote($key, '/') . ')(?:\b|\,)/'] = ctype_digit(
                (string)$val
            ) ? (int)$val : '"' . $val . '"';
        }

        return preg_replace(array_keys($replacer), array_values($replacer), $sql);
    }

    /**
     * Generates a valid identifier for a table or column.
     *
     * @param string $text The input text to be transformed into a valid identifier.
     * @param bool $quote Indicates whether the generated identifier should be quoted.
     *                    By default, it is set to true.
     *
     * @return string The generated identifier.
     * @throws RuntimeException If the input text is not a valid ASCII name or does not meet the naming convention requirements.
     */
    public function identifier(string $text, bool $quote = true): string
    {
        // table or column has to be valid ASCII name.
        // this is opinionated, but we only allow [a-zA-Z0-9_] in column/table name.
        if (!\preg_match('#\w#', $text)) {
            throw new RuntimeException(
                r("PDOAdapter: Invalid column/table '{ident}'. Column/table must be valid ASCII code.", [
                    'ident' => $text
                ])
            );
        }

        // The first character cannot be [0-9]:
        if (\preg_match('/^\d/', $text)) {
            throw new RuntimeException(
                r("PDOAdapter: Invalid column/table '{ident}'. Must begin with a letter or underscore.", [
                        'ident' => $text
                    ]
                )
            );
        }

        return !$quote ? $text : match ($this->driver) {
            'mssql' => '[' . $text . ']',
            'mysql' => '`' . $text . '`',
            default => '"' . $text . '"',
        };
    }

    /**
     * Wraps the given callback function with a retry mechanism to handle database locks.
     *
     * @param Closure $callback The callback function to be executed.
     *
     * @return mixed The result of the callback function.
     *
     * @throws DBLayerException If an error occurs while executing the callback function.
     * @throws RandomException If an error occurs while generating a random number.
     */
    private function wrap(Closure $callback): mixed
    {
        for ($i = 0; $i <= self::LOCK_RETRY; $i++) {
            try {
                return $callback($this);
            } catch (PDOException $e) {
                if (true === str_contains(strtolower($e->getMessage()), 'database is locked')) {
                    if ($i >= self::LOCK_RETRY) {
                        throw (new DBLayerException($e->getMessage(), (int)$e->getCode(), $e))
                            ->setInfo(
                                ag($this->db->getLastStatement(), 'sql', ''),
                                ag($this->db->getLastStatement(), 'bind', []),
                                $e->errorInfo ?? [],
                                $e->getCode()
                            )
                            ->setFile($e->getFile())
                            ->setLine($e->getLine());
                    }

                    $sleep = self::LOCK_RETRY + random_int(1, 3);

                    $this->logger->warning("PDOAdapter: Database is locked. sleeping for '{sleep}s'.", [
                        'sleep' => $sleep
                    ]);

                    sleep($sleep);
                } else {
                    throw (new DBLayerException($e->getMessage(), (int)$e->getCode(), $e))
                        ->setInfo(
                            ag($this->db->getLastStatement(), 'sql', ''),
                            ag($this->db->getLastStatement(), 'bind', []),
                            $e->errorInfo ?? [],
                            $e->getCode()
                        )
                        ->setFile($e->getFile())
                        ->setLine($e->getLine());
                }
            }
        }

        return false;
    }
}
