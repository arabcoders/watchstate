<?php

declare(strict_types=1);

namespace App\Libs\Storage\PDO;

use App\Libs\Entity\StateEntity;
use App\Libs\Storage\StorageInterface;
use Closure;
use DateTimeInterface;
use Exception;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PDOAdapter implements StorageInterface
{
    private array $supported = [
        'sqlite',
        // @TODO For v1.x support mysql/pgsql
        //'mysql',
        //'pgsql'
    ];

    private PDO|null $pdo = null;
    private string|null $driver = null;
    private bool $viaCommit = false;

    private PDOStatement|null $stmtInsert = null;
    private PDOStatement|null $stmtUpdate = null;
    private PDOStatement|null $stmtDelete = null;

    public function __construct(private LoggerInterface $logger)
    {
    }

    public function getAll(DateTimeInterface|null $date = null): array
    {
        $arr = [];

        $sql = sprintf("SELECT * FROM %s", $this->escapeIdentifier('state'));

        if (null !== $date) {
            $sql .= sprintf(' WHERE %s > %d', $this->escapeIdentifier('updated'), $date->getTimestamp());
        }

        $stmt = $this->pdo->query($sql);

        foreach ($stmt as $row) {
            $arr[] = new StateEntity($row);
        }

        return $arr;
    }

    public function setUp(array $opts): StorageInterface
    {
        if (null === ($opts['dsn'] ?? null)) {
            throw new RuntimeException('No storage.opts.dsn (Data Source Name) was provided.');
        }

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

        $this->driver = $this->getDriver();

        if (!in_array($this->driver, $this->supported)) {
            throw new RuntimeException(
                sprintf(
                    '\'%s\' Backend engine is not supported right now. only \'%s\' are supported.',
                    $this->driver,
                    implode(', ', $this->supported)
                )
            );
        }

        if (null !== ($exec = ag($opts, "exec.{$this->driver}")) && is_array($exec)) {
            foreach ($exec as $cmd) {
                $this->pdo->exec($cmd);
            }
        }

        return $this;
    }

    public function setLogger(LoggerInterface $logger): StorageInterface
    {
        $this->logger = $logger;

        return $this;
    }

    public function insert(StateEntity $entity): StateEntity
    {
        if (null === $this->pdo) {
            throw new RuntimeException('Setup(): method was not called.');
        }

        try {
            $data = $entity->getAll();

            if (is_array($data['meta'])) {
                $data['meta'] = json_encode($data['meta']);
            }

            if (null !== $data['id']) {
                throw new RuntimeException(
                    sprintf('Trying to insert already saved entity #%s', $data['id'])
                );
            }

            unset($data['id']);

            if (null === $this->stmtInsert) {
                $this->stmtInsert = $this->pdo->prepare(
                    $this->pdoInsert('state', array_keys($data))
                );
            }

            $this->stmtInsert->execute($data);

            $entity->id = (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            $this->stmtInsert = null;
            if (false === $this->viaCommit) {
                $this->logger->error($e->getMessage(), $entity->meta ?? []);
                return $entity;
            }
            throw $e;
        }

        return $entity;
    }

    public function update(StateEntity $entity): StateEntity
    {
        if (null === $this->pdo) {
            throw new RuntimeException('Setup(): method was not called.');
        }

        try {
            $data = $entity->getAll();

            if (is_array($data['meta'])) {
                $data['meta'] = json_encode($data['meta']);
            }

            if (null === $data['id']) {
                throw new RuntimeException('Trying to update unsaved entity');
            }

            if (null === $this->stmtUpdate) {
                $this->stmtUpdate = $this->pdo->prepare($this->pdoUpdate('state', array_keys($data)));
            }

            $this->stmtUpdate->execute($data);
        } catch (PDOException $e) {
            $this->stmtUpdate = null;
            if (false === $this->viaCommit) {
                $this->logger->error($e->getMessage(), $entity->meta ?? []);
                return $entity;
            }
            throw $e;
        }

        return $entity;
    }

    public function get(StateEntity $entity): StateEntity|null
    {
        if (null === $this->pdo) {
            throw new RuntimeException('Setup(): method was not called.');
        }

        if (null !== $entity->id) {
            $stmt = $this->pdo->prepare(
                sprintf(
                    'SELECT * FROM %s WHERE %s = :id LIMIT 1',
                    $this->escapeIdentifier('state'),
                    $this->escapeIdentifier('id'),
                )
            );

            if (false === ($stmt->execute(['id' => $entity->id]))) {
                return null;
            }

            if (false === ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
                return null;
            }

            return new StateEntity($row);
        }

        $cond = $where = [];
        foreach ($entity::getEntityKeys() as $key) {
            if (null === $entity->{$key} || !str_starts_with($key, 'guid_')) {
                continue;
            }
            $cond[$key] = $entity->{$key};
        }

        if (empty($cond)) {
            return null;
        }

        foreach ($cond as $key => $_) {
            $where[] = $this->escapeIdentifier($key) . ' = :' . $key;
        }

        $sqlWhere = implode(' OR ', $where);

        $stmt = $this->pdo->prepare(
            sprintf(
                "SELECT * FROM %s WHERE %s LIMIT 1",
                $this->escapeIdentifier('state'),
                $sqlWhere
            )
        );

        if (false === $stmt->execute($cond)) {
            throw new RuntimeException('Unable to prepare sql statement');
        }

        if (false === ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            return null;
        }

        return new StateEntity($row);
    }

    public function remove(StateEntity $entity): bool
    {
        if (null === $entity->id && !$entity->hasGuids()) {
            return false;
        }

        try {
            if (null === $entity->id) {
                if (null === $dbEntity = $this->get($entity)) {
                    return false;
                }
                $id = $dbEntity->id;
            } else {
                $id = $entity->id;
            }

            if (null === $this->stmtDelete) {
                $this->stmtDelete = $this->pdo->prepare(
                    sprintf(
                        'DELETE FROM %s WHERE %s = :id',
                        $this->escapeIdentifier('state'),
                        $this->escapeIdentifier('id'),
                    )
                );
            }

            $this->stmtDelete->execute(['id' => $id]);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage());
            $this->stmtDelete = null;
            return false;
        }

        return true;
    }

    public function commit(array $entities): array
    {
        if (null === $this->pdo) {
            throw new RuntimeException('Setup(): method was not called.');
        }

        return $this->transactional(function () use ($entities) {
            $list = [
                StateEntity::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
                StateEntity::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
            ];

            $count = count($entities);

            $this->logger->info(
                0 === $count ? 'No changes detected.' : sprintf('Updating database with \'%d\' changes.', $count)
            );

            $this->viaCommit = true;

            foreach ($entities as $entity) {
                try {
                    if (null === $entity->id) {
                        $this->logger->debug('Inserting ' . $entity->type, $entity->meta ?? []);

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
        $autoStartTransaction = false === $this->pdo->inTransaction();

        try {
            if (!$autoStartTransaction) {
                $this->pdo->beginTransaction();
            }

            $result = $callback($this->pdo);

            if (!$autoStartTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (PDOException $e) {
            if (!$autoStartTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
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
        $queryString = 'INSERT INTO ' . $this->escapeIdentifier($table) . ' (%{columns}) VALUES(%{values})';

        $sql_columns = $sql_placeholder = [];

        foreach ($columns as $column) {
            if ('id' === $column) {
                continue;
            }

            $sql_columns[] = $this->escapeIdentifier($column, true);
            $sql_placeholder[] = ':' . $this->escapeIdentifier($column, false);
        }

        $queryString = str_replace(
            ['%{columns}', '%{values}'],
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
        $queryString = sprintf(
            'UPDATE %s SET ${place} = ${holder} WHERE %s = :id',
            $this->escapeIdentifier($table, true),
            $this->escapeIdentifier('id', true)
        );

        $placeholders = [];

        foreach ($columns as $column) {
            if ('id' === $column) {
                continue;
            }
            $placeholders[] = sprintf(
                '%1$s = :%2$s',
                $this->escapeIdentifier($column, true),
                $this->escapeIdentifier($column, false)
            );
        }

        return trim(str_replace('${place} = ${holder}', implode(', ', $placeholders), $queryString));
    }

    private function escapeIdentifier(string $text, bool $quote = true): string
    {
        // table or column has to be valid ASCII name.
        // this is opinionated, but we only allow [a-zA-Z0-9_] in column/table name.
        if (!preg_match('#\w#', $text)) {
            throw new RuntimeException(
                sprintf(
                    'Invalid identifier "%s": Column/table must be valid ASCII code.',
                    $text
                )
            );
        }

        // The first character cannot be [0-9]:
        if (preg_match('/^\d/', $text)) {
            throw new RuntimeException(
                sprintf(
                    'Invalid identifier "%s": Must begin with a letter or underscore.',
                    $text
                )
            );
        }

        if (!$quote) {
            return $text;
        }

        return match ($this->driver) {
            'mssql' => '[' . $text . ']',
            'mysql' => '`' . $text . '`',
            default => '"' . $text . '"',
        };
    }

    public function __destruct()
    {
        $this->stmtDelete = $this->stmtUpdate = $this->stmtInsert = null;
    }

    public function migrations(string $dir, InputInterface $input, OutputInterface $output, array $opts = []): mixed
    {
        if (null === $this->pdo) {
            throw new RuntimeException('Setup(): method was not called.');
        }

        $class = new PDOMigrations($this->pdo);

        return match ($dir) {
            StorageInterface::MIGRATE_UP => $class->up($input, $output),
            StorageInterface::MIGRATE_DOWN => $class->down($output),
            default => throw new RuntimeException(sprintf('Unknown direction \'%s\' was given.', $dir)),
        };
    }

    /**
     * @throws Exception
     */
    public function makeMigration(string $name, OutputInterface $output, array $opts = []): void
    {
        if (null === $this->pdo) {
            throw new RuntimeException('Setup(): method was not called.');
        }

        (new PDOMigrations($this->pdo))->make($name, $output);
    }

    public function maintenance(InputInterface $input, OutputInterface $output, array $opts = []): mixed
    {
        return (new PDOMigrations($this->pdo))->runMaintenance();
    }

    private function getDriver(): string
    {
        $driver = $this->pdo->getAttribute($this->pdo::ATTR_DRIVER_NAME);

        if (empty($driver) || !is_string($driver)) {
            $driver = 'unknown';
        }

        return strtolower($driver);
    }
}
