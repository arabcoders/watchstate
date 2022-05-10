<?php

declare(strict_types=1);

namespace App\Libs\Entity;

use App\Libs\Guid;
use RuntimeException;

final class StateEntity implements StateInterface
{
    private array $data = [];
    private bool $tainted = false;

    public null|string|int $id = null;
    public string $type = '';
    public int $updated = 0;
    public int $watched = 0;

    public string $via = '';
    public string $title = '';

    public int|null $year = null;
    public int|null $season = null;
    public int|null $episode = null;

    public array $parent = [];
    public array $guids = [];
    public array $extra = [];

    public function __construct(array $data)
    {
        foreach ($data as $key => $val) {
            if (!in_array($key, StateInterface::ENTITY_KEYS)) {
                continue;
            }

            if ('type' === $key && self::TYPE_MOVIE !== $val && self::TYPE_EPISODE !== $val) {
                throw new RuntimeException(
                    sprintf(
                        'Unexpected type value was given. Was expecting \'%1$s or %2$s\' but got \'%3$s\' instead.',
                        self::TYPE_MOVIE,
                        self::TYPE_EPISODE,
                        $val
                    )
                );
            }

            foreach (StateInterface::ENTITY_ARRAY_KEYS as $subKey) {
                if ($subKey !== $key) {
                    continue;
                }

                if (true === is_array($val)) {
                    continue;
                }

                if (null === ($val = json_decode($val ?? '{}', true))) {
                    $val = [];
                }
            }

            $this->{$key} = $val;
        }

        $this->data = $this->getAll();
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function diff(bool $all = false): array
    {
        $changed = [];

        foreach ($this->getAll() as $key => $value) {
            if (false === $all && true === in_array($key, StateInterface::ENTITY_IGNORE_DIFF_CHANGES)) {
                continue;
            }

            if ($value === ($this->data[$key] ?? null)) {
                continue;
            }

            if (true === in_array($key, StateInterface::ENTITY_ARRAY_KEYS)) {
                $changes = array_diff_assoc_recursive($this->data[$key] ?? [], $value ?? []);
                if (!empty($changes)) {
                    foreach (array_keys($changes) as $subKey) {
                        $changed[$key][$subKey] = [
                            'old' => $this->data[$key][$subKey] ?? 'None',
                            'new' => $value[$subKey] ?? 'None'
                        ];
                    }
                }
            } else {
                $changed[$key] = [
                    'old' => $this->data[$key] ?? 'None',
                    'new' => $value ?? 'None'
                ];
            }
        }

        return $changed;
    }

    public function getName(): string
    {
        if ($this->isMovie()) {
            return sprintf('%s (%d)', $this->title ?? '??', $this->year ?? 0000);
        }

        return sprintf(
            '%s (%s) - %sx%s',
            $this->title ?? '??',
            $this->year ?? 0000,
            str_pad((string)($this->season ?? 0), 2, '0', STR_PAD_LEFT),
            str_pad((string)($this->episode ?? 0), 3, '0', STR_PAD_LEFT)
        );
    }

    public function getAll(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'updated' => $this->updated,
            'watched' => $this->watched,
            'via' => $this->via,
            'title' => $this->title,
            'year' => $this->year,
            'season' => $this->season,
            'episode' => $this->episode,
            'parent' => $this->parent,
            'guids' => $this->guids,
            'extra' => $this->extra,
        ];
    }

    public function isChanged(): bool
    {
        return count($this->diff(all: false)) >= 1;
    }

    public function hasGuids(): bool
    {
        return count($this->guids) >= 1;
    }

    public function getGuids(): array
    {
        return $this->guids;
    }

    public function hasParentGuid(): bool
    {
        return count($this->parent) >= 1;
    }

    public function getParentGuids(): array
    {
        return $this->parent;
    }

    public function isMovie(): bool
    {
        return StateInterface::TYPE_MOVIE === $this->type;
    }

    public function isEpisode(): bool
    {
        return StateInterface::TYPE_EPISODE === $this->type;
    }

    public function isWatched(): bool
    {
        return 1 === $this->watched;
    }

    public function hasRelativeGuid(): bool
    {
        return $this->isEpisode() && !empty($this->parent) && null !== $this->season && null !== $this->episode;
    }

    public function getRelativeGuids(): array
    {
        if (!$this->isEpisode()) {
            return [];
        }

        $list = [];

        foreach ($this->parent as $key => $val) {
            $list[$key] = $val . '/' . $this->season . '/' . $this->episode;
        }

        return array_intersect_key($list, Guid::SUPPORTED);
    }

    public function getRelativePointers(): array
    {
        if (!$this->isEpisode()) {
            return [];
        }

        $list = Guid::fromArray($this->getRelativeGuids())->getPointers();

        $rPointers = [];

        foreach ($list as $val) {
            $rPointers[] = 'r' . $val;
        }

        return $rPointers;
    }

    public function apply(StateInterface $entity, bool $guidOnly = false): self
    {
        if (true === $guidOnly) {
            if ($this->guids !== $entity->guids) {
                $this->updateValue('guids', $entity);
            }

            if ($this->parent !== $entity->parent) {
                $this->updateValue('parent', $entity);
            }

            return $this;
        }

        if ($this->isEqual($entity)) {
            return $this;
        }

        foreach ($entity->getAll() as $key => $val) {
            $this->updateValue($key, $entity);
        }

        return $this;
    }

    public function updateOriginal(): StateInterface
    {
        $this->data = $this->getAll();
        return $this;
    }

    public function getOriginalData(): array
    {
        return $this->data;
    }

    public function getPointers(array|null $guids = null): array
    {
        $list = array_intersect_key($this->guids, Guid::SUPPORTED);

        if ($this->isEpisode()) {
            foreach ($list as $key => $val) {
                $list[$key] = $val . '/' . $this->season . '/' . $this->episode;
            }
        }

        return Guid::fromArray($list)->getPointers();
    }

    public function setIsTainted(bool $isTainted): StateInterface
    {
        $this->tainted = $isTainted;
        return $this;
    }

    public function isTainted(): bool
    {
        return $this->tainted;
    }

    private function isEqual(StateInterface $entity): bool
    {
        foreach ($this->getAll() as $key => $val) {
            $checkedValue = $this->isEqualValue($key, $entity);
            if (false === $checkedValue) {
                return false;
            }
        }

        return true;
    }

    private function isEqualValue(string $key, StateInterface $entity): bool
    {
        if ('updated' === $key || 'watched' === $key) {
            return !($entity->updated > $this->updated && $entity->watched !== $this->watched);
        }

        if (null !== ($entity->{$key} ?? null) && $this->{$key} !== $entity->{$key}) {
            return false;
        }

        return true;
    }

    private function updateValue(string $key, StateInterface $entity): void
    {
        if ('updated' === $key || 'watched' === $key) {
            if ($entity->updated > $this->updated && $entity->watched !== $this->watched) {
                $this->updated = $entity->updated;
                $this->watched = $entity->watched;
            }
            return;
        }

        if ('id' === $key) {
            return;
        }

        if (true === in_array($key, StateInterface::ENTITY_ARRAY_KEYS)) {
            $this->{$key} = array_replace_recursive($this->{$key} ?? [], $entity->{$key} ?? []);
        } else {
            $this->{$key} = $entity->{$key};
        }
    }
}
