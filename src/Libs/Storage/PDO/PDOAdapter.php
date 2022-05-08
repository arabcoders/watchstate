<?php

declare(strict_types=1);

namespace App\Libs\Storage\PDO;

use App\Libs\Container;
use App\Libs\Entity\StateInterface;
use App\Libs\Guid;
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
    private bool $viaCommit = false;

    private bool $singleTransaction = false;

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

    public function insert(StateInterface $entity): StateInterface
    {
        try {
            $data = $entity->getAll();

            foreach (StateInterface::ENTITY_ARRAY_KEYS as $key) {
                if (null !== ($data[$key] ?? null) && is_array($data[$key])) {
                    ksort($data[$key]);
                    $data[$key] = json_encode($data[$key], flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            if (null !== $data['id']) {
                throw new StorageException(
                    sprintf('Trying to insert already saved entity #%s', $data['id']), 21
                );
            }

            unset($data['id']);

            if (null === ($this->stmt['insert'] ?? null)) {
                $this->stmt['insert'] = $this->pdo->prepare(
                    $this->pdoInsert('state', StateInterface::ENTITY_KEYS)
                );
            }

            $this->stmt['insert']->execute($data);

            $entity->id = (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            $this->stmt['insert'] = null;
            if (false === $this->viaCommit) {
                $this->logger->error($e->getMessage(), $entity->getAll());
                return $entity;
            }
            throw $e;
        }

        return $entity->updateOriginal();
    }

    public function get(StateInterface $entity): StateInterface|null
    {
        if ($entity->isEpisode() && $entity->hasRelativeGuid() && null !== ($item = $this->findByRGuid($entity))) {
            return $item;
        }

        if ($entity->hasGuids() && null !== ($item = $this->findByGuid($entity))) {
            return $item;
        }

        return null;
    }

    public function getAll(DateTimeInterface|null $date = null, StateInterface|null $class = null): array
    {
        $arr = [];

        $sql = 'SELECT * FROM state';

        if (null !== $date) {
            $sql .= ' WHERE updated > ' . $date->getTimestamp();
        }

        if (null === $class) {
            $class = Container::get(StateInterface::class);
        }

        foreach ($this->pdo->query($sql) as $row) {
            $arr[] = $class::fromArray($row);
        }

        return $arr;
    }

    public function update(StateInterface $entity): StateInterface
    {
        try {
            $data = $entity->getAll();

            foreach (StateInterface::ENTITY_ARRAY_KEYS as $key) {
                if (is_array($data[$key] ?? [])) {
                    $data[$key] = json_encode($data[$key], flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            if (null === $data['id']) {
                throw new StorageException('Trying to update unsaved entity', 51);
            }

            if (null === ($this->stmt['update'] ?? null)) {
                $this->stmt['update'] = $this->pdo->prepare(
                    $this->pdoUpdate('state', StateInterface::ENTITY_KEYS)
                );
            }

            $this->stmt['update']->execute($data);
        } catch (PDOException $e) {
            $this->stmt['update'] = null;
            if (false === $this->viaCommit) {
                $this->logger->error($e->getMessage(), $entity->getAll());
                return $entity;
            }
            throw $e;
        }

        return $entity->updateOriginal();
    }

    public function remove(StateInterface $entity): bool
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

            $this->pdo->query('DELETE FROM state WHERE id = ' . (int)$id);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage());
            return false;
        }

        return true;
    }

    public function commit(array $entities): array
    {
        return $this->transactional(function () use ($entities) {
            $list = [
                StateInterface::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
                StateInterface::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
            ];

            $count = count($entities);

            $this->logger->notice(
                0 === $count ? 'No changes detected.' : sprintf('Updating database with \'%d\' changes.', $count)
            );

            $this->viaCommit = true;

            foreach ($entities as $entity) {
                try {
                    if (null === $entity->id) {
                        $this->insert($entity);

                        $list[$entity->type]['added']++;
                    } else {
                        $this->update($entity);
                        $list[$entity->type]['updated']++;
                    }
                } catch (PDOException $e) {
                    $list[$entity->type]['failed']++;
                    $this->logger->error($e->getMessage(), $entity->getAll());
                }
            }

            $this->viaCommit = false;

            return $list;
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

    /**
     * Enable Single Transaction mode.
     *
     * @return bool
     */
    public function singleTransaction(): bool
    {
        $this->singleTransaction = true;
        $this->logger->info('Single transaction mode');

        if (false === $this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }

        return $this->pdo->inTransaction();
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
     * Wrap Transaction.
     *
     * @param Closure(PDO): mixed $callback
     *
     * @return mixed
     * @throws PDOException
     */
    private function transactional(Closure $callback): mixed
    {
        if (true === $this->pdo->inTransaction()) {
            return $callback($this->pdo);
        }

        try {
            $this->pdo->beginTransaction();

            $result = $callback($this->pdo);

            $this->pdo->commit();

            return $result;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
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
            if ('id' === $column) {
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
        $queryString = "UPDATE {$table} SET %(place) = %(holder) WHERE id = :id";

        $placeholders = [];

        foreach ($columns as $column) {
            if ('id' === $column) {
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
     * @param StateInterface $entity
     * @return StateInterface|null
     */
    private function findByRGuid(StateInterface $entity): StateInterface|null
    {
        $cond = $where = [];

        foreach ($entity->parent as $key => $val) {
            if (null === ($val ?? null)) {
                continue;
            }

            $where[] = "JSON_EXTRACT(parent,'$.{$key}') = :{$key}";
            $cond[$key] = $val;
        }

        $sql = "SELECT
                    *
                FROM
                    state
                WHERE
                (
                    type    = :type
                AND
                    season  = :season
                AND
                    episode = :episode
                )
                AND
                (
                    " . implode(' OR ', $where) . "
                )
                LIMIT 1
        ";

        $cond['season'] = $entity->season;
        $cond['episode'] = $entity->episode;
        $cond['type'] = StateInterface::TYPE_EPISODE;

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
     * @param StateInterface $entity
     * @return StateInterface|null
     */
    private function findByGuid(StateInterface $entity): StateInterface|null
    {
        if (null !== $entity->id) {
            $stmt = $this->pdo->query("SELECT * FROM state WHERE id = " . (int)$entity->id);

            if (false === ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
                return null;
            }

            return $entity::fromArray($row);
        }

        $guids = [];
        $cond = [
            'type' => $entity->type,
        ];

        foreach (array_keys(Guid::SUPPORTED) as $key) {
            if (null === ($entity->guids[$key] ?? null)) {
                continue;
            }

            $guids[] = "JSON_EXTRACT(guids,'$.{$key}') = :{$key}";
            $cond[$key] = $entity->guids[$key];
        }

        if (empty($cond)) {
            return null;
        }

        $sqlEpisode = '';

        if ($entity->isEpisode()) {
            $sqlEpisode = ' AND season = :season AND episode = :episode ';
            $cond['season'] = $entity->season;
            $cond['episode'] = $entity->episode;
        }

        $sqlGuids = ' AND (' . implode(' OR ', $guids) . ' ) ';

        $sql = "SELECT * FROM state WHERE ( type = :type {$sqlEpisode} ) {$sqlGuids} LIMIT 1";

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
