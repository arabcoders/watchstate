<?php

declare(strict_types=1);

namespace App\Libs\Storage;

use App\Libs\Entity\StateInterface;
use DateTimeInterface;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;

interface StorageInterface
{
    public const MIGRATE_UP = 'up';

    public const MIGRATE_DOWN = 'down';

    /**
     * Insert Entity immediately.
     *
     * @param StateInterface $entity
     *
     * @return StateInterface Return given entity with valid $id
     */
    public function insert(StateInterface $entity): StateInterface;

    /**
     * Get Entity.
     *
     * @param StateInterface $entity
     *
     * @return StateInterface|null
     */
    public function get(StateInterface $entity): StateInterface|null;

    /**
     * Load entities from backend.
     *
     * @param DateTimeInterface|null $date Get Entities That has changed since given time, if null get all.
     * @param StateInterface|null $class Create objects based on given class, if null use default class.
     *
     * @return array<StateInterface>
     */
    public function getAll(DateTimeInterface|null $date = null, StateInterface|null $class = null): array;

    /**
     * Update Entity immediately.
     *
     * @param StateInterface $entity
     *
     * @return StateInterface Return the given entity.
     */
    public function update(StateInterface $entity): StateInterface;

    /**
     * Match Relative Guid.
     *
     * @param StateInterface $entity
     * @return StateInterface|null
     */
    public function matchRelativeGuid(StateInterface $entity): StateInterface|null;

    /**
     * Get Entity Using array of ids.
     *
     * @param array $ids
     * @param StateInterface|null $class Create object based on given class, if null use default class.
     *
     * @return StateInterface|null
     */
    public function matchAnyId(array $ids, StateInterface|null $class = null): StateInterface|null;

    /**
     * Remove Entity.
     *
     * @param StateInterface $entity
     *
     * @return bool
     */
    public function remove(StateInterface $entity): bool;

    /**
     * Insert/Update Entities.
     *
     * @param array<StateInterface> $entities
     *
     * @return array
     */
    public function commit(array $entities): array;

    /**
     * Migrate Backend Storage Schema.
     *
     * @param string $dir direction {@see MIGRATE_UP}, {@see MIGRATE_DOWN}
     * @param array $opts
     *
     * @return mixed
     */
    public function migrations(string $dir, array $opts = []): mixed;

    /**
     * Does the backend storage need to run migrations?
     *
     * @return bool
     */
    public function isMigrated(): bool;

    /**
     * Run Maintenance on backend storage.
     *
     * @param array $opts
     * @return mixed
     */
    public function maintenance(array $opts = []): mixed;

    /**
     * Make Migration.
     *
     * @param string $name
     * @param array $opts
     *
     * @return mixed can return migration file name in supported cases.
     */
    public function makeMigration(string $name, array $opts = []): mixed;

    /**
     * Inject Logger.
     *
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): self;

    /**
     * Get Underlying PDO Instance.
     *
     * @return PDO
     * @throws RuntimeException if PDO is not initialized yet.
     */
    public function getPdo(): PDO;
}
