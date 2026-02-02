<?php

declare(strict_types=1);

namespace App\Libs\Entity;

use App\Libs\UserContext;
use Psr\Log\LoggerAwareInterface;

interface StateInterface extends LoggerAwareInterface
{
    public const string TYPE_MOVIE = 'movie';
    public const string TYPE_EPISODE = 'episode';
    public const string TYPE_SHOW = 'show';
    public const string TYPE_MIXED = 'mixed';

    /**
     * @var array list of supported types.
     */
    public const array TYPES_LIST = [
        self::TYPE_MOVIE,
        self::TYPE_SHOW,
        self::TYPE_EPISODE,
    ];

    /**
     * If you must reference field directly, use those constants.
     */
    public const string COLUMN_ID = 'id';
    public const string COLUMN_TYPE = 'type';
    public const string COLUMN_UPDATED = 'updated';
    public const string COLUMN_WATCHED = 'watched';
    public const string COLUMN_VIA = 'via';
    public const string COLUMN_TITLE = 'title';
    public const string COLUMN_YEAR = 'year';
    public const string COLUMN_SEASON = 'season';
    public const string COLUMN_EPISODE = 'episode';
    public const string COLUMN_PARENT = 'parent';
    public const string COLUMN_GUIDS = 'guids';
    public const string COLUMN_META_DATA = 'metadata';
    public const string COLUMN_META_SHOW = 'show';
    public const string COLUMN_META_LIBRARY = 'library';
    public const string COLUMN_META_PATH = 'path';
    public const string COLUMN_META_MULTI = 'multi';
    public const string COLUMN_META_DATA_ADDED_AT = 'added_at';
    public const string COLUMN_META_DATA_PLAYED_AT = 'played_at';
    public const string COLUMN_META_DATA_PROGRESS = 'progress';
    public const string COLUMN_META_DATA_RATING = 'rating';
    public const string COLUMN_META_DATA_EXTRA = 'extra';
    public const string COLUMN_META_DATA_EXTRA_TITLE = 'title';
    public const string COLUMN_META_DATA_EXTRA_OVERVIEW = 'overview';
    public const string COLUMN_META_DATA_EXTRA_FAVORITE = 'favorite';
    public const string COLUMN_META_DATA_EXTRA_GENRES = 'genres';
    public const string COLUMN_META_DATA_EXTRA_DATE = 'date';
    public const string COLUMN_EXTRA = 'extra';
    public const string COLUMN_EXTRA_EVENT = 'event';
    public const string COLUMN_EXTRA_DATE = 'received_at';

    public const string COLUMN_CREATED_AT = 'created_at';
    public const string COLUMN_UPDATED_AT = 'updated_at';

    /**
     * List of table keys.
     */
    public const array ENTITY_KEYS = [
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
        self::COLUMN_CREATED_AT,
        self::COLUMN_UPDATED_AT,
    ];

    /**
     * Ignore listed fields if the played status did not change.
     */
    public const array ENTITY_IGNORE_DIFF_CHANGES = [
        self::COLUMN_VIA,
        self::COLUMN_TITLE,
        self::COLUMN_YEAR,
        self::COLUMN_SEASON,
        self::COLUMN_EPISODE,
        self::COLUMN_EXTRA,
        self::COLUMN_CREATED_AT,
        self::COLUMN_UPDATED_AT,
    ];

    /**
     * Fields that if changed will trigger an update regardless of the watch state or event.
     */
    public const array ENTITY_FORCE_UPDATE_FIELDS = [
        self::COLUMN_PARENT,
        self::COLUMN_GUIDS,
        self::COLUMN_META_DATA,
    ];

    /**
     * List of JSON/array fields.
     */
    public const array ENTITY_ARRAY_KEYS = [
        self::COLUMN_PARENT,
        self::COLUMN_GUIDS,
        self::COLUMN_META_DATA,
        self::COLUMN_EXTRA,
    ];

    /**
     * Make new instance.
     *
     * @param array $data Data to set.
     *
     * @return StateInterface Return new instance.
     */
    public static function fromArray(array $data): self;

    /**
     * Return an array of changed items.
     *
     * @param array $fields if omitted, it will check all fields.
     *
     * @return array Return an array of changed items.
     */
    public function diff(array $fields = []): array;

