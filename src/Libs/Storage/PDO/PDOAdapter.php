<?php

declare(strict_types=1);

namespace App\Libs\Storage\PDO;

use App\Libs\Container;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use App\Libs\Options;
use App\Libs\Storage\StorageException;
use App\Libs\Storage\StorageInterface;
use Closure;
use DateTimeInterface;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;

final class PDOAdapter implements StorageInterface
{
    private bool $viaTransaction = false;
    private bool $singleTransaction = false;

    private array $options = [];

    /**
     * Cache Prepared Statements.
     *
     * @var array<array-key, PDOStatement>
     */
    private array $stmt = [
        'insert' => null,
        'update' => null,
    ];

    public function __construct(private LoggerInterface $logger, private PDO $pdo)
    {
    }

    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function insert(iFace $entity): iFace
    {
        try {
            if (null !== ($entity->id ?? null)) {
                throw new StorageException(
                    sprintf('Unable to insert item that has primary key. \'%s\'.', $entity->id), 21
                );
            }

            $data = $entity->getAll();
            unset($data[iFace::COLUMN_ID]);

            if (false === $entity->isWatched()) {
                foreach ($data[iFace::COLUMN_META_DATA] ?? [] as $via => $metadata) {
                    $data[iFace::COLUMN_META_DATA][$via][iFace::COLUMN_WATCHED] = '0';
                    if (null === ($metadata[iFace::COLUMN_META_DATA_PLAYED_AT] ?? null)) {
                        continue;
                    }
                    unset($data[iFace::COLUMN_META_DATA][$via][iFace::COLUMN_META_DATA_PLAYED_AT]);
                }
            }

            foreach (iFace::ENTITY_ARRAY_KEYS as $key) {
                if (null !== ($data[$key] ?? null) && is_array($data[$key])) {
                    ksort($data[$key]);
                    $data[$key] = json_encode($data[$key], flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            if (null === ($this->stmt['insert'] ?? null)) {
                $this->stmt['insert'] = $this->pdo->prepare(
                    $this->pdoInsert('state', iFace::ENTITY_KEYS)
                );
            }

            $this->stmt['insert']->execute($data);

            $entity->id = (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            $this->stmt['insert'] = null;
            if (false === $this->viaTransaction && false === $this->singleTransaction) {
                $this->logger->error($e->getMessage(), $entity->getAll());
                return $entity;
            }
            throw $e;
        }

        return $entity->updateOriginal();
    }

    public function get(iFace $entity): iFace|null
    {
        $inTraceMode = true === (bool)($this->options[Options::DEBUG_TRACE] ?? false);

        if ($inTraceMode) {
            $this->logger->debug(sprintf('STORAGE: Looking for \'%s\'.', $entity->getName()));
        }

        if (null !== $entity->id) {
            $stmt = $this->pdo->query(sprintf('SELECT * FROM state WHERE %s = %d', iFace::COLUMN_ID, (int)$entity->id));

            if (false !== ($item = $stmt->fetch(PDO::FETCH_ASSOC))) {
                $item = $entity::fromArray($item);

                if ($inTraceMode) {
                    $this->logger->debug(sprintf('STORAGE: Found \'%s\' using direct id match.', $item->getName()), [
                        iFace::COLUMN_ID => $entity->id
                    ]);
                }
                return $item;
            }
        }

        if (!empty($entity->via) && null !== ($entity->metadata[$entity->via][iFace::COLUMN_ID] ?? null)) {
            if (null !== ($item = $this->findByMetaDataId($entity))) {
                if ($inTraceMode) {
                    $this->logger->debug(sprintf('STORAGE: Found \'%s\' using metadata field.', $item->getName()), [
                        $entity->via => [
                            iFace::COLUMN_ID => $entity->metadata[$entity->via][iFace::COLUMN_ID],
                        ]
                    ]);
                }
                return $item;
            }
        }

        if ($entity->isEpisode() && $entity->hasRelativeGuid() && null !== ($item = $this->findByRGuid($entity))) {
            if ($inTraceMode) {
                $this->logger->debug(
                    sprintf('STORAGE: Found \'%s\' using relative external id match.', $item->getName()),
                    [
                        iFace::COLUMN_SEASON => $entity->season,
                        iFace::COLUMN_EPISODE => $entity->episode,
                        iFace::COLUMN_PARENT => $entity->getParentGuids(),
                    ]
                );
            }
            return $item;
        }

        if ($entity->hasGuids() && null !== ($item = $this->findByGuid($entity))) {
            if ($inTraceMode) {
                $this->logger->debug(sprintf('STORAGE: Found \'%s\' using external id match.', $item->getName()), [
                    iFace::COLUMN_GUIDS => $entity->getGuids(),
                ]);
            }
            return $item;
        }

        return null;
    }

    public function getAll(DateTimeInterface|null $date = null, iFace|null $class = null): array
    {
        $arr = [];

        $sql = 'SELECT * FROM state';

        if (null !== $date) {
            $sql .= ' WHERE ' . iFace::COLUMN_UPDATED . ' > ' . $date->getTimestamp();
        }

        if (null === $class) {
            $class = Container::get(iFace::class);
        }

        foreach ($this->pdo->query($sql) as $row) {
            $arr[] = $class::fromArray($row);
        }

        return $arr;
    }

    public function getCount(DateTimeInterface|null $date = null): int
    {
        $sql = 'SELECT COUNT(id) AS total FROM state';

        if (null !== $date) {
            $sql .= ' WHERE ' . iFace::COLUMN_UPDATED . ' > ' . $date->getTimestamp();
        }

        return $this->pdo->query($sql)->fetchColumn();
    }

    public function find(iFace ...$items): array
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

    public function update(iFace $entity): iFace
    {
        try {
            if (null === ($entity->id ?? null)) {
                throw new StorageException('Unable to update item without primary key.', 51);
            }

            $data = $entity->getAll();

            if (false === $entity->isWatched()) {
                foreach ($data[iFace::COLUMN_META_DATA] ?? [] as $via => $metadata) {
                    $data[iFace::COLUMN_META_DATA][$via][iFace::COLUMN_WATCHED] = '0';
                    if (null === ($metadata[iFace::COLUMN_META_DATA_PLAYED_AT] ?? null)) {
                        continue;
                    }
                    unset($data[iFace::COLUMN_META_DATA][$via][iFace::COLUMN_META_DATA_PLAYED_AT]);
                }
            }

            foreach (iFace::ENTITY_ARRAY_KEYS as $key) {
                if (null !== ($data[$key] ?? null) && is_array($data[$key])) {
                    ksort($data[$key]);
                    $data[$key] = json_encode($data[$key], flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            if (null === ($this->stmt['update'] ?? null)) {
                $this->stmt['update'] = $this->pdo->prepare(
                    $this->pdoUpdate('state', iFace::ENTITY_KEYS)
                );
            }

            $this->stmt['update']->execute($data);
        } catch (PDOException $e) {
            $this->stmt['update'] = null;
            if (false === $this->viaTransaction && false === $this->singleTransaction) {
                $this->logger->error($e->getMessage(), $entity->getAll());
                return $entity;
            }
            throw $e;
        }

        return $entity->updateOriginal();
    }

    public function remove(iFace $entity): bool
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

            $this->pdo->query(sprintf('DELETE FROM state WHERE %s = %d', iFace::COLUMN_ID, (int)$id));
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage());
            return false;
        }

        return true;
    }

    public function commit(array $entities, array $opts = []): array
    {
        $actions = [
            'added' => 0,
            'updated' => 0,
            'failed' => 0,
        ];

        return $this->transactional(function () use ($entities, $actions) {
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
                    $this->logger->error($e->getMessage(), $entity->getAll());
                }
            }

            return $actions;
        });
    }

    public function migrations(string $dir, array $opts = []): mixed
    {
        $class = new PDOMigrations($this->pdo, $this->logger);

        return match (strtolower($dir)) {
            StorageInterface::MIGRATE_UP => $class->up(),
            StorageInterface::MIGRATE_DOWN => $class->down(),
            default => throw new StorageException(sprintf('Unknown direction \'%s\' was given.', $dir), 91),
        };
    }

    public function migrateData(string $version, LoggerInterface|null $logger = null): mixed
    {
        return (new PDODataMigration($this->pdo, $logger ?? $this->logger))->automatic();
    }

    public function isMigrated(): bool
    {
        return (new PDOMigrations($this->pdo, $this->logger))->isMigrated();
    }

    public function makeMigration(string $name, array $opts = []): mixed
    {
        return (new PDOMigrations($this->pdo, $this->logger))->make($name);
    }

    public function maintenance(array $opts = []): mixed
    {
        return (new PDOMigrations($this->pdo, $this->logger))->runMaintenance();
    }

    public function setLogger(LoggerInterface $logger): StorageInterface
    {
        $this->logger = $logger;

        return $this;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function singleTransaction(): bool
    {
        $this->singleTransaction = true;

        if (false === $this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }

        return $this->pdo->inTransaction();
    }

    public function transactional(Closure $callback): mixed
    {
        if (true === $this->pdo->inTransaction()) {
            $this->viaTransaction = true;
            $result = $callback($this);
            $this->viaTransaction = false;
            return $result;
        }

        try {
            $this->pdo->beginTransaction();

            $this->viaTransaction = true;
            $result = $callback($this);
            $this->viaTransaction = false;

            $this->pdo->commit();

            return $result;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->viaTransaction = false;
            throw $e;
        }
    }

    /**
     * If we are using single transaction,
     * commit all changes on class destruction.
     */
    public function __destruct()
    {
        if (true === $this->singleTransaction && $this->pdo->inTransaction()) {
            $this->pdo->commit();
        }

        $this->stmt = [];
    }

    /**
     * Generate SQL Insert Statement.
     *
     * @param string $table
     * @param array $columns
     * @return string
     */
    private function pdoInsert(string $table, array $columns): string
    {
        $queryString = "INSERT INTO {$table} (%(columns)) VALUES(%(values))";

        $sql_columns = $sql_placeholder = [];

        foreach ($columns as $column) {
            if (iFace::COLUMN_ID === $column) {
                continue;
            }

            $sql_columns[] = $column;
            $sql_placeholder[] = ':' . $column;
        }

        $queryString = str_replace(
            ['%(columns)', '%(values)'],
            [implode(', ', $sql_columns), implode(', ', $sql_placeholder)],
            $queryString
        );

        return trim($queryString);
    }

    /**
     * Generate SQL Update Statement.
     *
     * @param string $table
     * @param array $columns
     * @return string
     */
    private function pdoUpdate(string $table, array $columns): string
    {
        /** @noinspection SqlWithoutWhere */
        $queryString = "UPDATE {$table} SET %(place) = %(holder) WHERE " . iFace::COLUMN_ID . " = :id";

        $placeholders = [];

        foreach ($columns as $column) {
            if (iFace::COLUMN_ID === $column) {
                continue;
            }
            $placeholders[] = sprintf('%1$s = :%1$s', $column);
        }

        return trim(str_replace('%(place) = %(holder)', implode(', ', $placeholders), $queryString));
    }

    /**
     * Find db entity using External Relative ID.
     * External Relative ID is : (db_name)://(showId)/(season)/(episode)
     *
     * @param iFace $entity
     * @return iFace|null
     */
    private function findByRGuid(iFace $entity): iFace|null
    {
        $where = [];
        $cond = [
            'type' => iFace::TYPE_EPISODE,
            'season' => $entity->season,
            'episode' => $entity->episode,
        ];

        foreach ($entity->parent as $key => $val) {
            if (null === ($val ?? null)) {
                continue;
            }

            $where[] = "JSON_EXTRACT(" . iFace::COLUMN_PARENT . ",'$.{$key}') = :{$key}";
            $cond[$key] = $val;
        }

        $sql = "SELECT
                    *
                FROM
                    state
                WHERE
                (
                    " . iFace::COLUMN_TYPE . "    = :type
                AND
                    " . iFace::COLUMN_SEASON . "  = :season
                AND
                    " . iFace::COLUMN_EPISODE . " = :episode
                )
                AND
                (
                    " . implode(' OR ', $where) . "
                )
                LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);

        if (false === $stmt->execute($cond)) {
            throw new StorageException('Failed to execute db query.', 61);
        }

        if (false === ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            return null;
        }

        return $entity::fromArray($row);
    }

    /**
     * Find db entity using Metadata id.
     *
     * @param iFace $entity
     * @return iFace|null
     */
    private function findByMetaDataId(iFace $entity): iFace|null
    {
        if (empty($entity->via)) {
            return null;
        }

        if (null === ($id = $entity->metadata[$entity->via][iFace::COLUMN_ID] ?? null)) {
            return null;
        }

        $key = sprintf('$.%s.id', $entity->via);

        $cond = [
            'type' => $entity->type,
            'id' => $id,
        ];

        $sql = "SELECT
                    *
                FROM
                    state
                WHERE
                    " . iFace::COLUMN_TYPE . " = :type
                AND
                    JSON_EXTRACT(" . iFace::COLUMN_META_DATA . ", '{$key}') = :id
                LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);

        if (false === $stmt->execute($cond)) {
            throw new StorageException('Failed to execute sql query.', 61);
        }

        if (false === ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            return null;
        }

        return $entity::fromArray($row);
    }

    /**
     * Find db entity using External ID.
     * External ID format is: (db_name)://(id)
     *
     * @param iFace $entity
     * @return iFace|null
     */
    private function findByGuid(iFace $entity): iFace|null
    {
        $guids = [];
        $cond = [
            'type' => $entity->type,
        ];

        foreach (array_keys(Guid::SUPPORTED) as $key) {
            if (null === ($entity->guids[$key] ?? null)) {
                continue;
            }

            $guids[] = "JSON_EXTRACT(" . iFace::COLUMN_GUIDS . ",'$.{$key}') = :{$key}";
            $cond[$key] = $entity->guids[$key];
        }

        if (empty($cond)) {
            return null;
        }

        $sqlEpisode = '';

        if ($entity->isEpisode()) {
            $sqlEpisode = ' AND ' . iFace::COLUMN_SEASON . ' = :season AND ' . iFace::COLUMN_EPISODE . ' = :episode ';
            $cond['season'] = $entity->season;
            $cond['episode'] = $entity->episode;
        }

        $sqlGuids = ' AND (' . implode(' OR ', $guids) . ' ) ';

        $sql = "SELECT * FROM state WHERE ( " . iFace::COLUMN_TYPE . " = :type {$sqlEpisode} ) {$sqlGuids} LIMIT 1";

        $stmt = $this->pdo->prepare($sql);

        if (false === $stmt->execute($cond)) {
            throw new StorageException('Failed to execute sql query.', 61);
        }

        if (false === ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            return null;
        }

        return $entity::fromArray($row);
    }
}
