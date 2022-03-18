<?php

declare(strict_types=1);

namespace App\Libs\Storage\PDO;

use App\Libs\Container;
use App\Libs\Entity\StateInterface;
use App\Libs\Storage\StorageException;
use App\Libs\Storage\StorageInterface;
use Closure;
use DateTimeInterface;
use Exception;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;

final class PDOAdapter implements StorageInterface
{
    private array $supported = [
        'sqlite',
        'mysql',
        'pgsql'
    ];

    private PDO|null $pdo = null;
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

    public function __construct(private LoggerInterface $logger)
    {
    }

    public function setUp(array $opts): StorageInterface
    {
        if (null === ($opts['dsn'] ?? null)) {
            throw new StorageException('No storage.opts.dsn (Data Source Name) was provided.', 10);
        }

        try {
            $this->pdo = new PDO(
                $opts['dsn'], $opts['username'] ?? null, $opts['password'] ?? null,
                array_replace_recursive(
                    [
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_STRINGIFY_FETCHES => false,
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ],
                    $opts['options'] ?? []
                )
            );
        } catch (PDOException $e) {
            throw new \PDOException(sprintf('Unable to connect to storage backend. \'%s\'.', $e->getMessage()));
        }

        $driver = $this->getDriver();

        if (!in_array($driver, $this->supported)) {
            throw new StorageException(sprintf('%s Driver is not supported.', $driver), 11);
        }

        if (null !== ($exec = ag($opts, "exec.{$driver}")) && is_array($exec)) {
            foreach ($exec as $cmd) {
                $this->pdo->exec($cmd);
            }
        }

        if (true === ($opts['singleTransaction'] ?? false)) {
            $this->singleTransaction();
        }

        return $this;
    }

    public function insert(StateInterface $entity): StateInterface
    {
        if (null === $this->pdo) {
            throw new StorageException(
                afterLast(__CLASS__, '\\') . '->setUp(): method was not called.',
                StorageException::SETUP_NOT_CALLED
            );
        }

        try {
            $data = $entity->getAll();

            if (is_array($data['meta'])) {
                $data['meta'] = json_encode($data['meta']);
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
                $this->logger->error($e->getMessage(), $entity->meta ?? []);
                return $entity;
            }
            throw $e;
        }

        return $entity->updateOriginal();
    }

    public function get(StateInterface $entity): StateInterface|null
    {
        $arr = array_intersect_key(
            $entity->getAll(),
            array_flip(StateInterface::ENTITY_GUIDS)
        );

        if (null !== $entity->id) {
            $arr['id'] = $entity->id;
        }

        if (empty($arr)) {
            return null;
        }

        return $this->matchAnyId($arr, $entity);
    }

    public function getAll(DateTimeInterface|null $date = null, StateInterface|null $class = null): array
    {
        if (null === $this->pdo) {
            throw new StorageException(
                afterLast(__CLASS__, '\\') . '->setUp(): method was not called.',
                StorageException::SETUP_NOT_CALLED
            );
        }

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
        if (null === $this->pdo) {
            throw new StorageException(
                afterLast(__CLASS__, '\\') . '->setUp(): method was not called.',
                StorageException::SETUP_NOT_CALLED
            );
        }

        try {
            $data = $entity->getAll();

            if (is_array($data['meta'])) {
                $data['meta'] = json_encode($data['meta']);
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
                $this->logger->error($e->getMessage(), $entity->meta ?? []);
                return $entity;
            }
            throw $e;
        }

        return $entity->updateOriginal();
    }