    /**
     * Get all entity keys.
     *
     * @return array Return an array of all entity keys.
     */
    public function getAll(): array;

    /**
     * Has the entity changed?
     *
     * @param array $fields if omitted, it will check all fields.
     *
     * @return bool Return true if the entity has changed.
     */
    public function isChanged(array $fields = []): bool;

    /**
     * Does the entity have external ids?
     *
     * @return bool Return true if the entity has external ids.
     */
    public function hasGuids(): bool;

    /**
     * Get List of external ids.
     *
     * @return array Return an array of external ids.
     */
    public function getGuids(): array;

    /**
     * Does the entity have relative external ids?
     *
     * @return bool Return true if the entity has relative external ids.
     */
    public function hasRelativeGuid(): bool;

    /**
     * Get Relative GUIDs.
     *
     * @return array Return an array of relative external ids.
     */
    public function getRelativeGuids(): array;

    /**
     * Get relative pointers.
     *
     * @return array Return an array of relative pointers.
     */
    public function getRelativePointers(): array;

    /**
     * Does the entity have parent external ids?
     *
     * @return bool Return true if the entity has parent external ids.
     */
    public function hasParentGuid(): bool;

    /**
     * Get parent external ids.
     *
     * @return array Return an array of parent external ids.
     */
    public function getParentGuids(): array;

    /**
     * Is the entity of movie type?
     *
     * @return bool Return true if the entity is of movie type.
     */
    public function isMovie(): bool;

    /**
     * Is the entity of show type?
     *
     * @return bool Return true if the entity is of show type.
     */
    public function isShow(): bool;

    /**
     * Is the entity of episode type?
     *
     * @return bool Return true if the entity is of episode type.
     */
    public function isEpisode(): bool;

    /**
     * Is entity marked as watched?
     *
     * @return bool Return true if the entity is marked as watched.
     */
    public function isWatched(): bool;

    /**
     * Get constructed name. We Return the following format depending on the type:
     * * For movies  : "Title (Year)"
     * * For Episodes: "Title (Year) - Season(00) x Episode(000)"
     *
     * @param bool $asMovie Return episode title as movie format.
     *
     * @return string Return constructed name.
     */
    public function getName(bool $asMovie = false): string;

    /**
     * Get external ids pointers.
     *
     * @return array Return an array of external ids pointers.
     */
    public function getPointers(): array;

    /**
     * Apply changes to entity.
     *
     * @param StateInterface $entity The entity which contains the changes.
     * @param array $fields if omitted, it will apply all {@see StateInterface::ENTITY_KEYS} fields.
     *
     * @return StateInterface Return the updated entity.
     */
    public function apply(StateInterface $entity, array $fields = []): StateInterface;

    /**
     * Update Original data.
     * Please do not use this unless you know what you are doing
     *
     * @return StateInterface
     * @internal This method is used internally.
     */
    public function updateOriginal(): StateInterface;

    /**
     * Get The Original data.
     *
     * @return array Return the original data.
     */
    public function getOriginalData(): array;

    /**
     * The Tainted flag control whether we will change state or not.
     * If the entity is not already stored in the database, then this flag is not used.
     * However, if the entity already exists and the flag is set to **true**, then
     * we will be checking **external ids** only, and if those differ {@see ENTITY_IGNORE_DIFF_CHANGES} will be updated
     * as well, otherwise, nothing will be changed, this flag serve to update item via webhook unhelpful events like
     * play/stop/resume without alternating the play state.
     *
     * @param bool $isTainted
     *
     * @return StateInterface Return the updated entity.
     */
    public function setIsTainted(bool $isTainted): StateInterface;

    /**
     * Is the entity data tainted?
     *
     * @return bool Return true if the entity data is tainted.
     */
    public function isTainted(): bool;

    /**
     * Get metadata.
     *
     * @param string|null $via if via is omitted, the entire "metadata" will be returned.
     *
     * @return array Return an array of metadata.
     */
    public function getMetadata(?string $via = null): array;

    /**
     * Get metadata.
     *
     * @param string $backend The backend name to remove metadata from.
     *
     * @return array Return the removed metadata. Or empty array if not found.
     */
    public function removeMetadata(string $backend): array;

