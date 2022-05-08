<?php

declare(strict_types=1);

namespace App\Libs\Entity;

interface StateInterface
{
    public const TYPE_MOVIE = 'movie';
    public const TYPE_EPISODE = 'episode';

    public const ENTITY_IGNORE_DIFF_CHANGES = [
        'via',
        'extra',
        'title',
        'year',
    ];

    public const ENTITY_ARRAY_KEYS = [
        'parent',
        'guids',
        'extra'
    ];

    public const ENTITY_KEYS = [
        'id',
        'type',
        'updated',
        'watched',
        'via',
        'title',
        'year',
        'season',
        'episode',
        'parent',
        'guids',
        'extra',
    ];

    /**
     * Make new instance.
     *
     * @param array $data
     *
     * @return StateInterface
     */
    public static function fromArray(array $data): self;

    /**
     * Return An array of changed items.
     *
     * @param bool $all check all keys. including ignored keys.
     *
     * @return array
     */
    public function diff(bool $all = false): array;

    /**
     * Get All Entity keys.
     *
     * @return array
     */
    public function getAll(): array;

    /**
     * Has the entity changed?
     *
     * @return bool
     */
    public function isChanged(): bool;

    /**
     * Does the entity have GUIDs?
     *
     * @return bool
     */
    public function hasGuids(): bool;

    /**
     * Does the entity have Relative GUIDs?
     *
     * @return bool
     */
    public function hasRelativeGuid(): bool;

    /**
     * Get Relative GUIDs.
     *
     * @return array
     */
    public function getRelativeGuids(): array;

    /**
     * Get Relative Pointers.
     *
     * @return array
     */
    public function getRelativePointers(): array;

    /**
     * Does the Entity have Parent IDs?
     *
     * @return bool
     */
    public function hasParentGuid(): bool;

    /**
     * Get Parent GUIDs.
     *
     * @return array
     */
    public function getParentGuids(): array;

    /**
     * Is the entity of movie type?
     *
     * @return bool
     */
    public function isMovie(): bool;

    /**
     * Is the entity of episode type?
     *
     * @return bool
     */
    public function isEpisode(): bool;

    /**
     * Get constructed name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get GUID Pointers.
     *
     * @return array
     */
    public function getPointers(): array;

    /**
     * Apply changes to entity.
     *
     * @param StateInterface $entity
     * @param bool $guidOnly
     *
     * @return StateInterface
     */
    public function apply(StateInterface $entity, bool $guidOnly = false): StateInterface;

    /**
     * Update Original data.
     *
     * @return StateInterface
     */
    public function updateOriginal(): StateInterface;

    /**
     * Get The Original data.
     *
     * @return array
     */
    public function getOriginalData(): array;

    /**
     * The Tainted flag control whether we will change state or not.
     * If the entity is not already stored in the database, then this flag is not used.
     * However, if the entity already exists and the flag is set to **true**, then
     * we will be checking **GUIDs** only, and if those differ then meta will be updated as well.
     * otherwise, nothing will be changed, This flag serve to update GUIDs via webhook unhelpful events like
     * play/stop/resume.
     *
     * @param bool $isTainted
     *
     * @return StateInterface
     */
    public function setIsTainted(bool $isTainted): StateInterface;

    /**
     * Whether the play state is tainted.
     *
     * @return bool
     */
    public function isTainted(): bool;
}
