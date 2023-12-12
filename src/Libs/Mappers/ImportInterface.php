<?php

declare(strict_types=1);

namespace App\Libs\Mappers;

use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface;
use Countable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;

interface ImportInterface extends Countable
{
    /**
     * Initiate mapper.
     *
     * @param array $options
     *
     * @return self
     */
    public function setOptions(array $options = []): self;

    /**
     * Preload data from db.
     *
     * @param DateTimeInterface|null $date
     *
     * @return self
     */
    public function loadData(DateTimeInterface|null $date = null): self;

    /**
     * Add entity. it has to search for
     * existing entity, and if found update it.
     *
     * @param StateInterface $entity Refers to the item state from backend.
     * @param array $opts options.
     *
     * @return self
     */
    public function add(StateInterface $entity, array $opts = []): self;

    /**
     * Get entity.
     *
     * @param StateInterface $entity
     *
     * @return null|StateInterface
     */
    public function get(StateInterface $entity): null|StateInterface;

    /**
     * Remove entity.
     *
     * @param StateInterface $entity
     *
     * @return bool
     */
    public function remove(StateInterface $entity): bool;

    /**
     * Commit changed items to db.
     *
     * @return mixed
     */
    public function commit(): mixed;

    /**
     * Has entity.
     *
     * @param StateInterface $entity
     *
     * @return bool
     */
    public function has(StateInterface $entity): bool;

    /**
     * Reset mapper state.
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
     * Inject logger.
     *
     * @param LoggerInterface $logger
     *
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self;

    /**
     * Inject db handler.
     *
     * @param iDB $db
     *
     * @return self
     */
    public function setDatabase(iDB $db): self;

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

    /**
     * Get list of registered pointers.
     *
     * @return array
     */
    public function getPointersList(): array;

    /**
     * Get list of changed items that changed.
     *
     * @return array
     */
    public function getChangedList(): array;
}
