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
    public const COLUMN_META_DATA_ADDED_AT = 'added_at';
    public const COLUMN_META_DATA_PLAYED_AT = 'played_at';
    public const COLUMN_META_DATA_EXTRA = 'extra';
    public const COLUMN_META_DATA_EXTRA_TITLE = 'title';
    public const COLUMN_META_DATA_EXTRA_DATE = 'date';
    public const COLUMN_EXTRA = 'extra';
    public const COLUMN_EXTRA_EVENT = 'event';
    public const COLUMN_EXTRA_DATE = 'received_at';

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
     * @param bool $metadataOnly
     *
     * @return StateInterface
     */
    public function apply(StateInterface $entity, bool $metadataOnly = false): StateInterface;

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

    /**
     * Get Metadata
     *
     * @param string|null $via if via is omitted, the entire "metadata" will be returned.
     *
     * @return array
     */
    public function getMetadata(string|null $via = null): array;

    /**
     * Get extra.
     *
     * @param string|null $via if via is omitted, the entire "extra" will be returned.
     *
     * @return array
     */
    public function getExtra(string|null $via = null): array;

    /**
     * Should we mark the media as unplayed?<br><br>
     * The Logic flows as the following:<br>
     *
     * Is the remote item marked as unplayed? if so get the recorded metadata that relates to the backend.
     * Does that metadata contains {@see iFace::COLUMN_META_DATA_ADDED_AT} and {@see iFace::COLUMN_META_DATA_PLAYED_AT} ?
     * since media backends when marking media items as unplayed they remove {@see iFace::COLUMN_META_DATA_PLAYED_AT}
     * field from response, so our logic handler for {@see iFace::COLUMN_UPDATED} fall back to
     * {@see iFace::COLUMN_META_DATA_ADDED_AT}.<br><br>
     *
     * so to mark items as unplayed the following conditions **MUST** be met<br><br>
     *
     * 1- Backend item **MUST** be marked as unplayed.<br>
     * 2- Database metadata **MUST** contain {@see iFace::COLUMN_META_DATA_PLAYED_AT} and {@see iFace::COLUMN_META_DATA_ADDED_AT} columns.<br>
     * 3- backend {@see iFace::COLUMN_UPDATED} **MUST** be equal to database metadata {@see iFace::COLUMN_META_DATA_ADDED_AT}<br><br>
     *
     * @param StateInterface $remote
     *
     * @return bool
     */
    public function shouldMarkAsUnplayed(StateInterface $remote): bool;

    /**
     * Mark item as unplayed.<br><br>
     * If all conditions described at {@see StateInterface::shouldMarkAsUnplayed()} are met, then these actions **MUST** be taken:<br><br>
     *
     * 1- Manually set the watch property to false.<br>
     * 2- Remove {@see iFace::COLUMN_META_DATA_PLAYED_AT}.<br>
     * 3- Manually set {@see iFace::COLUMN_UPDATED} to current time.<br>
     *
     * @param StateInterface $remote the backend object.
     *
     * @return StateInterface
     */
    public function markAsUnplayed(StateInterface $remote): StateInterface;
}
