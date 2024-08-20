<?php

declare(strict_types=1);

namespace App\Model\Events;

use App\Model\Base\Traits\UsesBasicRepository;
use App\Model\Events\Event as EntityItem;
use App\Model\Events\EventsTable as EntityTable;

final class EventsRepository
{
    use UsesBasicRepository;

    protected string $table = EntityTable::TABLE_NAME;
    protected string $primaryKey = EntityTable::TABLE_PRIMARY_KEY;

    public function getObject(array $row, bool $isCustom = false, array $opts = []): EntityItem
    {
        return (new EntityItem($row, $isCustom, $opts));
    }

    public function findOne(array $criteria): EntityItem|null
    {
        return $this->_findOne($criteria);
    }

    public function findById(string $id): EntityItem|null
    {
        return $this->_findOne([$this->primaryKey => $id]);
    }

    /**
     * Will return the last event by reference.
     *
     * @param string|int $reference Reference to search by.
     * @param array $criteria Criteria to search by.
     *
     * @return EntityItem|null The event or null if not found.
     */
    public function findByReference(string|int $reference, array $criteria = []): EntityItem|null
    {
        $criteria[EntityTable::COLUMN_REFERENCE] = $reference;

        $items = (clone $this)->setPerpage(1)->setStart(0)->setDescendingOrder()
            ->setSort(EntityTable::COLUMN_CREATED_AT)
            ->findAll($criteria);

        return $items[0] ?? null;
    }

    /**
     * Will return the last event by reference.
     *
     * @param string|int $reference reference id to remove.
     * @param array $criteria Filter criteria. By default, it will only remove pending events.
     */
    public function removeByReference(string|int $reference, array $criteria = []): bool
    {
        if (empty($criteria)) {
            $criteria[EntityTable::COLUMN_STATUS] = EventStatus::PENDING->value;
        }

        $criteria[EntityTable::COLUMN_REFERENCE] = $reference;

        $stmt = $this->db->delete($this->table, $criteria, [
            'limit' => 1,
            'orderby' => [EntityTable::COLUMN_CREATED_AT => 'DESC'],
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array $criteria Criteria to search by.
     * @param array $cols Columns to select.
     * @return array<EntityItem> empty array if no match found.
     */
    public function findAll(array $criteria = [], array $cols = []): array
    {
        return $this->_findAll($criteria, $cols);
    }

    public function save(EntityItem $object): string
    {
        return $this->_save($object, useUUID: true);
    }

    /**
     * @param array<EntityItem> $items
     *
     * @return array<string, EntityItem>
     */
    public function saveAll(array $items): array
    {
        return $this->_saveAll($items, useUUID: true);
    }

    public function remove(EntityItem|array $criteria): bool
    {
        return $this->_remove($criteria);
    }
}
