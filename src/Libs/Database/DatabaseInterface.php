<?php

declare(strict_types=1);

namespace App\Libs\Database;

use App\Libs\Entity\StateInterface;
use Closure;
use DateTimeInterface;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

interface DatabaseInterface
{
    public const string MIGRATE_UP = 'up';

    public const string MIGRATE_DOWN = 'down';

    /**
     * Set options
     *
     * @param array $options PDO options
     *
     * @return self
     */
    public function setOptions(array $options): self;

    /**
     * Insert entity immediately.
     *
     * @param StateInterface $entity Entity to insert
     *
     * @return StateInterface Return given entity with valid primary key.
     */
    public function insert(StateInterface $entity): StateInterface;

    /**
     * Get entity.
     *
     * @param StateInterface $entity Item encoded in entity class to get.
     *
     * @return StateInterface|null Return null if not found.
     */
    public function get(StateInterface $entity): StateInterface|null;

    /**
     * Load entities from database.
     *
     * @param DateTimeInterface|null $date Get Entities That has changed since given time, if null get all.
     * @param array $opts (Optional) options.
     *
     * @return array<StateInterface>
     */
    public function getAll(DateTimeInterface|null $date = null, array $opts = []): array;

    /**
     * Return number of items.
     *
     * @param DateTimeInterface|null $date if provided, it will return items changes since this date.
     *
     * @return int Number of items.
     */
    public function getCount(DateTimeInterface|null $date = null): int;

    /**
     * Return database records for given items.
     *
     * @param array<StateInterface> $items Items to find.
     *
     * @return array<StateInterface>
     */
    public function find(StateInterface ...$items): array;

    /**
     * Find database item using backend name and id.
     *
     * @param string $backend Backend name.
     * @param int|string $id Backend id.
     * @param string|null $type (Optional) Type of item will speed up lookups.
     *
     * @return StateInterface|null Return null if not found.
     */
    public function findByBackendId(string $backend, int|string $id, string|null $type = null): StateInterface|null;

    /**
     * Update entity immediately.
     *
     * @param StateInterface $entity Entity to update.
     *
     * @return StateInterface Return the updated entity.
     */
    public function update(StateInterface $entity): StateInterface;

    /**
     * Remove entity.
     *
     * @param StateInterface $entity Entity to remove.
     *
     * @return bool Return true if removed, false if not found.
     */
    public function remove(StateInterface $entity): bool;

    /**
     * Insert or update entities.
     *
     * @param array<StateInterface> $entities Entities to commit.
     * @param array $opts (Optional) options.
     *
     * @return array{added: int, updated: int, failed: int} Return array with count for each operation.
     */
    public function commit(array $entities, array $opts = []): array;

    /**
     * Run database migrations.
     *
     * @param string $dir Migration direction (up|down).
     * @param array $opts (Optional) options.
     *
     * @return mixed Return value depends on the driver.
     */
    public function migrations(string $dir, array $opts = []): mixed;

    /**
     * Ensure database has correct indexes.
     *
     * @param array $opts (Optional) options.
     *
     * @return mixed Return value depends on the driver.
     */
    public function ensureIndex(array $opts = []): mixed;

    /**
     * Migrate data from old database schema to new one.
     *
     * @param string $version Version to migrate to.
     * @param LoggerInterface|null $logger Logger to use.
     *
     * @return mixed Return value depends on the driver.
     */
    public function migrateData(string $version, LoggerInterface|null $logger = null): mixed;

    /**
     * Is the database up to date with migrations?
     *
     * @return bool
     */
    public function isMigrated(): bool;

    /**
     * Run maintenance tasks on database.
     *
     * @param array $opts (Optional) options.
     *
     * @return mixed Return value depends on the driver.
     */
    public function maintenance(array $opts = []): mixed;

    /**
     * Make migration.
     *
     * @param string $name
     * @param array $opts
     *
     * @return mixed can return migration filename in supported cases.
     */
    public function makeMigration(string $name, array $opts = []): mixed;

    /**
     * Reset database to initial state.
     *
     * @return bool
     */
    public function reset(): bool;

    /**
     * Inject Logger.
     *
     * @param LoggerInterface $logger
     *
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): self;

    /**
     * Get PDO instance.
     *
     * @return PDO
     *
     * @throws RuntimeException if PDO is not initialized yet.
     */
    public function getPDO(): PDO;

    /**
     * Enable single transaction mode.
     *
     * @return bool
     */
    public function singleTransaction(): bool;

    /**
     * Wrap queries into single transaction.
     *
     * @param Closure(DatabaseInterface): mixed $callback
     *
     * @return mixed Return value from callback.
     *
     * @throws PDOException if transaction fails.
     */
    public function transactional(Closure $callback): mixed;
}
