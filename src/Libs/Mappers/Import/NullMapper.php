<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\ImportInterface as iImport;
use DateTimeInterface as iDate;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;

/**
 * Mapper class based on MemoryMapper with stripped down functionality.
 *
 * @implements iImport
 */
class NullMapper extends MemoryMapper implements iImport
{
    public function __construct(iLogger $logger, iDB $db, iCache $cache)
    {
        $this->fullyLoaded = true;
        parent::__construct($logger, $db, $cache);
    }

    public function loadData(?iDate $date = null): static
    {
        $this->fullyLoaded = true;
        return $this;
    }

    public function add(iState $entity, array $opts = []): static
    {
        $this->fullyLoaded = true;
        return parent::add($entity, $opts);
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

    /**
     * Compute the play state for each backend.
     *
     * @param array $backends List of backends to check.
     *
     * @return array List of changes for each backend.
     */
    public function computeChanges(array $backends): array
    {
        $changes = [];

        foreach ($backends as $backend) {
            $changes[$backend] = [];
        }

        foreach ($this->objects as $entity) {
            $state = $entity->isSynced($backends);
            foreach ($state as $b => $value) {
                if (false === $value) {
                    $changes[$b][] = $entity;
                }
            }
        }

        return $changes;
    }

    public function __destruct()
    {
        // -- disabled autocommit.
    }
}
