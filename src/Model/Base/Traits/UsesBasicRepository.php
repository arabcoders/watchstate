<?php

declare(strict_types=1);

namespace App\Model\Base\Traits;

use App\Libs\Database\DBLayer;
use App\Model\Base\BasicModel;
use App\Model\Base\Interfaces\IDInterface;
use Generator;
use InvalidArgumentException;
use RuntimeException;

trait UsesBasicRepository
{
    use UsesPaging;

    public function __construct(
        private readonly DBLayer $db,
    ) {
        $this->init($this->db);

        if (empty($this->table)) {
            throw new RuntimeException('You must set table name in $this->table');
        }
    }

    private function init(DBLayer $db): void {}

    private function _findOne(array $criteria, array $cols = []): mixed
    {
        if (empty($criteria)) {
            throw new InvalidArgumentException('criteria is empty.');
        }

        $q = $this->db->select($this->table, $cols, $criteria, ['limit' => 1])->fetch();

        if (empty($q)) {
            return null;
        }

        $isCustom = !empty($cols);

        $item = $this->getObject($q, $isCustom);

        return $isCustom || $item->validate() ? $item : null;
    }

    private function _findAll(array $criteria = [], array $cols = []): array
    {
        $arr = [];

        $q = $this->db->select($this->table, $cols, $criteria, [
            'count' => true,
            'start' => $this->getStart(),
            'limit' => $this->getPerpage(),
            'orderby' => [$this->getSort() => $this->getOrder()],
        ]);

        $isCustom = !empty($cols);

        $this->setTotal($this->db->totalRows());

        while ($row = $q->fetch()) {
            $item = $this->getObject($row);

            if (!$isCustom && !$item->validate()) {
                continue;
            }

            $arr[] = $item;
        }

        return $arr;
    }

    private function _findAllGenerator(array $criteria = [], array $cols = []): Generator
    {
        $q = $this->db->select($this->table, $cols, $criteria, [
            'orderby' => [$this->getSort() => $this->getOrder()],
        ]);

        $isCustom = !empty($cols);

        while ($row = $q->fetch()) {
            $item = $this->getObject($row);

            if (!$isCustom && !$item->validate()) {
                continue;
            }

            yield $item;
        }
    }

    private function _save(BasicModel $object, bool $useUUID = false, array $opts = []): mixed
    {
        $object->validate();

        if ($object->hasPrimaryKey()) {
            if ($arr = $object->diff(transform: true)) {
                $this->db->transactional(function (DBLayer $db) use ($arr, $object) {
                    $db->update($this->table, $arr, [$object->getPrimaryKey() => $object->getPrimaryId()]);
                    $object->updatePrimaryData();
                });
            }
            return $object->getPrimaryId();
        }

        if (true === ($isCustomID = is_a($this, IDInterface::class))) {
            $object->{$object->getPrimaryKey()} = $this->makeId($object);
        } elseif ($useUUID) {
            $object->{$object->getPrimaryKey()} = generate_uuid();
        }

        $this->db->transactional(function (DBLayer $db) use (&$object, $isCustomID, $useUUID) {
            $obj = $object->getAll(transform: true);
            $db->insert($this->table, $obj);

            if (!$isCustomID && !$useUUID) {
                $object->{$object->getPrimaryKey()} = (int) $db->id();
            }

            $object->updatePrimaryData();
        });

        return $object->getPrimaryId();
    }

    /**
     * Save All Given Entities in one transaction.
     *
     * @param array<BasicModel> $items
     * @param bool $useUUID
     * @param array $opts
     *
     * @return array
     */
    private function _saveAll(array $items, bool $useUUID = false, array $opts = []): array
    {
        return $this->db->transactional(function (DBLayer $db) use ($items, $useUUID) {
            $ids = [];
            $isCustomID = is_a($this, IDInterface::class);

            foreach ($items as $object) {
                if ($object->hasPrimaryKey()) {
                    if ($arr = $object->diff(transform: true)) {
                        $db->update($this->table, $arr, [$object->getPrimaryKey() => $object->getPrimaryId()]);
                        $object->updatePrimaryData();
                    }
                    $ids[$object->getPrimaryId()] = $object;
                    continue;
                }

                if (true === $isCustomID) {
                    /** @noinspection PhpUndefinedMethodInspection */
                    $object->{$object->getPrimaryKey()} = $this->makeId($object);
                } elseif ($useUUID) {
                    $object->{$object->getPrimaryKey()} = generate_uuid();
                }

                $obj = $object->getAll(transform: true);

                $db->insert($this->table, $obj);

                if (!$isCustomID && !$useUUID) {
                    $object->{$object->getPrimaryKey()} = (int) $db->id();
                }

                $object->updatePrimaryData();

                $ids[$object->getPrimaryKey()] = $object;
            }

            return $ids;
        });
    }

    private function _remove(BasicModel|array $criteria): bool
    {
        if ($criteria instanceof BasicModel) {
            if (!$criteria->hasPrimaryKey()) {
                throw new InvalidArgumentException(sprintf("'%s' has no primary key.", $criteria::class));
            }
            $criteria = [$criteria->getPrimaryKey() => $criteria->getPrimaryId()];
        }

        if (empty($criteria)) {
            throw new InvalidArgumentException('\'$criteria\' cannot be empty.');
        }

        $count = 0;

        $this->db->transactional(function (DBLayer $db) use (&$count, $criteria) {
            $count = $db->delete($this->table, $criteria);
        });

        return (bool) $count;
    }

    private function _removeById(string|int $id, string $columnName = 'id'): bool
    {
        $this->db->transactional(fn(DBLayer $db) => $db->delete($this->table, [$columnName => $id]));
        return true;
    }

    /**
     * Save All Given Entities in one transaction.
     *
     * @param array<BasicModel> $items
     * @param array $opts
     *
     * @return array
     */
    private function _removeAll(array $items, array $opts = []): array
    {
        return $this->db->transactional(function (DBLayer $db) use ($items) {
            $ids = [];

            foreach ($items as $object) {
                if (!$object->hasPrimaryKey()) {
                    continue;
                }

                $db->delete($this->table, [$object->getPrimaryKey() => $object->getPrimaryId()]);
                $ids[] = $object->getPrimaryId();
            }

            return $ids;
        });
    }
}
