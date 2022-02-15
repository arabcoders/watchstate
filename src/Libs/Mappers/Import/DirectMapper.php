<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Data;
use App\Libs\Entity\StateEntity;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Storage\StorageInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

final class DirectMapper implements ImportInterface
{
    private array $operations = [
        StateEntity::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
        StateEntity::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
    ];

    public function __construct(private StorageInterface $storage)
    {
    }

    public function setLogger(LoggerInterface $logger): self
    {
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
        return $this;
    }

    public function commit(): mixed
    {
        return $this->operations;
    }

    public function loadData(DateTimeImmutable|null $date = null): self
    {
        return $this;
    }

    public function add(string $bucket, StateEntity $entity, array $opts = []): self
    {
        if (!$entity->hasGuids()) {
            Data::increment($bucket, $entity->type . '_failed_no_guid');
            return $this;
        }

        $record = $this->get($entity);

        if (null === $entity->id && null === $record) {
            try {
                $this->storage->insert($entity);
            } catch (Throwable $e) {
                $this->operations[$entity->type]['failed']++;
                Data::append($bucket, 'storage_error', $e->getMessage());
                return $this;
            }
            Data::increment($bucket, $entity->type . '_added');
            $this->operations[$entity->type]['added']++;
            return $this;
        }

        $record = $record->apply($entity);

        if ($record->isChanged()) {
            try {
                $this->storage->update($record);
            } catch (Throwable $e) {
                $this->operations[$entity->type]['failed']++;
                Data::append($bucket, 'storage_error', $e->getMessage());
                return $this;
            }

            Data::increment($bucket, $entity->type . '_updated');
            $this->operations[$entity->type]['updated']++;
        } else {
            Data::increment($bucket, $entity->type . '_ignored_no_change');
        }

        return $this;
    }

    public function get(StateEntity $entity): null|StateEntity
    {
        return $this->storage->get($entity);
    }

    public function has(StateEntity $entity): bool
    {
        return null !== $this->storage->get($entity);
    }

    public function remove(StateEntity $entity): bool
    {
        return $this->storage->remove($entity);
    }

    public function reset(): self
    {
        return $this;
    }

    public function count(): int
    {
        return 0;
    }
}
