<?php

declare(strict_types=1);

namespace App\Libs\Entity;

use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\Options;
use App\Libs\UserContext;
use Psr\Log\LoggerAwareTrait;
use RuntimeException;

/**
 * Class StateEntity
 *
 * Represents an metadata as entity.
 *
 * @implements iState
 * @implements LoggerAwareTrait
 */
final class StateEntity implements iState
{
    use LoggerAwareTrait;

    /**
     * @var array $data Holds the original entity data.
     */
    private array $data = [];

    /**
     * @var array $context Holds the context data for the entity.
     */
    private array $context = [];

    /**
     * @var bool $tainted Flag indicating if the data is tainted based on its event type.
     */
    private bool $tainted = false;

    /**
     * @var string|int|null $id The corresponding database id.
     */
    public null|string|int $id = null;

    /**
     * @var string $type What type of data this entity holds.
     */
    public string $type = '';

    /**
     * @var int $updated When was the entity last updated.
     */
    public int $updated = 0;

    /**
     * @var int $watched Whether the entity is watched or not.
     */
    public int $watched = 0;

    /**
     * @var string $via The backend that this entity data belongs to.
     */
    public string $via = '';

    /**
     * @var string $title The title of the entity usually in format of "Movie Title (Year)" if event type is movie. Or "Series Title (Year) - Season x Episode" if event type is episode.
     */
    public string $title = '';

    /**
     * @var int|null $year The year of the entity.
     */
    public ?int $year = null;
    /**
     * @var int|null $season The season number of the episode if event type is episode.
     */
    public ?int $season = null;
    /**
     * @var int|null $episode The episode number of the episode event type is episode.
     */
    public ?int $episode = null;

    /**
     * @var array $parent The parent guids for this entity. Empty if event type is movie.
     */
    public array $parent = [];

    /**
     * @var array $guids The guids for this entity. Empty if event type is episode.
     */
    public array $guids = [];

    /**
     * @var array $metadata holds the metadata from various backends.
     */
    public array $metadata = [];

    /**
     * @var array $extra holds the extra data from various backends.
     */
    public array $extra = [];

    public int $created_at = 0;

    public int $updated_at = 0;

