<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Export;

use App\Libs\Entity\StateInterface;
use App\Libs\Guid;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Storage\StorageInterface;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ExportMapper implements ExportInterface
{
    /**
     * @var array<int|string,StateInterface> Holds Entities.
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

    private bool $fullyLoaded = false;

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
        $this->fullyLoaded = null === $date;

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

    public function findByIds(array $ids): null|StateInterface
    {
        $pointers = Guid::fromArray($ids)->getPointers();

        foreach ($pointers as $key) {
            if (null !== ($this->guids[$key] ?? null)) {
                return $this->objects[$this->guids[$key]];
            }
        }

        if (true === $this->fullyLoaded) {
            return null;
        }

        if (null !== ($lazyEntity = $this->storage->matchAnyId($ids))) {
            $this->objects[$lazyEntity->id] = $lazyEntity;
            $this->addGuids($this->objects[$lazyEntity->id], $lazyEntity->id);
            return $this->objects[$lazyEntity->id];
        }

        return null;
    }

    public function get(StateInterface $entity): null|StateInterface
    {
        if (null !== $entity->id && null !== ($this->objects[$entity->id] ?? null)) {
            return $this->objects[$entity->id];
        }

        foreach ($entity->getPointers() as $key) {
            if (null !== ($this->guids[$key] ?? null)) {
                return $this->objects[$this->guids[$key]];
            }
        }

        if (true === $this->fullyLoaded) {
            return null;
        }

        if (null !== ($lazyEntity = $this->storage->get($entity))) {
            $this->objects[$lazyEntity->id] = $lazyEntity;
            $this->addGuids($this->objects[$lazyEntity->id], $lazyEntity->id);
            return $this->objects[$lazyEntity->id];
        }

        return null;
    }

    public function has(StateInterface $entity): bool
    {
        return null !== $this->get($entity);
    }

    public function reset(): self
    {
        $this->fullyLoaded = false;
        $this->objects = $this->guids = $this->queue = [];

        return $this;
    }

    private function addGuids(StateInterface $entity, int|string $pointer): void
    {
        foreach ($entity->getPointers() as $key) {
            $this->guids[$key] = $pointer;
        }
    }
}
