<?php

declare(strict_types=1);

namespace App\Libs\Storage;

use App\Libs\Entity\StateInterface;
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
     *
     * @return mixed can return migration file name in supported cases.
     */
    public function makeMigration(string $name, OutputInterface $output, array $opts = []): mixed;

    /**
     * Inject Logger.
     *
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): self;
}
