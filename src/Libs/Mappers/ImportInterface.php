<?php

declare(strict_types=1);

namespace App\Libs\Mappers;

use App\Libs\Entity\StateEntity;
use App\Libs\Storage\StorageInterface;
use Countable;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

interface ImportInterface extends Countable
{
    /**
     * Initiate Mapper.
     *
     * @param array $opts
     *
     * @return self
     */
    public function setUp(array $opts): self;

    /**
     * Inject Logger.
     *
     * @param LoggerInterface $logger
     *
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self;

    /**
     * Inject Storage.
     *
     * @param StorageInterface $storage
     *
     * @return self
     */
    public function SetStorage(StorageInterface $storage): self;

    /**
     * Commit Entities to storage backend.
     *
     * @return mixed
     */
    public function commit(): mixed;

    /**
     * Do Data retrieval if necessary.
     *
     * This method get called only once. on import. and once for every export.
     *
     * @param DateTimeImmutable|null $date
     *
     * @return self
     */
    public function loadData(DateTimeImmutable|null $date = null): self;

    /**
     * Add Entity. it has to search for
     * existing entity if found and update it.
     *
     * @param string $bucket bucket name.
     * @param string $name Item name.
     * @param StateEntity $entity
     * @param array $opts
     *
     * @return self
     */
    public function add(string $bucket, string $name, StateEntity $entity, array $opts = []): self;

    /**
     * Get Entity.
     *
     * @param StateEntity $entity
     *
     * @return null|StateEntity
     */
    public function get(StateEntity $entity): null|StateEntity;

    /**
     * Has Entity.
     *
     * @param StateEntity $entity
     *
     * @return bool
     */
    public function has(StateEntity $entity): bool;

    /**
     * Remove Entity.
     *
     * @param StateEntity $entity
     *
     * @return bool
     */
    public function remove(StateEntity $entity): bool;
}