    /**
     * Constructor for the StateEntity class
     *
     * @param array $data The data used to initialize the StateEntity object
     *
     * @throws RuntimeException If an unexpected type is given for the COLUMN_TYPE key
     */
    public function __construct(array $data)
    {
        foreach ($data as $key => $val) {
            if (!in_array($key, iState::ENTITY_KEYS, true)) {
                continue;
            }

            if (iState::COLUMN_TYPE === $key && false === in_array($val, self::TYPES_LIST, true)) {
                throw new RuntimeException(
                    r("StateEntity: Unexpected '{value}' type was given. Expecting '{types_list}'.", context: [
                        'value' => $val,
                        'types_list' => implode(', ', self::TYPES_LIST),
                    ]),
                );
            }

            foreach (iState::ENTITY_ARRAY_KEYS as $subKey) {
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

        if (0 === $this->updated_at && $this->updated > 0) {
            $this->updated_at = $this->updated;
        }

        if (0 === $this->created_at && $this->updated > 0) {
            $this->created_at = $this->updated;
        }

        $this->data = $this->getAll();
    }

    /**
     * @inheritdoc
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * @inheritdoc
     */
    public function diff(array $fields = []): array
    {
        $changed = [];

        $keys = !empty($fields) ? $fields : iState::ENTITY_KEYS;

        foreach ($keys as $key) {
            if ($this->{$key} === ($this->data[$key] ?? null)) {
                continue;
            }

            if (true === in_array($key, iState::ENTITY_ARRAY_KEYS, true)) {
                $changes = $this->arrayDiff($this->data[$key] ?? [], $this->{$key} ?? []);
                if (!empty($changes)) {
                    $changed[$key] = $changes;
                }
            } else {
                $changed[$key] = [
                    'old' => $this->data[$key] ?? 'None',
                    'new' => $this->{$key} ?? 'None',
                ];
            }
        }

        return $changed;
    }

    /**
     * @inheritdoc
     */
    public function getName(bool $asMovie = false): string
    {
        $year = ag($this->data, iState::COLUMN_YEAR, $this->year);
        $title = ag($this->data, iState::COLUMN_TITLE, $this->title);

        if ($this->isMovie() || $this->isShow() || true === $asMovie) {
            return r('{title} ({year})', [
                'title' => !empty($title) ? $title : '??',
                'year' => $year ?? '0000',
            ]);
        }

        $season = str_pad((string) ag($this->data, iState::COLUMN_SEASON, $this->season ?? 0), 2, '0', STR_PAD_LEFT);
        $episode = str_pad((string) ag($this->data, iState::COLUMN_EPISODE, $this->episode ?? 0), 3, '0', STR_PAD_LEFT);

        return r(
            '{title} ({year}) - {season}x{episode}',
            [
                'title' => !empty($title) ? $title : '??',
                'year' => $year ?? '0000',
                'season' => $season,
                'episode' => $episode,
            ],
        );
    }

    /**
     * @inheritdoc
     */
    public function getAll(): array
    {
        return [
            iState::COLUMN_ID => $this->id,
            iState::COLUMN_TYPE => $this->type,
            iState::COLUMN_UPDATED => $this->updated,
            iState::COLUMN_WATCHED => $this->watched,
            iState::COLUMN_VIA => $this->via,
            iState::COLUMN_TITLE => $this->title,
            iState::COLUMN_YEAR => $this->year,
            iState::COLUMN_SEASON => $this->season,
            iState::COLUMN_EPISODE => $this->episode,
            iState::COLUMN_PARENT => $this->parent,
            iState::COLUMN_GUIDS => $this->guids,
            iState::COLUMN_META_DATA => $this->metadata,
            iState::COLUMN_EXTRA => $this->extra,
            iState::COLUMN_CREATED_AT => $this->created_at,
            iState::COLUMN_UPDATED_AT => $this->updated_at,
        ];
    }

    /**
     * @inheritdoc
     */
    public function isChanged(array $fields = []): bool
    {
        return count($this->diff($fields)) >= 1;
    }

    /**
     * @inheritdoc
     */
    public function hasGuids(): bool
    {
        if (iState::TYPE_EPISODE === $this->type && true === (bool) Config::get('guid.disable.episode', false)) {
            return false;
        }

        $list = array_intersect_key($this->guids, Guid::getSupported());

        return count($list) >= 1;
    }

    /**
     * @inheritdoc
     */
    public function getGuids(): array
    {
        if (iState::TYPE_EPISODE === $this->type && true === (bool) Config::get('guid.disable.episode', false)) {
            return [];
        }

        return $this->guids;
    }

    /**
     * @inheritdoc
     */
    public function getPointers(?array $guids = null): array
    {
        if (iState::TYPE_EPISODE === $this->type && true === (bool) Config::get('guid.disable.episode', false)) {
            return [];
        }

        return Guid::fromArray(payload: array_intersect_key($this->guids, Guid::getSupported()), context: [
            'backend' => $this->via,
            'backend_id' => ag($this->getMetadata($this->via), iState::COLUMN_ID),
            'item' => [
                iState::COLUMN_ID => $this->id,
                iState::COLUMN_TYPE => $this->type,
                iState::COLUMN_YEAR => $this->year,
                iState::COLUMN_TITLE => $this->getName(),
            ],
        ])->getPointers();
    }

    /**
     * @inheritdoc
     */
    public function hasParentGuid(): bool
    {
        $list = array_intersect_key($this->parent, Guid::getSupported());
        return count($list) >= 1;
    }

    /**
     * @inheritdoc
     */
    public function getParentGuids(): array
    {
        return $this->parent;
    }

    /**
     * @inheritdoc
     */
    public function isMovie(): bool
    {
        return iState::TYPE_MOVIE === $this->type;
    }

    /**
     * @inheritdoc
     */
    public function isShow(): bool
    {
        return iState::TYPE_SHOW === $this->type;
    }

    /**
     * @inheritdoc
     */
    public function isEpisode(): bool
    {
        return iState::TYPE_EPISODE === $this->type;
    }

    /**
     * @inheritdoc
     */
    public function isWatched(): bool
    {
        return 1 === $this->watched;
    }

    /**
     * @inheritdoc
     */
    public function hasRelativeGuid(): bool
    {
        return $this->isEpisode() && !empty($this->parent) && null !== $this->season && null !== $this->episode;
    }

    /**
     * @inheritdoc
     */
    public function getRelativeGuids(): array
    {
        if (!$this->isEpisode()) {
            return [];
        }

        $list = array_map(fn($val) => $val . '/' . $this->season . '/' . $this->episode, $this->parent);

        return array_intersect_key($list, Guid::getSupported());
    }

    /**
     * @inheritdoc
     */
    public function getRelativePointers(): array
    {
        if (!$this->isEpisode()) {
            return [];
        }

        $list = Guid::fromArray(payload: $this->getRelativeGuids(), context: [
            'backend' => $this->via,
            'backend_id' => ag($this->getMetadata($this->via), iState::COLUMN_ID),
            'item' => [
                iState::COLUMN_ID => $this->id,
                iState::COLUMN_TYPE => $this->type,
                iState::COLUMN_YEAR => $this->year,
                iState::COLUMN_TITLE => $this->getName(),
            ],
        ])->getPointers();

        $rPointers = [];

        foreach ($list as $val) {
            $rPointers[] = 'r' . $val;
        }

        return $rPointers;
    }

    /**
     * @inheritdoc
     */
    public function apply(iState $entity, array $fields = []): self
    {
        if (!empty($fields)) {
            foreach ($fields as $key) {
                if (
                    false === in_array($key, iState::ENTITY_KEYS, true)
                    || true === $this->isEqualValue(
                        $key,
                        $entity,
                    )
                ) {
                    continue;
                }
                $this->updateValue($key, $entity);
            }

            return $this;
        }

        foreach (iState::ENTITY_KEYS as $key) {
            if (true === $this->isEqualValue($key, $entity)) {
                continue;
            }
            $this->updateValue($key, $entity);
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function updateOriginal(): iState
    {
        $this->data = $this->getAll();
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getOriginalData(): array
    {
        return $this->data;
    }

    /**
     * @inheritdoc
     */
    public function setIsTainted(bool $isTainted): iState
    {
        $this->tainted = $isTainted;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function isTainted(): bool
    {
        return $this->tainted;
    }

    /**
     * @inheritdoc
     */
    public function getMetadata(?string $via = null): array
    {
        if (null === $via) {
            return $this->metadata;
        }

        return $this->metadata[$via] ?? [];
    }

    /**
     * @inheritdoc
     */
    public function removeMetadata(string $backend): array
    {
        if (null === ($this->metadata[$backend] ?? null)) {
            return [];
        }

        $metadata = $this->metadata[$backend];

        unset($this->metadata[$backend]);

        return $metadata;
    }

    /**
     * @inheritdoc
     */
    public function setMetadata(array $metadata): StateInterface
    {
        if (empty($this->via)) {
            throw new RuntimeException('StateEntity: No backend was set in $this->via parameter.');
        }

        $this->metadata[$this->via] = empty($metadata)
            ? []
            : array_replace_recursive(
                $this->metadata[$this->via],
                $metadata,
            );

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setMeta(string $key, mixed $value, ?string $via = null): StateInterface
    {
        if (null === $via && empty($this->via)) {
            throw new RuntimeException('StateEntity: No $via and no $this->via backend was set.');
        }
        $via ??= $this->via;

        $this->metadata = ag_set($this->metadata, "{$via}.{$key}", $value);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getExtra(?string $via = null): array
    {
        if (null === $via) {
            return $this->extra;
        }

        return $this->extra[$via] ?? [];
    }

    /**
     * @inheritdoc
     */
    public function setExtra(array $extra): StateInterface
    {
        if (empty($this->via)) {
            throw new RuntimeException('StateEntity: No backend was set in $this->via parameter.');
        }

        $this->extra[$this->via] = empty($extra)
            ? []
            : array_replace_recursive(
                $this->extra[$this->via],
                $extra,
            );

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function shouldMarkAsUnplayed(iState $backend, ?UserContext $userContext = null): bool
    {
        // -- Condition: 1 & 2
        if (false !== $backend->isWatched() && true === $this->isWatched()) {
            return false;
        }

        $metadata = $this->getMetadata($backend->via);

        // -- Condition: 3
        if (count($metadata) < 1) {
            return false;
        }

        $itemId = ag($metadata, iState::COLUMN_ID);
        $watched = ag($metadata, iState::COLUMN_WATCHED);
        $addedAt = ag($metadata, iState::COLUMN_META_DATA_ADDED_AT);
        $playedAt = ag($metadata, iState::COLUMN_META_DATA_PLAYED_AT);

        // -- Condition: 4
        if (null === $playedAt || null === $addedAt || null === $itemId || null === $watched) {
            return false;
        }

        // -- Condition: 5
        if (1 !== (int) $watched) {
            return false;
        }

        // -- Condition: 6
        if ($itemId !== ag($backend->getMetadata($backend->via), iState::COLUMN_ID)) {
            return false;
        }

        // -- Condition: 7
        if ((int) $addedAt !== $backend->updated) {
            return false;
        }

        // -- Condition: 8
        $key = "{$backend->via}.options." . Options::DISABLE_MARK_UNPLAYED;
        if ($userContext && true === $userContext->get($key, false)) {
            return false;
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function markAsUnplayed(iState $backend): StateInterface
    {
        $this->watched = 0;
        $this->via = $backend->via;
        $this->updated = time();

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function hasPlayProgress(): bool
    {
        $allowUpdate = (int) Config::get('progress.threshold', 0);
        $minimumProgress = (int) Config::get('progress.minimum', 60000);

        if ($this->isWatched() && $allowUpdate < 1) {
            return false;
        }

        foreach ($this->getMetadata() as $metadata) {
            if (0 !== (int) ag($metadata, iState::COLUMN_WATCHED, 0) && $allowUpdate < 1) {
                continue;
            }
            if ((int) ag($metadata, iState::COLUMN_META_DATA_PROGRESS, 0) > $minimumProgress) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritdoc
     */
    public function getPlayProgress(): int
    {
        $allowUpdate = (int) Config::get('progress.threshold', 0);
        if ($this->isWatched() && $allowUpdate < 1) {
            return 0;
        }

        $compare = [];
        $minimumProgress = (int) Config::get('progress.minimum', 60000);

        foreach ($this->getMetadata() as $backend => $metadata) {
            if (0 !== (int) ag($metadata, iState::COLUMN_WATCHED, 0) && $allowUpdate < 1) {
                continue;
            }
            if ((int) ag($metadata, iState::COLUMN_META_DATA_PROGRESS, 0) < $minimumProgress) {
                continue;
            }

            $compare[$backend] = [
                'progress' => (int) ag($metadata, iState::COLUMN_META_DATA_PROGRESS, 0),
                'datetime' => ag($this->getExtra($backend), iState::COLUMN_EXTRA_DATE, 0),
            ];
        }

        $lastProgress = 0;
        $lastDate = make_date($this->updated - 1);

        foreach ($compare as $data) {
            if (null === ($progress = ag($data, 'progress', null))) {
                continue;
            }
            if (null === ($datetime = ag($data, 'datetime', null))) {
                continue;
            }

            if ($progress < $minimumProgress || $lastDate->getTimestamp() > make_date($datetime)->getTimestamp()) {
                continue;
            }

            $lastDate = make_date($datetime);
            $lastProgress = $progress;
        }

        return $lastProgress;
    }

    /**
     * @inheritdoc
     */
    public function setContext(string $key, mixed $value): iState
    {
        $this->context = ag_set($this->context, $key, $value);
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getContext(?string $key = null, mixed $default = null): mixed
    {
        if (null === $key) {
            return $default ?? $this->context;
        }

        return ag($this->context, $key, $default);
    }

    /**
     * @inheritdoc
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        if (empty($this->via)) {
            $this->logger?->warning('StateEntity: No backend was set in $this->via parameter.');
            return $default;
        }

        $values = [];
        $total = count($this->metadata);
        $quorum = round($total / 2, 0, PHP_ROUND_HALF_UP);

        if ($quorum < 2) {
            $this->logger?->warning("StateEntity: Quorum is less than 2. '{quorum}' Using default value.", [
                'quorum' => $quorum,
            ]);
            return ag($this->metadata[$this->via], $key, $default);
        }

        foreach ($this->metadata as $data) {
            if (null === ($value = ag($data, $key, null))) {
                continue;
            }

            $values[$value] = isset($values[$value]) ? $values[$value] + 1 : 1;
        }

        foreach ($values as $value => $count) {
            if ($count < $quorum) {
                continue;
            }

            $this->logger?->info('StateEntity: quorum found. Using value from {value}.', ['value' => $value]);
            return $value;
        }

        $this->logger?->warning('StateEntity: no quorum found. Using default value.');
        return ag($this->metadata[$this->via], $key, $default);
    }

    /**
     * @inheritdoc
     */
    public function hasContext(string $key): bool
    {
        return ag_exists($this->context, $key);
    }

    /**
     * @inheritdoc
     */
    public function removeContext(?string $key = null): StateInterface
    {
        $this->context = $key ? ag_delete($this->context, $key) : [];
        return $this;
    }

    public function isSynced(array $backends): array
    {
        $match = [];

        foreach ($backends as $backend) {
            if (null === ag($this->metadata, $backend)) {
                $match[$backend] = null;
                continue;
            }
            $match[$backend] = $this->isWatched() === (bool) ag($this->metadata[$backend], iState::COLUMN_WATCHED, 0);
        }

        return $match;
    }

    /**
     * Checks if the value of a given key in the entity object is equal to the corresponding value in the current object.
     * Some keys are special and require special logic to compare. For example, the updated and watched keys are special
     * because they are tied together.
     *
     * @param string $key The key to check in the entity object.
     * @param iState $entity The entity object to compare the key value with.
     *
     * @return bool Returns true if the value of the key in the entity object is equal to the value in the current object,
     *              otherwise returns false.
     */
    private function isEqualValue(string $key, iState $entity): bool
    {
        if (iState::COLUMN_UPDATED === $key || iState::COLUMN_WATCHED === $key) {
            return !($entity->updated > $this->updated && $entity->watched !== $this->watched);
        }

        if (null !== ($entity->{$key} ?? null) && $this->{$key} !== $entity->{$key}) {
            return false;
        }

        return true;
    }

    /**
     * Updates the value of a given key in the current object with the corresponding value from the remote object.
     * The method follows certain logic for specific keys such as "updated" and "watched". For these keys, if the remote
     * object has a greater "updated" value and a different "watched" value compared to the current object, the values in
     * the current object are updated with the values from the remote object. If the key is an array column the method uses
     * the recursive replacement to update the value of the key in the current object with the value from the remote
     * object. Otherwise, it simply assigns the value of the key from the remote object to the current object.
     *
     * @param string $key The key to update in the current object.
     * @param iState $remote The remote object to get the updated value from.
     */
    private function updateValue(string $key, iState $remote): void
    {
        if (iState::COLUMN_UPDATED === $key || iState::COLUMN_WATCHED === $key) {
            // -- Normal logic flow usually this indicates that backend has played_at date column.
            if ($remote->updated > $this->updated && $remote->isWatched() !== $this->isWatched()) {
                $this->updated = $remote->updated;
                $this->watched = $remote->watched;
            }
            return;
        }

        if (iState::COLUMN_ID === $key) {
            return;
        }

        if (true === in_array($key, iState::ENTITY_ARRAY_KEYS, true)) {
            $this->{$key} = array_replace_recursive($this->{$key} ?? [], $remote->{$key} ?? []);
        } else {
            $this->{$key} = $remote->{$key};
        }
    }

    /**
     * Calculates the difference between two arrays by comparing their values recursively.
     *
     * @param array $oldArray The original array to compare.
     * @param array $newArray The new array to compare.
     *
     * @return array Returns an associative array that contains the differences between the two arrays. The keys are the
     *               differing elements from the new array, and the values are arrays that contain the old and new values
     *               for each differing element.
     */
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
