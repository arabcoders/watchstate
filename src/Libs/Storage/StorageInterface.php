<?php

declare(strict_types=1);

namespace App\Libs\Storage;

use App\Libs\Entity\StateEntity;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

interface StorageInterface
{
    public const MIGRATE_UP = 'up';

    public const MIGRATE_DOWN = 'down';

    /**
     * Initiate Driver.
     *
     * @param array $opts
     *
     * @return $this
     */
    public function setUp(array $opts): self;

    /**
     * Inject Logger.
     *
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): self;

    /**
     * Insert Entity immediately.
     *
     * @param StateEntity $entity
     *
     * @return StateEntity Return given entity with valid $id
     */
    public function insert(StateEntity $entity): StateEntity;

    /**
     * Update Entity immediately.
     *
     * @param StateEntity $entity
     *
     * @return StateEntity Return the given entity.
     */
    public function update(StateEntity $entity): StateEntity;

    /**
     * Get Entity.
     *
     * @param StateEntity $entity
     *
     * @return StateEntity|null
     */
    public function get(StateEntity $entity): StateEntity|null;

    /**
     * Remove Entity.
     *
     * @param StateEntity $entity
     *
     * @return bool
     */
    public function remove(StateEntity $entity): bool;

    /**
     * Insert/Update Entities.
     *
     * @param array<StateEntity> $entities
     *
     * @return array
     */
    public function commit(array $entities): array;

    /**
     * Load All Entities From backend.
     *
     * @param DateTimeInterface|null $date Get Entities That has changed since given time.
     *
     * @return array<StateEntity>
     */
    public function getAll(DateTimeInterface|null $date = null): array;

    /**
     * Migrate Backend Storage Schema.
     *
     * @param string $dir direction {@see MIGRATE_UP}, {@see MIGRATE_DOWN}
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param array $opts
     *
     * @return mixed
     */
    public function migrations(string $dir, InputInterface $input, OutputInterface $output, array $opts = []): mixed;

    /**
     * Run Maintenance on backend storage.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param array $opts
     * @return mixed
     */
    public function maintenance(InputInterface $input, OutputInterface $output, array $opts = []): mixed;

    /**
     * Make Migration.
     *
     * @param string $name
     * @param OutputInterface $output
     * @param array $opts
     */
    public function makeMigration(string $name, OutputInterface $output, array $opts = []): void;
}
