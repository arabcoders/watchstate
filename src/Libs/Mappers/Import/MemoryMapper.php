<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Data;
use App\Libs\Entity\StateInterface;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Storage\StorageInterface;
use DateTimeInterface;
use Psr\Log\LoggerInterface;

final class MemoryMapper implements ImportInterface
{
    /**
     * @var array<int,StateInterface> Load all entities.
     */
    private array $objects = [];

    /**
     * @var array<string,int> Map GUIDs to entities.
     */
    private array $guids = [];

    /**
     * @var array<int,int> List Changed Entities.
     */
    private array $changed = [];

    private array $options = [];

    private bool $fullyLoaded = false;

    public function __construct(private LoggerInterface $logger, private StorageInterface $storage)
    {
    }

    public function setUp(array $opts): ImportInterface
    {
        $this->options = $opts;

        return $this;
    }

    public function loadData(DateTimeInterface|null $date = null): self
    {
        $this->fullyLoaded = null === $date;

        foreach ($this->storage->getAll($date, $this->options['class'] ?? null) as $entity) {
            if (null !== ($this->objects[$entity->id] ?? null)) {
                continue;
            }
            $this->objects[$entity->id] = $entity;
            $this->addPointers($this->objects[$entity->id], $entity->id);
        }

        return $this;
    }

    public function add(string $bucket, string $name, StateInterface $entity, array $opts = []): self
    {
        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $this->logger->info(sprintf('Ignoring %s. No valid GUIDs.', $name));
            Data::increment($bucket, $entity->type . '_failed_no_guid');
            return $this;
        }

        if (false === ($pointer = $this->getPointer($entity))) {
            $this->objects[] = $entity;

            $pointer = array_key_last($this->objects);
            $this->changed[$pointer] = $pointer;

            Data::increment($bucket, $entity->type . '_added');
            $this->addPointers($this->objects[$pointer], $pointer);
            $this->logger->debug(sprintf('Adding %s. As new Item.', $name));

            return $this;
        }

        // -- Ignore old item.
        if (null !== ($opts['after'] ?? null) && ($opts['after'] instanceof DateTimeInterface)) {
            if ($opts['after']->getTimestamp() >= $entity->updated) {
                $cloned = clone $this->objects[$pointer];
                // -- check for updated GUIDs.
                if ($this->objects[$pointer]->apply($entity, guidOnly: true)->isChanged()) {
                    $this->changed[$pointer] = $pointer;
                    Data::increment($bucket, $entity->type . '_updated');
                    $this->removePointers($cloned);
                    $this->addPointers($this->objects[$pointer], $pointer);
                    $this->logger->debug(sprintf('Updating %s. Parent & Entity GUIDs.', $name), [
                        'changes' => $this->objects[$pointer]->diff(),
                    ]);

                    return $this;
                }

                $this->logger->debug(sprintf('Ignoring %s. No change since last sync.', $name));
                Data::increment($bucket, $entity->type . '_ignored_not_played_since_last_sync');
                return $this;
            }
        }

        $this->objects[$pointer] = $this->objects[$pointer]->apply($entity);

        $cloned = clone $this->objects[$pointer];
        if (true === $this->objects[$pointer]->isChanged()) {
            Data::increment($bucket, $entity->type . '_updated');
            $this->changed[$pointer] = $pointer;
            $this->removePointers($cloned);
            $this->addPointers($this->objects[$pointer], $pointer);
            $this->logger->debug(sprintf('Updating %s. State changed.', $name), [
                'changes' => $this->objects[$pointer]->diff(all: true),
            ]);
            return $this;
        }

        $this->logger->debug(sprintf('Ignoring %s. State unchanged.', $name));
        Data::increment($bucket, $entity->type . '_ignored_no_change');

        return $this;
    }

    public function get(StateInterface $entity): null|StateInterface
    {
        if (null !== $entity->id && null !== ($this->objects[$entity->id] ?? null)) {
            return $this->objects[$entity->id];
        }

        return false === ($pointer = $this->getPointer($entity)) ? null : $this->objects[$pointer];
    }

    public function remove(StateInterface $entity): bool
    {
        if (false === ($pointer = $this->getPointer($entity))) {
            return false;
        }

        $this->storage->remove($this->objects[$pointer]);

        $this->removePointers($this->objects[$pointer]);

        unset($this->objects[$pointer]);

        if (null !== ($this->changed[$pointer] ?? null)) {
            unset($this->changed[$pointer]);
        }

        return true;
    }

    public function commit(): mixed
    {
        $state = $this->storage->commit(
            array_intersect_key($this->objects, $this->changed)
        );

        $this->reset();

        return $state;
    }

    public function has(StateInterface $entity): bool
    {
        return null !== $this->get($entity);
    }

    public function reset(): self
    {
        $this->fullyLoaded = false;
        $this->objects = $this->changed = $this->guids = [];

        return $this;
    }

    public function getObjects(array $opts = []): array
    {
        return $this->objects;
    }

    public function getObjectsCount(): int
    {
        return count($this->objects);
    }

    public function count(): int
    {
        return count($this->changed);
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

    /**
     * Is the object already mapped?
     *
     * @param StateInterface $entity
     *
     * @return int|bool int pointer for the object, Or false if not registered.
     */
    private function getPointer(StateInterface $entity): int|bool
    {
        foreach ([...$entity->getRelativePointers(), ...$entity->getPointers()] as $key) {
            if (null !== ($this->guids[$key] ?? null)) {
                return $this->guids[$key];
            }
        }

        if (false === $this->fullyLoaded && null !== ($lazyEntity = $this->storage->get($entity))) {
            $this->objects[] = $lazyEntity;
            $id = array_key_last($this->objects);
            $this->addPointers($this->objects[$id], $id);
            return $id;
        }

        return false;
    }

    private function addPointers(StateInterface $entity, int $pointer): void
    {
        foreach ([...$entity->getPointers(), ...$entity->getRelativePointers()] as $key) {
            $this->guids[$key] = $pointer;
        }
    }

    private function removePointers(StateInterface $entity): void
    {
        foreach ([...$entity->getPointers(), ...$entity->getRelativePointers()] as $key) {
            if (isset($this->guids[$key])) {
                unset($this->guids[$key]);
            }
        }
    }

    public function __destruct()
    {
        if (false === ($this->options['disable_autocommit'] ?? false) && $this->count() >= 1) {
            $this->commit();
        }
    }
}
