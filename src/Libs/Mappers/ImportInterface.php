<?php

declare(strict_types=1);

namespace App\Libs\Mappers;

use App\Libs\Entity\StateInterface;
use App\Libs\Storage\StorageInterface;
use Countable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;

interface ImportInterface extends Countable
{
    /**
     * Initiate Mapper.
     *
     * @param array $options
     *
     * @return self
     */
    public function setOptions(array $options = []): self;

    /**
     * Preload data from storage as usable entity.
     *
     * @param DateTimeInterface|null $date
     *
     * @return self
     */
    public function loadData(DateTimeInterface|null $date = null): self;

    /**
     * Add Entity. it has to search for
     * existing entity, and if found update it.
     *
     * @param StateInterface $entity Refers to the item state from backend.
     * @param array $opts options.
     *
     * @return self
     */
    public function add(StateInterface $entity, array $opts = []): self;

    /**
     * Get Entity.
     *
     * @param StateInterface $entity
     *
     * @return null|StateInterface
     */
    public function get(StateInterface $entity): null|StateInterface;

    /**
     * Remove Entity.
     *
     * @param StateInterface $entity
     *
     * @return bool
     */
    public function remove(StateInterface $entity): bool;

    /**
     * Commit Entities to storage backend.
     *
     * @return mixed
     */
    public function commit(): mixed;

    /**
     * Has Entity.
     *
     * @param StateInterface $entity
     *
     * @return bool
     */
    public function has(StateInterface $entity): bool;

    /**
     * Reset Mapper State.
     *
     * @return ImportInterface
     */
    public function reset(): ImportInterface;

    /**
     * Get loaded objects.
     *
     * @param array $opts
     *
     * @return array<StateInterface>
     */
    public function getObjects(array $opts = []): array;

    /**
     * Get loaded objects count.
     *
     * @return int
     */
    public function getObjectsCount(): int;

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
     * Are we in dry run mode?
     *
     * @return bool
     */
    public function inDryRunMode(): bool;

    /**
     * Are we in deep debug mode?
     * @return bool
     */
    public function inTraceMode(): bool;
}
