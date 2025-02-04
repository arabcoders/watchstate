<?php

declare(strict_types=1);

namespace App\Libs\Mappers;

use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use Countable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;

interface ImportInterface extends Countable
{
    /**
     * Get current options for the object.
     * @return array
     */
    public function getOptions(): array;

    /**
     * Set options for the current object.
     *
     * @param array $options An associative array of options to be set
     *
     * @return self
     */
    public function setOptions(array $options = []): self;

    /**
     * Set options and return a new instance of the object.
     *
     * @param array $options An associative array of options to be set.
     *
     * @return self
     */
    public function withOptions(array $options = []): self;

    /**
     * Preload data from database into the mapper.
     *
     * @param DateTimeInterface|null $date If null, then load all data. Otherwise, load data since the given date.
     *
     * @return self
     */
    public function loadData(DateTimeInterface|null $date = null): self;

    /**
     * Add entity data to the mapper. if the entity already exists, then it will be updated.
     * if the entity does not exist, then it will be added.
     *
     * @param iState $entity Refers to the item state from backend.
     * @param array $opts (Optional) options.
     *
     * @return self
     */
    public function add(iState $entity, array $opts = []): self;

    /**
     * Get the corresponding item from mapper that map to the given entity.
     *
     * @param iState $entity Refers to the item state from backend.
     *
     * @return null|iState Returns the entity from mapper if exists, null otherwise.
     */
    public function get(iState $entity): null|iState;

    /**
     * Remove an entity from the mapper.
     *
     * @param iState $entity The entity to be removed.
     *
     * @return bool Returns true if the entity was successfully removed, false otherwise.
     */
    public function remove(iState $entity): bool;

    /**
     * Commit changes to the database.
     * This method commits the changes made to the database. The changes can include inserts, updates, and deletes.
     *
     * @return mixed The result of the commit operation.
     */
    public function commit(): mixed;

    /**
     * Check if mapper has an entity that corresponds to the given entity.
     *
     * @param iState $entity The entity to be checked.
     *
     * @return bool Returns true if the entity exists, false otherwise.
     */
    public function has(iState $entity): bool;

    /**
     * Reset mapper data. It should ONLY reset the mapper data, not the database.
     *
     * @return ImportInterface
     */
    public function reset(): ImportInterface;

    /**
     * Get list of loaded objects.
     *
     * @param array $opts (Optional) options.
     *
     * @return array<iState> Returns the loaded objects.
     */
    public function getObjects(array $opts = []): array;

    /**
     * Get the count of loaded objects.
     *
     * @return int The count of objects.
     */
    public function getObjectsCount(): int;

    /**
     * Set the logger instance.
     *
     * @param LoggerInterface $logger The logger instance to be set.
     *
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self;

    /**
     * Get the logger instance.
     *
     * @return LoggerInterface The logger instance.
     */
    public function getLogger(): LoggerInterface;

    /**
     * Set the database object for this class.
     *
     * @param iDB $db The database object to be set.
     *
     * @return self
     */
    public function setDatabase(iDB $db): self;

    /**
     * Check if the system is currently running in dry run mode.
     *
     * @return bool True if the system is in dry run mode, false otherwise.
     */
    public function inDryRunMode(): bool;

    /**
     * Check if the application is running in trace mode.
     *
     * @return bool Returns true if the application is running in trace mode, false otherwise.
     */
    public function inTraceMode(): bool;

    /**
     * Get list of pointers.
     *
     * This method returns an array containing a list of pointers pointing to different items.
     * The pointers can be used to access and manipulate the items.
     *
     * @return array The list of pointers.
     */
    public function getPointersList(): array;

    /**
     * Retrieves the list of changed items.
     *
     * @return array<int,int> The array containing the list of changed items.
     */
    public function getChangedList(): array;
}
