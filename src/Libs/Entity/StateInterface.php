<?php

declare(strict_types=1);

namespace App\Libs\Entity;

use Psr\Log\LoggerAwareInterface;

interface StateInterface extends LoggerAwareInterface
{
    public const TYPE_MOVIE = 'movie';
    public const TYPE_EPISODE = 'episode';
    public const TYPE_SHOW = 'show';

    public const TYPES_LIST = [
        self::TYPE_MOVIE,
        self::TYPE_SHOW,
        self::TYPE_EPISODE,
    ];

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
    public const COLUMN_META_SHOW = 'show';
    public const COLUMN_META_LIBRARY = 'library';
    public const COLUMN_META_PATH = 'path';
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
        self::COLUMN_SEASON,
        self::COLUMN_EPISODE,
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
     * @param array $fields if omitted, will check all fields.
     *
     * @return array
     */
    public function diff(array $fields = []): array;

    /**
     * Get All Entity keys.
     *
     * @return array
     */
    public function getAll(): array;

    /**
     * Has the entity changed?
     *
     * @param array $fields
     * @return bool
     */
    public function isChanged(array $fields = []): bool;

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
     * @param bool $asMovie Return episode title as movie format.
     *
     * @return string
     */
    public function getName(bool $asMovie = false): string;

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
     * @param array $fields if omitted, it will apply all {@see StateInterface::ENTITY_KEYS} fields.
     *
     * @return StateInterface
     */
    public function apply(StateInterface $entity, array $fields = []): StateInterface;

    /**
     * Update Original data. Please do not use this unless you know what you are doing
     *
     * @return StateInterface
     * @internal
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
     * Set Metadata related to {$this->via} backend
     *
     * @param array $metadata metadata
     *
     * @return StateInterface
     *
     * @throws \RuntimeException if no via is set.
     */
    public function setMetadata(array $metadata): StateInterface;

    /**
     * Get extra.
     *
     * @param string|null $via if via is omitted, the entire "extra" will be returned.
     *
     * @return array
     */
    public function getExtra(string|null $via = null): array;

    /**
     * Set Extra related to {$this->via} backend
     *
     * @param array $extra Extra
     *
     * @return StateInterface
     *
     * @throws \RuntimeException if no via is set.
     */
    public function setExtra(array $extra): StateInterface;

    /**
     * Should we mark the item as unplayed?
     *
     * Since media backends when marking item as unplayed they remove lastPlayedDate from response,
     * we have to compare the **entity.updated** field against **db.item.metadata.backend.added_at** field.<br><br>
     *
     * To mark items as unplayed the following conditions **MUST** be met:
     *
     * ----------------
     *
     * * [1] **entity.watched** field **MUST** must be marked as unplayed.
     * * [2] **db.item.watched** field **MUST** be set as played.
     * * [3] **db.item.metadata** field **MUST** have pre-existing metadata from that backend.
     * * [4] **db.item.metadata.backend** JSON field **MUST** contain **watched**, **id**, **played_at** and **added_at** as keys with values.
     * * [5] **db.item.metadata.backend.watched** field **MUST** be set as played.
     * * [6] **entity.metadata.backend.id** field **MUST** match **db.item.metadata.backend.id**.
     * * [7] **entity.updated** field **MUST** match **db.item.metadata.backend.added_at**.
     *
     * ----------------
     *
     * Ref: **.db.item.[]** refers to local db data. **entity.[]** refers to the data being received from backend.
     *
     * @param StateInterface $backend Backend object.
     *
     * @return bool
     */
    public function shouldMarkAsUnplayed(StateInterface $backend): bool;

    /**
     * Mark item as unplayed.<br><br>
     * If all conditions described at {@see StateInterface::shouldMarkAsUnplayed()} are met, then these actions **MUST** be taken:<br><br>
     *
     * 1- Manually set the watch property to false.<br>
     * 2- Manually set the via property.<br>
     * 3- Remove {@see iFace::COLUMN_META_DATA_PLAYED_AT}.<br>
     * 4- Manually set {@see iFace::COLUMN_UPDATED} to current time.<br>
     *
     * @param StateInterface $backend Backend object.
     *
     * @return StateInterface
     */
    public function markAsUnplayed(StateInterface $backend): StateInterface;
}