    /**
     * Set metadata related to {$this->via} backend.
     *
     * @param array $metadata Metadata
     *
     * @return StateInterface Return the updated entity.
     *
     * @throws \RuntimeException if no via is set.
     */
    public function setMetadata(array $metadata): StateInterface;

    /**
     * Set meta data key/value related to a specific backend.
     *
     * @param string $key key
     * @param mixed $value value
     * @param string|null $via if via is omitted, the metadata will be set for the current {@see StateInterface::COLUMN_VIA} backend.
     *
     * @return StateInterface Return the updated entity.
     *
     * @throws \RuntimeException if no via is set.
     */
    public function setMeta(string $key, mixed $value, ?string $via = null): StateInterface;

    /**
     * Get extra.
     *
     * @param string|null $via if via is omitted, the entire "extra" will be returned.
     *
     * @return array
     */
    public function getExtra(?string $via = null): array;

    /**
     * Set Extra related to {$this->via} backend.
     *
     * @param array $extra Extra
     *
     * @return StateInterface Return the updated entity.
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
     * * [1] **entity.watched** field **MUST** must be set as **0** (unplayed).
     * * [2] **db.item.watched** field **MUST** be set as **1** (played).
     * * [3] **db.item.metadata** field **MUST** have pre-existing metadata from the backend that is asking to mark the item as unplayed.
     * * [4] **db.item.metadata.backend** JSON field **MUST** contain **watched**, **id**, **played_at** and **added_at** as keys with values.
     * * [5] **db.item.metadata.backend.watched** field **MUST** be set as **1** (played).
     * * [6] **entity.metadata.backend.id** field **MUST** match **db.item.metadata.backend.id**.
     * * [7] **entity.updated** field **MUST** match **db.item.metadata.backend.added_at**.
     * * [8] **userContext.options.DISABLE_MARK_UNPLAYED** flag **MUST NOT** be set to true.
     *
     * ----------------
     *
     * Ref: **db.item.[]** refers to local db data. **entity.[]** refers to the data being received from backend.
     *
     * @param StateInterface $backend Backend object.
     * @param UserContext|null $userContext User context object.
     *
     * @return bool Return true if all conditions are met.
     */
    public function shouldMarkAsUnplayed(StateInterface $backend, ?UserContext $userContext = null): bool;

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
     * @return StateInterface Return the updated entity.
     */
    public function markAsUnplayed(StateInterface $backend): StateInterface;

    /**
     * Check item if it has play progress.
     * This is used to determine if we should update the progress or not.
     *
     * @return bool Return true if the item has play progress.
     */
    public function hasPlayProgress(): bool;

    /**
     * Get play progress. If the item is watched and/or has no progress, then 0 will be returned. otherwise
     * time in milliseconds will be returned.
     *
     * @return int Return the play progress.
     */
    public function getPlayProgress(): int;

    /**
     * Set entity contextual data.
     *
     * @param string $key key
     * @param mixed $value value
     *
     * @return StateInterface Returns the current object.
     */
    public function setContext(string $key, mixed $value): StateInterface;

    /**
     * Remove entity contextual data.
     *
     * @param string|null $key the key to remove, if null, all context will be removed.
     *
     * @return StateInterface Returns the current object.
     */
    public function removeContext(?string $key = null): StateInterface;

    /**
     * Get entity contextual data.
     *
     * @param string|null $key the key to get, if both key and default are null, the entire context is returned.
     * @param mixed $default default value.
     *
     * @return mixed
     */
    public function getContext(?string $key = null, mixed $default = null): mixed;

    /**
     * Get the metadata that is likely to be correct based on the quorum.
     * To constitute a quorum, 2/3 of the backends must have the same metadata, otherwise fallback to
     * {@see ag($this->getMetadata($this->via), $key, $default)}
     *
     * @param string $key key
     * @param mixed|null $default default value.
     *
     * @return mixed
     */
    public function getMeta(string $key, mixed $default = null): mixed;

    /**
     * Check if entity has contextual data.
     *
     * @param string $key key
     *
     * @return bool Return true if the entity has contextual data related to the key.
     */
    public function hasContext(string $key): bool;

    /**
     *  Check whether the entity play state is synced with the backends.
     *
     * @param array $backends List of backends to check.
     *
     * @return array{string: bool|null} Return true if the entity is synced with the backend. or false if not, or null if the backend has no metadata.
     */
    public function isSynced(array $backends): array;
}
