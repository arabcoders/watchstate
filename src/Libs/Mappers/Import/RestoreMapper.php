<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\Mappers\ImportInterface as iImport;
use DateTimeInterface as iDate;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Psr\Log\LoggerInterface as iLogger;

final class RestoreMapper implements iImport
{
    /**
     * @var array<int,iState> Entities table.
     */
    protected array $objects = [];

    /**
     * @var array<string,int> Map GUIDs to entities.
     */
    protected array $pointers = [];

    protected array $options = [];

    public function __construct(private iLogger $logger, private string $file)
    {
    }

    public function setOptions(array $options = []): iImport
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @throws \JsonMachine\Exception\InvalidArgumentException
     */
    public function loadData(iDate|null $date = null): self
    {
        $it = Items::fromFile($this->file, [
            'decoder' => new ErrorWrappingDecoder(new ExtJsonDecoder(true, JSON_INVALID_UTF8_IGNORE))
        ]);

        $state = new StateEntity([]);

        foreach ($it as $entity) {
            if ($entity instanceof DecodingError) {
                continue;
            }

            $entity[iState::COLUMN_VIA] = 'backup_file';

            if (null !== ($entity[iState::COLUMN_GUIDS] ?? null)) {
                $entity[iState::COLUMN_GUIDS] = Guid::fromArray($entity[iState::COLUMN_GUIDS])->getAll();
            }

            if (null !== ($entity[iState::COLUMN_PARENT] ?? null)) {
                $entity[iState::COLUMN_PARENT] = Guid::fromArray($entity[iState::COLUMN_PARENT])->getAll();
            }

            $item = $state::fromArray($entity);

            $this->add($item);
        }

        return $this;
    }

    public function add(iState $entity, array $opts = []): self
    {
        if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
            $this->logger->debug('MAPPER: Ignoring [%(title)] no valid/supported external ids.', [
                'title' => $entity->getName(),
            ]);
            return $this;
        }

        if (false === $this->getPointer($entity)) {
            $this->objects[] = $entity;
            $pointer = array_key_last($this->objects);
            $this->addPointers($this->objects[$pointer], $pointer);
        }

        return $this;
    }

    public function get(iState $entity): null|iState
    {
        return false === ($pointer = $this->getPointer($entity)) ? null : $this->objects[$pointer];
    }

    public function remove(iState $entity): bool
    {
        return true;
    }

    public function commit(): mixed
    {
        $this->reset();

        return [];
    }

    public function has(iState $entity): bool
    {
        return null !== $this->get($entity);
    }

    public function reset(): self
    {
        $this->objects = $this->pointers = [];

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
        return 0;
    }

    public function setLogger(iLogger $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function setDatabase(iDB $db): self
    {
        return $this;
    }

    public function inDryRunMode(): bool
    {
        return false;
    }

    public function inTraceMode(): bool
    {
        return false;
    }

    protected function addPointers(iState $entity, string|int $pointer): iImport
    {
        foreach ($entity->getRelativePointers() as $key) {
            $this->pointers[$key] = $pointer;
        }

        foreach ($entity->getPointers() as $key) {
            $this->pointers[$key . '/' . $entity->type] = $pointer;
        }

        return $this;
    }

    protected function getPointer(iState $entity): int|string|bool
    {
        foreach ($entity->getRelativePointers() as $key) {
            if (null !== ($this->pointers[$key] ?? null)) {
                return $this->pointers[$key];
            }
        }

        foreach ($entity->getPointers() as $key) {
            $lookup = $key . '/' . $entity->type;
            if (null !== ($this->pointers[$lookup] ?? null)) {
                return $this->pointers[$lookup];
            }
        }

        return false;
    }

    public function getPointersList(): array
    {
        return $this->pointers;
    }

    public function getChangedList(): array
    {
        return [];
    }
}
