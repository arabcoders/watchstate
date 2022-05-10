<?php

declare(strict_types=1);

namespace App\Libs\Entity;

use App\Libs\Guid;
use RuntimeException;

final class StateEntity implements StateInterface
{
    private array $data = [];
    private bool $tainted = false;

    /**
     * User Addressable Variables.
     */
    public null|string|int $id = null;
    public string $type = '';
    public int $updated = 0;
    public int $watched = 0;
    public array $meta = [];
    public string|null $guid_plex = null;
    public string|null $guid_imdb = null;
    public string|null $guid_tvdb = null;
    public string|null $guid_tmdb = null;
    public string|null $guid_tvmaze = null;
    public string|null $guid_tvrage = null;
    public string|null $guid_anidb = null;

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

            if ('meta' === $key && is_string($val)) {
                if (null === ($val = json_decode($val, true))) {
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

    public function diff(): array
    {
        $changed = [];

        foreach ($this->getAll() as $key => $value) {
            /**
             * We ignore meta on purpose as it changes frequently.
             * from one server to another.
             */
            if ('meta' === $key && !$this->isEpisode()) {
                continue;
            }

            if ('meta' === $key && ($value['parent'] ?? []) === ($this->data['parent'] ?? [])) {
                continue;
            }

            if ($value === ($this->data[$key] ?? null)) {
                continue;
            }

            if ('meta' === $key) {
                $getChanged = array_diff_assoc_recursive($this->data['meta'] ?? [], $this->meta);

                foreach ($getChanged as $metaKey => $_) {
                    $changed['new'][$key][$metaKey] = $this->meta[$metaKey] ?? 'None';
                    $changed['old'][$key][$metaKey] = $this->data[$key][$metaKey] ?? 'None';
                }
            } else {
                $changed['new'][$key] = $value ?? 'None';
                $changed['old'][$key] = $this->data[$key] ?? 'None';
            }
        }

        if (!empty($changed) && !array_key_exists('meta', $changed['new'] ?? $changed['old'] ?? [])) {
            $getChanged = array_diff_assoc_recursive($this->data['meta'] ?? [], $this->meta);

            foreach ($getChanged as $key => $_) {
                $changed['new']['meta'][$key] = $this->meta[$key] ?? 'None';
                $changed['old']['meta'][$key] = $this->data['meta'][$key] ?? 'None';
            }
        }

        return $changed;
    }

    public function getName(): string
    {
        if ($this->isMovie()) {
            return sprintf(
                '%s (%d) - @%s',
                $this->meta['title'] ?? $this->data['meta']['title'] ?? '??',
                $this->meta['year'] ?? $this->data['meta']['year'] ?? '??',
                $this->meta['via'] ?? $this->data['meta']['via'] ?? '??',
            );
        }

        return sprintf(
            '%s (%d) - %dx%d - @%s',
            $this->meta['series'] ?? $this->data['meta']['series'] ?? '??',
            $this->meta['year'] ?? $this->data['meta']['year'] ?? '??',
            $this->meta['season'] ?? $this->data['meta']['season'] ?? 00,
            $this->meta['episode'] ?? $this->data['meta']['episode'] ?? 00,
            $this->meta['via'] ?? $this->data['meta']['via'] ?? '??',
        );
    }

    public function getAll(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'updated' => $this->updated,
            'watched' => $this->watched,
            'meta' => $this->meta,
            'guid_plex' => $this->guid_plex,
            'guid_imdb' => $this->guid_imdb,
            'guid_tvdb' => $this->guid_tvdb,
            'guid_tmdb' => $this->guid_tmdb,
            'guid_tvmaze' => $this->guid_tvmaze,
            'guid_tvrage' => $this->guid_tvrage,
            'guid_anidb' => $this->guid_anidb,
        ];
    }

    public function isChanged(): bool
    {
        return count($this->diff()) >= 1;
    }

    public function hasGuids(): bool
    {
        foreach (array_keys(Guid::SUPPORTED) as $key) {
            if (null !== $this->{$key}) {
                return true;
            }
        }

        return false;
    }

    public function hasParentGuid(): bool
    {
        return count($this->getParentGuids()) >= 1;
    }

    public function getParentGuids(): array
    {
        return (array)ag($this->meta, 'parent', []);
    }

    public function isMovie(): bool
    {
        return StateInterface::TYPE_MOVIE === $this->type;
    }

    public function isEpisode(): bool
    {
        return StateInterface::TYPE_EPISODE === $this->type;
    }

    public function hasRelativeGuid(): bool
    {
        $parents = ag($this->meta, 'parent', []);
        $season = ag($this->meta, 'season', null);
        $episode = ag($this->meta, 'episode', null);

        return !(null === $season || null === $episode || 0 === $episode || empty($parents));
    }

    public function getRelativeGuids(): array
    {
        $parents = ag($this->meta, 'parent', []);
        $season = ag($this->meta, 'season', null);
        $episode = ag($this->meta, 'episode', null);

        if (null === $season || null === $episode || 0 === $episode || empty($parents)) {
            return [];
        }

        $list = [];

        foreach ($parents as $key => $val) {
            $list[$key] = $val . '/' . $season . '/' . $episode;
        }

        return array_intersect_key($list, Guid::SUPPORTED);
    }

    public function getRelativePointers(): array
    {
        return Guid::fromArray($this->getRelativeGuids())->getPointers();
    }

    public function apply(StateInterface $entity, bool $guidOnly = false): self
    {
        if ($this->isEqual($entity)) {
            return $this;
        }

        foreach ($entity->getAll() as $key => $val) {
            if (true === $guidOnly && !str_starts_with($key, 'guid_')) {
                continue;
            }

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

    public function getPointers(): array
    {
        return Guid::fromArray(array_intersect_key((array)$this, Guid::SUPPORTED))->getPointers();
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
        if ($key === 'updated' || $key === 'watched') {
            return !($entity->updated > $this->updated && $entity->watched !== $this->watched);
        }

        if (null !== ($entity->{$key} ?? null) && $this->{$key} !== $entity->{$key}) {
            return false;
        }

        return true;
    }

    private function updateValue(string $key, StateInterface $entity): void
    {
        if ($key === 'updated' || $key === 'watched') {
            if ($entity->updated > $this->updated && $entity->watched !== $this->watched) {
                $this->updated = $entity->updated;
                $this->watched = $entity->watched;
            }
            return;
        }

        if (null !== ($entity->{$key} ?? null) && $this->{$key} !== $entity->{$key}) {
            if ('meta' === $key) {
                $this->{$key} = array_replace_recursive($this->{$key} ?? [], $entity->{$key} ?? []);
            } else {
                $this->{$key} = $entity->{$key};
            }
        }
    }
}
