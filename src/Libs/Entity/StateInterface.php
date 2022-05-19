<?php

declare(strict_types=1);

namespace App\Libs\Entity;

interface StateInterface
{
    public const TYPE_MOVIE = 'movie';
    public const TYPE_EPISODE = 'episode';

    /**
     * If you must reference field directly, use those constants.
     */
    public const COLUMN_ID = 'id';
    public const COLUMN_TYPE = 'type';
    public const COLUMN_UPDATED = 'updated';
    public const COLUMN_WATCHED = 'watched';
    public const COLUMN_VIA = 'via';
    public const COLUMN_TITLE = 'title';
    public const COLUMN_YEAR = 'year';
    public const COLUMN_SEASON = 'season';
    public const COLUMN_EPISODE = 'episode';
    public const COLUMN_PARENT = 'parent';
    public const COLUMN_GUIDS = 'guids';
    public const COLUMN_META_DATA = 'metadata';
    public const COLUMN_META_DATA_EXTRA = 'extra';
    public const COLUMN_META_DATA_EXTRA_TITLE = 'title';
    public const COLUMN_META_DATA_EXTRA_DATE = 'date';
    public const COLUMN_META_DATA_EXTRA_EVENT = 'event';
    public const COLUMN_META_DATA_PAYLOAD = 'payload';
    public const COLUMN_EXTRA = 'extra';

    /**
     * List of table keys.
     */
    public const ENTITY_KEYS = [
        self::COLUMN_ID,
        self::COLUMN_TYPE,
        self::COLUMN_UPDATED,
        self::COLUMN_WATCHED,
        self::COLUMN_VIA,
        self::COLUMN_TITLE,
        self::COLUMN_YEAR,
        self::COLUMN_SEASON,
        self::COLUMN_EPISODE,
        self::COLUMN_PARENT,
        self::COLUMN_GUIDS,
        self::COLUMN_META_DATA,
        self::COLUMN_EXTRA,
    ];

    /**
     * Ignore listed fields if the played status did not change.
     */
    public const ENTITY_IGNORE_DIFF_CHANGES = [
        self::COLUMN_VIA,
        self::COLUMN_TITLE,
        self::COLUMN_YEAR,
        self::COLUMN_META_DATA,
        self::COLUMN_EXTRA,
    ];

    /**
     * Fields that if changed will trigger an update regardless of the watch state or event.
     */
    public const ENTITY_FORCE_UPDATE_FIELDS = [
        self::COLUMN_PARENT,
        self::COLUMN_GUIDS,
        self::COLUMN_META_DATA,
    ];

    /**
     * List of JSON/array fields.
     */
    public const ENTITY_ARRAY_KEYS = [
        self::COLUMN_PARENT,
        self::COLUMN_GUIDS,
        self::COLUMN_META_DATA,
        self::COLUMN_EXTRA,
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
     * Does the entity have external ids?
     *
     * @return bool
     */
    public function hasGuids(): bool;

    /**
     * Get List of external ids.
     *
     * @return array
     */
    public function getGuids(): array;

    /**
     * Does the entity have relative external ids?
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
     * Does the Entity have Parent external ids?
     *
     * @return bool
     */
    public function hasParentGuid(): bool;

    /**
     * Get Parent external ids.
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
     * Is entity marked as watched?
     *
     * @return bool
     */
    public function isWatched(): bool;

    /**
     * Get constructed name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get external ids Pointers.
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
     * we will be checking **external ids** only, and if those differ {@see ENTITY_IGNORE_DIFF_CHANGES} will be updated
     * as well, otherwise, nothing will be changed, This flag serve to update GUIDs via webhook unhelpful events like
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
