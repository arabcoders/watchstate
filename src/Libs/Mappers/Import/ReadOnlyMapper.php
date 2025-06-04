<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\ImportInterface as iImport;

/**
 * Mapper class based on MemoryMapper with stripped down functionality.
 *
 * @implements iImport
 */
class ReadOnlyMapper extends MemoryMapper implements iImport
{
    private bool $isContainer = false;

    public function asContainer(): void
    {
        $this->isContainer = true;
    }

    public function add(iState $entity, array $opts = []): self
    {
        if (false === $this->isContainer) {
            return parent::add($entity, $opts);
        }
        $this->objects[] = $entity;
        $pointer = array_key_last($this->objects);
        $this->changed[$pointer] = $pointer;
        $this->addPointers($this->objects[$pointer], $pointer);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function remove(iState $entity): bool
    {
        if (false === ($pointer = $this->getPointer($entity))) {
            return false;
        }

        $this->removePointers($this->objects[$pointer]);

        if (null !== ($this->objects[$pointer] ?? null)) {
            unset($this->objects[$pointer]);
        }

        if (null !== ($this->changed[$pointer] ?? null)) {
            unset($this->changed[$pointer]);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function commit(): array
    {
        $this->reset();

        return [
            iState::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
            iState::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
        ];
    }

    public function __destruct()
    {
        // -- disabled autocommit.
    }
}