    public function matchAnyId(array $ids, StateInterface|null $class = null): StateInterface|null
    {
        if (null === $this->pdo) {
            throw new StorageException(
                afterLast(__CLASS__, '\\') . '->setUp(): method was not called.',
                StorageException::SETUP_NOT_CALLED
            );
        }

        if (null === $class) {
            $class = Container::get(StateInterface::class);
        }

        if (null !== ($ids['id'] ?? null)) {
            $stmt = $this->pdo->query("SELECT * FROM state WHERE id = " . (int)$ids['id']);

            if (false === ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
                return null;
            }

            return $class::fromArray($row);
        }

        $cond = $where = [];
        foreach (StateInterface::ENTITY_GUIDS as $key) {
            if (null === ($ids[$key] ?? null)) {
                continue;
            }
            $cond[$key] = $ids[$key];
        }

        if (empty($cond)) {
            return null;
        }

        foreach ($cond as $key => $_) {
            $where[] = $key . ' = :' . $key;
        }

        $sqlWhere = implode(' OR ', $where);

        $cachedKey = md5($sqlWhere);

        try {
            if (null === ($this->stmt[$cachedKey] ?? null)) {
                $this->stmt[$cachedKey] = $this->pdo->prepare("SELECT * FROM state WHERE {$sqlWhere}");
            }

            if (false === $this->stmt[$cachedKey]->execute($cond)) {
                $this->stmt[$cachedKey] = null;
                throw new StorageException('Failed to execute sql query.', 61);
            }

            if (false === ($row = $this->stmt[$cachedKey]->fetch(PDO::FETCH_ASSOC))) {
                return null;
            }

            return $class::fromArray($row);
        } catch (PDOException|StorageException $e) {
            $this->stmt[$cachedKey] = null;
            throw $e;
        }
    }

    public function remove(StateInterface $entity): bool
    {
        if (null === $this->pdo) {
            throw new StorageException(
                afterLast(__CLASS__, '\\') . '->setUp(): method was not called.',
                StorageException::SETUP_NOT_CALLED
            );
        }

        if (null === $entity->id && !$entity->hasGuids()) {
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
        if (null === $this->pdo) {
            throw new StorageException(
                afterLast(__CLASS__, '\\') . '->setUp(): method was not called.',
                StorageException::SETUP_NOT_CALLED
            );
        }

        return $this->transactional(function () use ($entities) {
            $list = [
                StateInterface::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
                StateInterface::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
            ];

            $count = count($entities);

            $this->logger->info(
                0 === $count ? 'No changes detected.' : sprintf('Updating database with \'%d\' changes.', $count)
            );

            $this->viaCommit = true;

            foreach ($entities as $entity) {
                try {
                    if (null === $entity->id) {
                        $this->logger->debug('Adding ' . $entity->type, $entity->meta ?? []);

                        $this->insert($entity);

                        $list[$entity->type]['added']++;
                    } else {
                        $this->logger->debug(
                            'Updating ' . $entity->type,
                            ['id' => $entity->id] + ($entity->diff() ?? [])
                        );
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
        if (null === $this->pdo) {
            throw new StorageException(
                afterLast(__CLASS__, '\\') . '->setUp(): method was not called.',
                StorageException::SETUP_NOT_CALLED
            );
        }

        $class = new PDOMigrations($this->pdo, $this->logger);

        return match (strtolower($dir)) {
            StorageInterface::MIGRATE_UP => $class->up(),
            StorageInterface::MIGRATE_DOWN => $class->down(),
            default => throw new StorageException(sprintf('Unknown direction \'%s\' was given.', $dir), 91),
        };
    }

    public function isMigrated(): bool
    {
        if (null === $this->pdo) {
            throw new StorageException(
                afterLast(__CLASS__, '\\') . '->setUp(): method was not called.',
                StorageException::SETUP_NOT_CALLED
            );
        }

        return (new PDOMigrations($this->pdo, $this->logger))->isMigrated();
    }

    /**
     * @throws Exception
     */
    public function makeMigration(string $name, array $opts = []): mixed
    {
        if (null === $this->pdo) {
            throw new StorageException(
                afterLast(__CLASS__, '\\') . '->setUp(): method was not called.',
                StorageException::SETUP_NOT_CALLED
            );
        }

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

    /**
     * Enable Single Transaction mode.
     *
     * @return bool
     */
    public function singleTransaction(): bool
    {
        $this->singleTransaction = true;
        $this->logger->notice('Single transaction mode');

        if (false === $this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }

        return $this->pdo->inTransaction();
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
     * Get PDO Driver.
     *
     * @return string
     */
    private function getDriver(): string
    {
        $driver = $this->pdo->getAttribute($this->pdo::ATTR_DRIVER_NAME);

        if (empty($driver) || !is_string($driver)) {
            $driver = 'unknown';
        }

        return strtolower($driver);
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

    public function __destruct()
    {
        if (true === $this->singleTransaction && $this->pdo->inTransaction()) {
            $this->pdo->commit();
        }

        $this->stmt = [];
    }
}
