<?php

declare(strict_types=1);

namespace App\Libs\Entity;

use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use RuntimeException;

final class StateEntity implements iFace
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
    public array $metadata = [];
    public array $extra = [];

    public function __construct(array $data)
    {
        foreach ($data as $key => $val) {
            if (!in_array($key, iFace::ENTITY_KEYS)) {
                continue;
            }

            if (iFace::COLUMN_TYPE === $key && self::TYPE_MOVIE !== $val && self::TYPE_EPISODE !== $val) {
                throw new RuntimeException(
                    sprintf(
                        'Unexpected type value was given. Was expecting \'%1$s or %2$s\' but got \'%3$s\' instead.',
                        self::TYPE_MOVIE,
                        self::TYPE_EPISODE,
                        $val
                    )
                );
            }

            foreach (iFace::ENTITY_ARRAY_KEYS as $subKey) {
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

    public function diff(array $fields = []): array
    {
        $changed = [];

        $keys = !empty($fields) ? $fields : iFace::ENTITY_KEYS;

        foreach ($keys as $key) {
            if ($this->{$key} === ($this->data[$key] ?? null)) {
                continue;
            }

            if (true === in_array($key, iFace::ENTITY_ARRAY_KEYS)) {
                $changes = $this->arrayDiff($this->data[$key] ?? [], $this->{$key} ?? []);
                if (!empty($changes)) {
                    $changed[$key] = $changes;
                }
            } else {
                $changed[$key] = [
                    'old' => $this->data[$key] ?? 'None',
                    'new' => $this->{$key} ?? 'None'
                ];
            }
        }

        return $changed;
    }

    public function getName(bool $asMovie = false): string
    {
        $title = ag($this->data, iFace::COLUMN_TITLE, $this->title);
        $year = ag($this->data, iFace::COLUMN_YEAR, $this->year);

        if ($this->isMovie() || true === $asMovie) {
            return sprintf('%s (%s)', $title, $year ?? '0000');
        }

        return sprintf(
            '%s (%s) - %sx%s',
            $title ?? '??',
            $year ?? '0000',
            str_pad((string)ag($this->data, iFace::COLUMN_SEASON, $this->season ?? 0), 2, '0', STR_PAD_LEFT),
            str_pad((string)ag($this->data, iFace::COLUMN_EPISODE, $this->episode ?? 0), 3, '0', STR_PAD_LEFT)
        );
    }

    public function getAll(): array
    {
        return [
            iFace::COLUMN_ID => $this->id,
            iFace::COLUMN_TYPE => $this->type,
            iFace::COLUMN_UPDATED => $this->updated,
            iFace::COLUMN_WATCHED => $this->watched,
            iFace::COLUMN_VIA => $this->via,
            iFace::COLUMN_TITLE => $this->title,
            iFace::COLUMN_YEAR => $this->year,
            iFace::COLUMN_SEASON => $this->season,
            iFace::COLUMN_EPISODE => $this->episode,
            iFace::COLUMN_PARENT => $this->parent,
            iFace::COLUMN_GUIDS => $this->guids,
            iFace::COLUMN_META_DATA => $this->metadata,
            iFace::COLUMN_EXTRA => $this->extra,
        ];
    }

    public function isChanged(array $fields = []): bool
    {
        return count($this->diff($fields)) >= 1;
    }

    public function hasGuids(): bool
    {
        $list = array_intersect_key($this->guids, Guid::getSupported(includeVirtual: false));

        return count($list) >= 1;
    }

    public function getGuids(): array
    {
        return $this->guids;
    }

    public function getPointers(array|null $guids = null): array
    {
        return Guid::fromArray(
            array_intersect_key(
                $this->guids,
                Guid::getSupported(includeVirtual: true)
            ), context: [
            'backend' => $this->via,
            'backend_id' => ag($this->getMetadata($this->via) ?? [], 'id'),
            'item' => [
                'id' => $this->id,
                'type' => $this->type,
                'year' => $this->year,
                'title' => $this->getName()
            ]
        ]
        )->getPointers();
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
        return iFace::TYPE_MOVIE === $this->type;
    }

    public function isEpisode(): bool
    {
        return iFace::TYPE_EPISODE === $this->type;
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

        return array_intersect_key($list, Guid::getSupported(includeVirtual: false));
    }

    public function getRelativePointers(): array
    {
        if (!$this->isEpisode()) {
            return [];
        }

        $list = Guid::fromArray($this->getRelativeGuids(), context: [
            'backend' => $this->via,
            'backend_id' => ag($this->getMetadata($this->via) ?? [], 'id'),
            'item' => [
                'id' => $this->id,
                'type' => $this->type,
                'year' => $this->year,
                'title' => $this->getName()
            ]
        ])->getPointers();

        $rPointers = [];

        foreach ($list as $val) {
            $rPointers[] = 'r' . $val;
        }

        return $rPointers;
    }

    public function apply(iFace $entity, array $fields = []): self
    {
        if (!empty($fields)) {
            foreach ($fields as $key) {
                if (false === in_array($key, iFace::ENTITY_KEYS) || true === $this->isEqualValue($key, $entity)) {
                    continue;
                }
                $this->updateValue($key, $entity);
            }

            return $this;
        }

        foreach (iFace::ENTITY_KEYS as $key) {
            if (true === $this->isEqualValue($key, $entity)) {
                continue;
            }
            $this->updateValue($key, $entity);
        }

        return $this;
    }

    public function updateOriginal(): iFace
    {
        $this->data = $this->getAll();
        return $this;
    }

    public function getOriginalData(): array
    {
        return $this->data;
    }

    public function setIsTainted(bool $isTainted): iFace
    {
        $this->tainted = $isTainted;
        return $this;
    }

    public function isTainted(): bool
    {
        return $this->tainted;
    }

    public function getMetadata(string|null $via = null): array
    {
        if (null === $via) {
            return $this->metadata;
        }

        return $this->metadata[$via] ?? [];
    }

    public function getExtra(string|null $via = null): array
    {
        if (null === $via) {
            return $this->extra;
        }

        return $this->extra[$via] ?? [];
    }

    public function shouldMarkAsUnplayed(iFace $backend): bool
    {
        if (false !== $backend->isWatched() && true === $this->isWatched()) {
            return false;
        }

        $addedAt = ag($this->getMetadata($backend->via), iFace::COLUMN_META_DATA_ADDED_AT);
        $playedAt = ag($this->getMetadata($backend->via), iFace::COLUMN_META_DATA_PLAYED_AT);

        // -- Required columns are not recorded at the backend database. so discontinue.
        if (null === $playedAt || null === $addedAt) {
            return false;
        }

        // -- Recorded added_at at database is not equal to remote updated.
        if ((int)$addedAt !== $backend->updated) {
            return false;
        }

        return true;
    }

    public function markAsUnplayed(iFace $backend): StateInterface
    {
        $this->watched = 0;
        $this->via = $backend->via;
        $this->updated = time();

        return $this;
    }

    private function isEqualValue(string $key, iFace $entity): bool
    {
        if (iFace::COLUMN_UPDATED === $key || iFace::COLUMN_WATCHED === $key) {
            return !($entity->updated > $this->updated && $entity->watched !== $this->watched);
        }

        if (null !== ($entity->{$key} ?? null) && $this->{$key} !== $entity->{$key}) {
            return false;
        }

        return true;
    }

    private function updateValue(string $key, iFace $remote): void
    {
        if (iFace::COLUMN_UPDATED === $key || iFace::COLUMN_WATCHED === $key) {
            // -- Normal logic flow usually this indicates that backend has played_at date column.
            if ($remote->updated > $this->updated && $remote->isWatched() !== $this->isWatched()) {
                $this->updated = $remote->updated;
                $this->watched = $remote->watched;
            }
            return;
        }

        if (iFace::COLUMN_ID === $key) {
            return;
        }

        if (true === in_array($key, iFace::ENTITY_ARRAY_KEYS)) {
            $this->{$key} = array_replace_recursive($this->{$key} ?? [], $remote->{$key} ?? []);
        } else {
            $this->{$key} = $remote->{$key};
        }
    }

    private function arrayDiff(array $oldArray, array $newArray): array
    {
        $difference = [];

        foreach ($newArray as $key => $value) {
            if (false === is_array($value)) {
                if (!array_key_exists($key, $oldArray) || $oldArray[$key] !== $value) {
                    $difference[$key] = [
                        'old' => $oldArray[$key] ?? 'None',
                        'new' => $value ?? 'None',
                    ];
                }
                continue;
            }

            if (!isset($oldArray[$key]) || !is_array($oldArray[$key])) {
                $difference[$key] = [
                    'old' => $oldArray[$key] ?? 'None',
                    'new' => $value ?? 'None',
                ];
            } else {
                $newDiff = $this->arrayDiff($oldArray[$key], $value);
                if (!empty($newDiff)) {
                    $difference[$key] = $newDiff;
                }
            }
        }

        return $difference;
    }

}
