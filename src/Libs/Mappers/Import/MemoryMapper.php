<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Data;
use App\Libs\Entity\StateEntity;
use App\Libs\Guid;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Servers\ServerInterface;
use App\Libs\Storage\StorageInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;

final class MemoryMapper implements ImportInterface
{
    /**
     * Load all entities.
     *
     * @var array<int,StateEntity>
     */
    private array $objects = [];

    /**
     * Map GUIDs to entities.
     *
     * @var array<string,int>
     */
    private array $guids = [];

    /**
     * Map Deleted GUIDs.
     *
     * @var array<int,int>
     */
    private array $removed = [];

    /**
     * List Changed Entities.
     *
     * @var array<int,int>
     */
    private array $changed = [];

    /**
     * @var bool Has the data been loaded from store?
     */
    private bool $loaded = false;

    /**
     * @var bool Lazy load data from storage. Otherwise, load all.
     */
    private bool $lazyLoad = false;

    public function __construct(private LoggerInterface $logger, private StorageInterface $storage)
    {
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        $this->storage->setLogger($logger);
        return $this;
    }

    public function setStorage(StorageInterface $storage): self
    {
        $this->storage = $storage;
        return $this;
    }

    public function setUp(array $opts): ImportInterface
    {
        $this->lazyLoad = true === (bool)($opts['lazyload'] ?? false);
        return $this;
    }

    public function commit(): mixed
    {
        $state = $this->storage->commit($this->getChanged());

        $this->reset();

        return $state;
    }

    public function loadData(DateTimeImmutable|null $date = null): self
    {
        if (true === $this->loaded) {
            return $this;
        }

        if ($this->lazyLoad) {
            $this->loaded = true;
            return $this;
        }

        foreach ($this->storage->getAll($date) as $index => $entity) {
            $this->objects[$index] = $entity;
            $this->addGuids($this->objects[$index], $index);
        }

        $this->loaded = true;

        return $this;
    }

    public function getChanged(): array
    {
        $arr = [];

        foreach ($this->changed as $id) {
            $arr[] = &$this->objects[$id];
        }

        return $arr;
    }

    public function add(string $bucket, string $name, StateEntity $entity, array $opts = []): self
    {
        if (!$entity->hasGuids()) {
            $this->logger->debug(sprintf('Ignoring %s. No valid GUIDs.', $name));
            Data::increment($bucket, $entity->type . '_failed_no_guid');
            return $this;
        }

        if (false === ($pointer = $this->getPointer($entity))) {
            $this->objects[] = $entity;

            $pointer = array_key_last($this->objects);
            $this->changed[$pointer] = $pointer;

            Data::increment($bucket, $entity->type . '_added');
            $this->addGuids($this->objects[$pointer], $pointer);
            $this->logger->debug(sprintf('Adding %s. As new Item.', $name));

            return $this;
        }

        // -- Ignore unwatched Item.
        if (0 === $entity->watched && true !== ($opts[ServerInterface::OPT_IMPORT_UNWATCHED] ?? false)) {
            // -- check for updated GUIDs.
            if ($this->objects[$pointer]->apply($entity, guidOnly: true)->isChanged()) {
                $this->changed[$pointer] = $pointer;
                Data::increment($bucket, $entity->type . '_updated');
                $this->addGuids($this->objects[$pointer], $pointer);
                $this->logger->debug(sprintf('Updating %s. GUIDs.', $name), $this->objects[$pointer]->diff());
                return $this;
            }

            $this->logger->debug(sprintf('Ignoring %s. Not watched.', $name));
            Data::increment($bucket, $entity->type . '_ignored_not_watched');
            return $this;
        }

        // -- Ignore old item.
        if (null !== ($opts['after'] ?? null) && ($opts['after'] instanceof DateTimeInterface)) {
            if ($opts['after']->getTimestamp() >= $entity->updated) {
                // -- check for updated GUIDs.
                if ($this->objects[$pointer]->apply($entity, guidOnly: true)->isChanged()) {
                    $this->changed[$pointer] = $pointer;
                    Data::increment($bucket, $entity->type . '_updated');
                    $this->addGuids($this->objects[$pointer], $pointer);
                    $this->logger->debug(sprintf('Updating %s. GUIDs.', $name), $this->objects[$pointer]->diff());
                    return $this;
                }

                $this->logger->debug(sprintf('Ignoring %s. Not played since last sync.', $name));
                Data::increment($bucket, $entity->type . '_ignored_not_played_since_last_sync');
                return $this;
            }
        }

        $this->objects[$pointer] = $this->objects[$pointer]->apply($entity);

        if ($this->objects[$pointer]->isChanged()) {
            Data::increment($bucket, $entity->type . '_updated');
            $this->changed[$pointer] = $pointer;
            $this->addGuids($this->objects[$pointer], $pointer);
            $this->logger->debug(sprintf('Updating %s. State changed.', $name), $this->objects[$pointer]->diff());
            return $this;
        }

        $this->logger->debug(sprintf('Ignoring %s. State unchanged.', $name));
        Data::increment($bucket, $entity->type . '_ignored_no_change');

        return $this;
    }

    private function addGuids(StateEntity $entity, int $pointer): void
    {
        foreach (Guid::fromArray($entity->getAll())->getPointers() as $key) {
            $this->guids[$key] = $pointer;
        }
    }

    public function get(StateEntity $entity): null|StateEntity
    {
        foreach (Guid::fromArray($entity->getAll())->getPointers() as $key) {
            if (null !== ($this->guids[$key] ?? null)) {
                return $this->objects[$this->guids[$key]];
            }
        }

        if (true === $this->lazyLoad && null !== ($lazyEntity = $this->storage->get($entity))) {
            $this->objects[] = $lazyEntity;
            $id = array_key_last($this->objects);
            $this->addGuids($this->objects[$id], $id);
            return $this->objects[$id];
        }

        return null;
    }

    public function has(StateEntity $entity): bool
    {
        return null !== $this->get($entity);
    }

    public function remove(StateEntity $entity): bool
    {
        if (false === ($pointer = $this->getPointer($entity))) {
            return false;
        }

        $this->storage->remove($this->objects[$pointer]);

        foreach (Guid::fromArray($entity->getAll())->getPointers() as $key) {
            if (null !== ($this->guids[$key] ?? null)) {
                unset($this->guids[$key]);
            }
        }

        unset($this->objects[$pointer]);

        return true;
    }

    /**
     * Is the object already mapped?
     *
     * @param StateEntity $entity
     *
     * @return int|bool int pointer for the object, Or false if not registered.
     */
    private function getPointer(StateEntity $entity): int|bool
    {
        foreach (Guid::fromArray($entity->getAll())->getPointers() as $key) {
            if (null !== ($this->guids[$key] ?? null)) {
                if (isset($this->removed[$this->guids[$key]])) {
                    unset($this->guids[$key]);
                    continue;
                }

                return $this->guids[$key];
            }
        }

        if (true === $this->lazyLoad && null !== ($lazyEntity = $this->storage->get($entity))) {
            $this->objects[] = $lazyEntity;
            $id = array_key_last($this->objects);
            $this->addGuids($this->objects[$id], $id);
            return $id;
        }

        return false;
    }

    public function reset(): self
    {
        $this->objects = [];
        $this->guids = [];
        $this->removed = [];

        return $this;
    }

    public function count(): int
    {
        return count($this->changed);
    }
}
