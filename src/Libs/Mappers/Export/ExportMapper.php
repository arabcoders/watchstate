<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Export;

use App\Libs\Entity\StateEntity;
use App\Libs\Guid;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Storage\StorageInterface;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ExportMapper implements ExportInterface
{
    /**
     * @var array<int|string,StateEntity> Holds Entities.
     */
    private array $objects = [];

    /**
     * @var array<string,int|string> Map GUIDs to entities.
     */
    private array $guids = [];

    /**
     * @var array<ResponseInterface> Queued Requests.
     */
    private array $queue = [];

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

    public function setUp(array $opts): self
    {
        return $this;
    }

    public function loadData(DateTimeInterface|null $date = null): self
    {
        if (!empty($this->objects)) {
            return $this;
        }

        foreach ($this->storage->getAll($date) as $entity) {
            if (null !== ($this->objects[$entity->id] ?? null)) {
                continue;
            }
            $this->objects[$entity->id] = $entity;
            $this->addGuids($this->objects[$entity->id], $entity->id);
        }

        return $this;
    }

    public function getQueue(): array
    {
        return $this->queue;
    }

    public function queue(ResponseInterface $request): self
    {
        $this->queue[] = $request;

        return $this;
    }

    private function addGuids(StateEntity $entity, int|string $pointer): void
    {
        foreach (Guid::fromArray($entity->getAll())->getPointers() as $key) {
            $this->guids[$key] = $pointer;
        }
    }

    public function findByIds(array $ids): null|StateEntity
    {
        foreach (Guid::fromArray($ids)->getPointers() as $key) {
            if (null !== ($this->guids[$key] ?? null)) {
                return $this->objects[$this->guids[$key]];
            }
        }

        return null;
    }

    public function get(StateEntity $entity): null|StateEntity
    {
        if (null !== $entity->id && null !== ($this->objects[$entity->id] ?? null)) {
            return $this->objects[$entity->id];
        }

        foreach (Guid::fromArray($entity->getAll())->getPointers() as $key) {
            if (null !== ($this->guids[$key] ?? null)) {
                return $this->objects[$this->guids[$key]];
            }
        }

        if (null !== ($lazyEntity = $this->storage->get($entity))) {
            $this->objects[$lazyEntity->id] = $lazyEntity;
            $this->addGuids($this->objects[$lazyEntity->id], $lazyEntity->id);
            return $this->objects[$lazyEntity->id];
        }

        return null;
    }

    public function has(StateEntity $entity): bool
    {
        return null !== $this->get($entity);
    }

    public function reset(): self
    {
        $this->objects = $this->guids = $this->queue = [];

        return $this;
    }
}
