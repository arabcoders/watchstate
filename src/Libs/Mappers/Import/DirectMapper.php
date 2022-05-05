<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Data;
use App\Libs\Entity\StateInterface;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Storage\StorageInterface;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class DirectMapper implements ImportInterface
{
    private array $operations = [
        StateInterface::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
        StateInterface::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
    ];

    private int $changed = 0;

    public function __construct(private LoggerInterface $logger, private StorageInterface $storage)
    {
    }

    public function setUp(array $opts): ImportInterface
    {
        return $this;
    }

    public function loadData(DateTimeInterface|null $date = null): ImportInterface
    {
        return $this;
    }

    public function add(string $bucket, string $name, StateInterface $entity, array $opts = []): self
    {
        if (!$entity->hasGuids() && $entity->hasRelativeGuid()) {
            $this->logger->info(sprintf('Ignoring %s. No valid GUIDs.', $name));
            Data::increment($bucket, $entity->type . '_failed_no_guid');
            return $this;
        }

        $item = $this->get($entity);

        if (null === $entity->id && null === $item) {
            try {
                $this->storage->insert($entity);
            } catch (Throwable $e) {
                $this->operations[$entity->type]['failed']++;
                Data::append($bucket, 'storage_error', $e->getMessage());
                return $this;
            }

            $this->changed++;
            Data::increment($bucket, $entity->type . '_added');
            $this->operations[$entity->type]['added']++;
            $this->logger->debug(sprintf('Adding %s. As new Item.', $name));
            return $this;
        }

        // -- Ignore old item.
        if (null !== ($opts['after'] ?? null) && ($opts['after'] instanceof DateTimeInterface)) {
            if ($opts['after']->getTimestamp() >= $entity->updated) {
                // -- check for updated GUIDs.
                if ($item->apply($entity, guidOnly: true)->isChanged()) {
                    try {
                        $this->changed++;
                        if (!empty($entity->meta)) {
                            $item->meta = $entity->meta;
                        }
                        $this->storage->update($item);
                        $this->operations[$entity->type]['updated']++;
                        $this->logger->debug(sprintf('Updating %s. GUIDs.', $name), $item->diff());
                        return $this;
                    } catch (Throwable $e) {
                        $this->operations[$entity->type]['failed']++;
                        Data::append($bucket, 'storage_error', $e->getMessage());
                        return $this;
                    }
                }

                $this->logger->debug(sprintf('Ignoring %s. No change since last sync.', $name));
                Data::increment($bucket, $entity->type . '_ignored_not_played_since_last_sync');
                return $this;
            }
        }

        $item = $item->apply($entity);

        if ($item->isChanged()) {
            try {
                $this->storage->update($item);
            } catch (Throwable $e) {
                $this->operations[$entity->type]['failed']++;
                Data::append($bucket, 'storage_error', $e->getMessage());
                return $this;
            }

            $this->changed++;
            Data::increment($bucket, $entity->type . '_updated');
            $this->operations[$entity->type]['updated']++;
        } else {
            Data::increment($bucket, $entity->type . '_ignored_no_change');
        }

        return $this;
    }

    public function get(StateInterface $entity): null|StateInterface
    {
        return $this->storage->get($entity);
    }

    public function remove(StateInterface $entity): bool
    {
        return $this->storage->remove($entity);
    }

    public function commit(): mixed
    {
        $op = $this->operations;

        $this->reset();

        return $op;
    }

    public function has(StateInterface $entity): bool
    {
        return null !== $this->storage->get($entity);
    }

    public function reset(): self
    {
        $this->changed = 0;

        $this->operations[StateInterface::TYPE_EPISODE]['added'] = 0;
        $this->operations[StateInterface::TYPE_EPISODE]['updated'] = 0;
        $this->operations[StateInterface::TYPE_EPISODE]['failed'] = 0;
        $this->operations[StateInterface::TYPE_MOVIE]['added'] = 0;
        $this->operations[StateInterface::TYPE_MOVIE]['updated'] = 0;
        $this->operations[StateInterface::TYPE_MOVIE]['failed'] = 0;

        return $this;
    }

    public function getObjects(array $opts = []): array
    {
        return [];
    }

    public function getObjectsCount(): int
    {
        return 0;
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

    public function count(): int
    {
        return $this->changed;
    }
}
