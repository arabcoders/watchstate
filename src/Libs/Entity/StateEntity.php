<?php

declare(strict_types=1);

namespace App\Libs\Entity;

use RuntimeException;

final class StateEntity implements StateInterface
{
    private array $data = [];

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
            if ('meta' === $key) {
                continue;
            }

            if ($value === ($this->data[$key] ?? null)) {
                continue;
            }

            $changed['new'][$key] = $value ?? 'None';
            $changed['old'][$key] = $this->data[$key] ?? 'None';
        }

        return $changed;
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
        foreach (StateInterface::ENTITY_GUIDS as $key) {
            if (null !== $this->{$key}) {
                return true;
            }
        }

        return false;
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
            $this->{$key} = $entity->{$key};
        }
    }
}
