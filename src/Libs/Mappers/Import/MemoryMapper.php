<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Config;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\UserContext;
use App\Listeners\ProcessProgressEvent;
use App\Model\Events\EventsTable;
use DateTimeInterface as iDate;
use PDOException;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;

/**
 * MemoryMapper Class
 *
 * This class is the default import mapper, it uses memory to store the entities. until they are committed.
 * This leads to faster processing and less database calls overall in exchange for higher memory usage.
 *
 * @implements ImportInterface
 *
 * @DEPRECATED Please use {@see DirectMapper}, This class now only exists to support {@see ReadOnlyMapper} for the
 * time being. It will be merged into {@see ReadOnlyMapper} in the future.
 */
class MemoryMapper implements ImportInterface
{
    /**
     * @var string Local database GUID prefix.
     */
    protected const string GUID = 'local_db://';

    /**
     * @var array<int,iState> In memory entities list.
     */
    protected array $objects = [];

    /**
     * @var array<string,int> Map entity pointers to object pointers.
     */
    protected array $pointers = [];

    /**
     * @var array<int,int> Stores the pointers for the entities which has changed.
     */
    protected array $changed = [];

    /**
     * @var array<int,iState> List of items with play progress.
     */
    protected array $progressItems = [];

    /**
     * @var array<int, mixed> Mapper options.
     */
    protected array $options = [];

    protected bool $fullyLoaded = false;

    /**
     * @var UserContext|null The User Context
     */
    protected ?UserContext $userContext = null;

    /**
     * Class Constructor.
     *
     * @param iLogger $logger The instance of the logger interface.
     * @param iDB $db The instance of the database interface.
     * @param iCache $cache The instance of the cache interface.
     */
    public function __construct(
        protected iLogger $logger,
        protected iDB $db,
        protected iCache $cache,
    ) {}

    /**
     * @inheritdoc
     */
    public function withDB(iDB $db): self
    {
        $instance = clone $this;
        $instance->db = $db;
        return $instance;
    }

    /**
     * @inheritdoc
     */
    public function withCache(iCache $cache): self
    {
        $instance = clone $this;
        $instance->cache = $cache;
        return $instance;
    }

    /**
     * @inheritdoc
     */
    public function withLogger(iLogger $logger): self
    {
        $instance = clone $this;
        $instance->logger = $logger;
        return $instance;
    }

    /**
     * @inheritdoc
     */
    public function getOptions(array $options = []): array
    {
        return $this->options;
    }

    /**
     * @inheritdoc
     */
    public function setOptions(array $options = []): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function withOptions(array $options = []): static
    {
        $instance = clone $this;
        $instance->options = $options;
        return $instance;
    }

    /**
     * @inheritdoc
     */
    public function withUserContext(UserContext $userContext): static
    {
        $instance = clone $this;
        $instance->userContext = $userContext;
        return $instance;
    }

    /**
     * @inheritdoc
     */
    public function loadData(?iDate $date = null): self
    {
        $startedAt = microtime(true);
        $this->fullyLoaded = null === $date;

        foreach ($this->db->getAll($date, opts: ['class' => $this->options['class'] ?? null]) as $entity) {
            $pointer = self::GUID . $entity->id;

            if (null !== ($this->objects[$pointer] ?? null)) {
                continue;
            }

            $this->objects[$pointer] = $entity;
            $this->addPointers($this->objects[$pointer], $pointer);
        }

        $this->logger->info(
            'Preloaded {pointer_count} pointers and {object_count} objects for \'{user}\' into {mapper}.',
            [
                ...$this->mapperContext(),
                'event_name' => 'mapper.preload.completed',
                'operation' => 'preload',
                'outcome' => 'completed',
                'pointer_count' => count($this->pointers),
                'object_count' => count($this->objects),
                'duration_seconds' => round(microtime(true) - $startedAt, 4),
                'memory' => [
                    'now' => get_memory_usage(),
                    'peak' => get_peak_memory_usage(),
                ],
            ],
        );

        return $this;
    }

    /**
     * Add new item to the mapper.
     *
     * @param iState $entity The entity to add.
     * @param array $opts Additional options.
     *
     * @return self
     */
    protected function addNewItem(iState $entity, array $opts = []): self
    {
        if (true === (bool) ag($opts, Options::IMPORT_METADATA_ONLY)) {
            Message::increment("{$entity->via}.{$entity->type}.failed");
            $this->logger->notice(
                'Ignoring {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\': backend is metadata-only.',
                $this->itemContext($entity, [
                    'event_name' => 'mapper.item.ignored',
                    'operation' => 'add',
                    'outcome' => 'ignored',
                    'reason' => 'metadata_source_only',
                    'meta_only' => true,
                    'state' => $entity->getAll(),
                ]),
            );
            return $this;
        }

        $this->objects[] = $entity;
        $pointer = array_key_last($this->objects);

        $this->changed[$pointer] = $pointer;

        Message::increment("{$entity->via}.{$entity->type}.added");
        $this->addPointers($this->objects[$pointer], $pointer);

        if (true === $this->inTraceMode()) {
            $data = $entity->getAll();
            unset($data['id']);
            $data[iState::COLUMN_UPDATED] = make_date($data[iState::COLUMN_UPDATED]);
            $data[iState::COLUMN_WATCHED] = 0 === $data[iState::COLUMN_WATCHED] ? 'No' : 'Yes';
            if ($entity->isMovie()) {
                unset($data[iState::COLUMN_SEASON], $data[iState::COLUMN_EPISODE], $data[iState::COLUMN_PARENT]);
            }
        } else {
            $data = [
                iState::COLUMN_META_DATA => [
                    $entity->via => [
                        iState::COLUMN_ID => ag($entity->getMetadata($entity->via), iState::COLUMN_ID),
                        iState::COLUMN_UPDATED => make_date($entity->updated),
                        iState::COLUMN_GUIDS => $entity->getGuids(),
                        iState::COLUMN_PARENT => $entity->getParentGuids(),
                    ],
                ],
            ];
        }

        $this->logger->notice('Added {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\' to local state.', [
            ...$this->itemContext($entity),
            'event_name' => 'mapper.item.added',
            'operation' => 'add',
            'outcome' => 'added',
            true === $this->inTraceMode() ? 'trace' : 'metadata' => $data,
        ]);

        return $this;
    }

    /**
     * Handle tainted entities.
     *
     * @param string|int $pointer The pointer to the entity.
     * @param iState $cloned The cloned entity.
     * @param iState $entity The entity to handle.
     * @param array $opts Additional options.
     *
     * @return self
     */
    protected function handleTainted(string|int $pointer, iState $cloned, iState $entity, array $opts = []): self
    {
        $keys = [iState::COLUMN_META_DATA];

        if (true === (clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
            $this->changed[$pointer] = $pointer;
            Message::increment("{$entity->via}.{$entity->type}.updated");

            $this->objects[$pointer] = $this->objects[$pointer]->apply(
                entity: $entity,
                fields: array_merge($keys, [iState::COLUMN_EXTRA]),
            );

            $this->removePointers($cloned)->addPointers($this->objects[$pointer], $pointer);

            $changes = $this->objects[$pointer]->diff(fields: $keys);

            if (count($changes) >= 1) {
                $this->logger->notice('Updated metadata for {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\'.', [
                    ...$this->itemContext($cloned, ['backend' => $entity->via]),
                    'event_name' => 'mapper.item.updated',
                    'operation' => 'metadata',
                    'outcome' => 'updated',
                    'changes' => $changes,
                    'changed_fields' => array_keys($changes),
                ]);
            }
            return $this;
        }

        if ($entity->isWatched() !== $this->objects[$pointer]->isWatched()) {
            $reasons = [];

            if (true === $entity->isTainted()) {
                $reasons[] = 'metadata-only event';
            }
            if (true === (bool) ag($opts, Options::IMPORT_METADATA_ONLY)) {
                $reasons[] = 'mapper is in metadata-only mode';
            }

            if (count($reasons) < 1) {
                $reasons[] = 'Abnormal state detected.';
            }

            $this->logger->notice(
                'Ignoring state change for {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\': remote state {state} differs from local {local_state}.',
                [
                    ...$this->itemContext($this->objects[$pointer], ['backend' => $entity->via]),
                    'event_name' => 'mapper.item.ignored',
                    'operation' => 'state',
                    'outcome' => 'ignored',
                    'reason' => 'state_change_ignored',
                    'state' => $entity->isWatched() ? 'played' : 'unplayed',
                    'local_state' => $this->objects[$pointer]->isWatched() ? 'played' : 'unplayed',
                    'reasons' => implode(', ', $reasons),
                ],
            );

            return $this;
        }

        if (true === $this->inTraceMode()) {
            $this->logger->info(
                'No metadata changes for {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\'.',
                $this->itemContext($cloned, [
                    'backend' => $entity->via,
                    'event_name' => 'mapper.item.unchanged',
                    'operation' => 'metadata',
                    'outcome' => 'completed',
                    'reason' => 'no_metadata_changes',
                ]),
            );
        }

        return $this;
    }

    protected function handleOldEntity(string|int $pointer, iState $cloned, iState $entity, array $opts = []): self
    {
        $keys = [iState::COLUMN_META_DATA];

        // -- Handle mark as unplayed logic.
        if (false === $entity->isWatched() && true === $cloned->shouldMarkAsUnplayed($entity, $this->userContext)) {
            $this->changed[$pointer] = $pointer;
            Message::increment("{$entity->via}.{$entity->type}.updated");

            $this->objects[$pointer] = $this->objects[$pointer]
                ->apply(
                    entity: $entity,
                    fields: array_merge($keys, [iState::COLUMN_EXTRA]),
                )
                ->markAsUnplayed(backend: $entity);

            $changes = $this->objects[$pointer]->diff(
                array_merge($keys, [iState::COLUMN_WATCHED, iState::COLUMN_UPDATED]),
            );

            if (count($changes) >= 1) {
                $this->logger->notice('Marked {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\' as unplayed.', [
                    ...$this->itemContext($cloned, ['backend' => $entity->via]),
                    'event_name' => 'mapper.item.state_changed',
                    'operation' => 'state',
                    'outcome' => 'completed',
                    'old_state' => $cloned->isWatched() ? 'played' : 'unplayed',
                    'new_state' => 'unplayed',
                    'changes' => $changes,
                    'changed_fields' => array_keys($changes),
                ]);
            }

            return $this;
        }

        $newPlayProgress = (int) ag($entity->getMetadata($entity->via), iState::COLUMN_META_DATA_PROGRESS);
        $oldPlayProgress = (int) ag($cloned->getMetadata($entity->via), iState::COLUMN_META_DATA_PROGRESS);
        $playChanged = $newPlayProgress > ($oldPlayProgress + 10);
        $metaExists = count($cloned->getMetadata($entity->via)) >= 1;

        // -- this sometimes leads to never ending updates as data from backends conflicts.
        if (!$metaExists || $playChanged || true === (bool) ag($this->options, Options::MAPPER_ALWAYS_UPDATE_META)) {
            if (true === (clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
                $this->changed[$pointer] = $pointer;
                Message::increment("{$entity->via}.{$entity->type}.updated");

                $this->objects[$pointer] = $this->objects[$pointer]->apply(
                    entity: $entity,
                    fields: array_merge($keys, [iState::COLUMN_EXTRA]),
                );

                $this->removePointers($cloned)->addPointers($this->objects[$pointer], $pointer);

                $allowUpdate = (int) Config::get('progress.threshold', 0);

                $changes = $this->objects[$pointer]->diff(fields: $keys);
                $progress = $playChanged && $entity->hasPlayProgress();
                $minThreshold = (int) Config::get('progress.minThreshold', 86_400);
                if ($entity->isWatched() && $allowUpdate < $minThreshold) {
                    $progress = false;
                }

                if (count($changes) >= 1) {
                    $_keys = array_merge($keys, [iState::COLUMN_EXTRA]);
                    if ($playChanged && $progress) {
                        $_keys[] = iState::COLUMN_VIA;
                    }

                    $this->objects[$pointer] = $this->objects[$pointer]->apply(entity: $entity, fields: $_keys);

                    $this->logger->notice(
                        $progress
                            ? 'Updated {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\' due to play progress change.'
                            : 'Updated metadata for {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\'.',
                        [
                            ...$this->itemContext($cloned, ['backend' => $entity->via]),
                            'event_name' => 'mapper.item.updated',
                            'operation' => $progress ? 'progress' : 'metadata',
                            'outcome' => 'updated',
                            'changes' => $progress ? $this->objects[$pointer]->diff(fields: $_keys) : $changes,
                            'fields' => implode(',', $keys),
                            'changed_fields' => array_keys($progress ? $this->objects[$pointer]->diff(fields: $_keys) : $changes),
                        ],
                    );

                    if (true === $progress) {
                        $itemId = r('{type}://{id}:{tainted}@{backend}', [
                            'type' => $entity->type,
                            'backend' => $entity->via,
                            'tainted' => 'untainted',
                            'id' => ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '??'),
                        ]);

                        $this->progressItems[$itemId] = $this->objects[$pointer];
                        if (null !== ($onProgressUpdate = ag($opts, Options::STATE_PROGRESS_EVENT, null))) {
                            $onProgressUpdate($this->objects[$pointer]);
                        }
                    }
                }

                return $this;
            }
        }

        Message::increment("{$entity->via}.{$entity->type}.ignored_not_played_since_last_sync");

        if ($entity->isWatched() !== $this->objects[$pointer]->isWatched()) {
            if ($this->inTraceMode()) {
                $this->logger->debug(
                    'Ignoring state change for {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\': remote item date is older than last sync.',
                    [
                        ...$this->itemContext($this->objects[$pointer], ['backend' => $entity->via]),
                        'event_name' => 'mapper.item.ignored',
                        'operation' => 'state',
                        'outcome' => 'ignored',
                        'reason' => 'remote_older_than_last_sync',
                        'remote_date' => make_date($entity->updated),
                        'local_date' => make_date($opts['after']),
                        'state' => $entity->isWatched() ? 'played' : 'unplayed',
                        'local_state' => $this->objects[$pointer]->isWatched() ? 'played' : 'unplayed',
                    ],
                );
            }
            return $this->handleTainted($pointer, $cloned, $entity, $opts);
        }

        if ($this->inTraceMode()) {
            $this->logger->debug('Ignoring {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\': no changes detected.', $this->itemContext($cloned, [
                'backend' => $entity->via,
                'event_name' => 'mapper.item.ignored',
                'operation' => 'state',
                'outcome' => 'ignored',
                'reason' => 'no_changes',
            ]));
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function add(iState $entity, array $opts = []): self
    {
        if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
            $this->logger->warning(
                'Ignoring {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\': no supported external ids.',
                $this->itemContext($entity, [
                    'event_name' => 'mapper.item.ignored',
                    'operation' => 'add',
                    'outcome' => 'ignored',
                    'reason' => 'no_supported_external_ids',
                    'guid_count' => count($entity->getGuids()),
                ]),
            );
            Message::increment("{$entity->via}.{$entity->type}.failed_no_guid");
            return $this;
        }

        if (true === $entity->isEpisode() && $entity->episode < 1) {
            $this->logger->notice(
                'Ignoring {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\': episode number is missing.',
                $this->itemContext($entity, [
                    'event_name' => 'mapper.item.ignored',
                    'operation' => 'add',
                    'outcome' => 'ignored',
                    'reason' => 'missing_episode_number',
                    'state' => $entity->getAll(),
                ]),
            );
            Message::increment("{$entity->via}.{$entity->type}.failed_no_episode_number");
            return $this;
        }

        $metadataOnly = true === (bool) ag($opts, Options::IMPORT_METADATA_ONLY);

        if (false === ($pointer = $this->getPointer($entity))) {
            // -- A new entry with no previous data was found.
            return $this->addNewItem($entity, $opts);
        }

        /**
         * ONLY update backend metadata
         * if metadataOnly is set or the event is tainted.
         */
        if (true === $metadataOnly || true === $entity->isTainted()) {
            return $this->handleTainted($pointer, clone $this->objects[$pointer], $entity, $opts);
        }

        // -- Item date is older than recorded last sync date logic handling.
        $hasAfter = null !== ($opts['after'] ?? null) && true === $opts['after'] instanceof iDate;
        if (true === $hasAfter && $opts['after']->getTimestamp() >= $entity->updated) {
            return $this->handleOldEntity($pointer, clone $this->objects[$pointer], $entity, $opts);
        }

        /**
         * DO NOT operate directly on this object it should be cloned.
         * It should maintain pristine condition until changes are committed.
         */
        $cloned = clone $this->objects[$pointer];

        /**
         * Fix for #329 {@see https://github.com/arabcoders/watchstate/issues/329}
         *
         * This conditional block should proceed only if specific conditions are met.
         * 1- the backend state is [unwatched] while the db state is [watched]
         * 2- if the db.metadata.backend.played_at is equal to entity.updated or the db.metadata has no data.
         * 3 - mark entity as tainted and re-process it.
         */
        if (true === $hasAfter && true === $cloned->isWatched() && false === $entity->isWatched()) {
            $hasMeta = count($cloned->getMetadata($entity->via)) >= 1;
            $hasDate = $entity->updated === ag($cloned->getMetadata($entity->via), iState::COLUMN_META_DATA_PLAYED_AT);

            $reason = false === $hasMeta ? 'missing_metadata' : 'played_at_matches_updated';

            $this->logger->warning('Queued {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\' for reprocessing because remote state conflicts with local metadata.', [
                ...$this->itemContext($cloned, ['backend' => $entity->via]),
                'event_name' => 'mapper.item.requeued',
                'operation' => 'state',
                'outcome' => 'requeued',
                'reason' => $reason,
                'current_state' => $cloned->isWatched() ? 'played' : 'unplayed',
                'new_state' => $entity->isWatched() ? 'played' : 'unplayed',
            ]);

            if (false === $hasMeta || true === $hasDate) {
                $metadata = $entity->getMetadata();
                $metadata[$entity->via][iState::COLUMN_META_DATA_PLAYED_AT] = $entity->updated;
                $entity->metadata = $metadata;
                $entity->setIsTainted(true);
                return $this->add($entity, $opts);
            }
        }

        $keys = $opts['diff_keys'] ?? array_flip(
            array_keys_diff(
                base: array_flip(iState::ENTITY_KEYS),
                list: iState::ENTITY_IGNORE_DIFF_CHANGES,
                has: false,
            ),
        );

        if (true === (clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
            $this->changed[$pointer] = $pointer;
            Message::increment("{$entity->via}.{$entity->type}.updated");

            $this->objects[$pointer] = $this->objects[$pointer]->apply(
                entity: $entity,
                fields: array_merge($keys, [iState::COLUMN_EXTRA]),
            );

            $this->removePointers($cloned)->addPointers($this->objects[$pointer], $pointer);

            $changes = $this->objects[$pointer]->diff(fields: $keys);

            if (count($changes) >= 1) {
                $this->logger->notice(
                    $cloned->isWatched() !== $this->objects[$pointer]->isWatched()
                        ? 'Updated {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\' and marked it as {state}.'
                        : 'Updated {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\'.',
                    [
                        ...$this->itemContext($cloned, ['backend' => $entity->via]),
                        'event_name' => 'mapper.item.updated',
                        'operation' => 'update',
                        'outcome' => 'updated',
                        'changes' => $changes,
                        'state' => $this->objects[$pointer]->isWatched() ? 'played' : 'unplayed',
                        'fields' => implode(', ', $keys),
                        'changed_fields' => array_keys($changes),
                    ],
                );
            }

            return $this;
        }

        $context = [
            'mapper' => after_last(self::class, '\\'),
            'item_id' => $cloned->id ?? 'New',
            'backend' => $entity->via,
            'title' => $cloned->getName(),
            'user' => $this->userContext->name ?? 'main',
        ];

        if (true === $this->inTraceMode()) {
            $context['state'] = [
                'database' => $cloned->getAll(),
                'backend' => $entity->getAll(),
            ];
        }

        $this->logger->debug(
            'Ignoring {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\': metadata and play state are identical.',
            [
                ...$context,
                'event_name' => 'mapper.item.ignored',
                'operation' => 'update',
                'outcome' => 'ignored',
                'reason' => 'no_changes',
            ],
        );

        Message::increment("{$entity->via}.{$entity->type}.ignored_no_change");

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function get(iState $entity): ?iState
    {
        return false === ($pointer = $this->getPointer($entity)) ? null : $this->objects[$pointer];
    }

    /**
     * @inheritdoc
     */
    public function remove(iState $entity): bool
    {
        if (false === ($pointer = $this->getPointer($entity))) {
            return false;
        }

        $this->removePointers($this->objects[$pointer]);

        $this->db->remove($this->objects[$pointer]);

        if (null !== ($this->objects[$pointer] ?? null)) {
            unset($this->objects[$pointer]);
        }

        if (null !== ($this->changed[$pointer] ?? null)) {
            unset($this->changed[$pointer]);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function commit(): mixed
    {
        $state = $this->db->transactional(function (iDB $db) {
            $list = [
                iState::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
                iState::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
            ];

            $count = count($this->changed);

            if (0 === $count) {
                $this->logger->notice('Skipping mapper commit for \'{user}\': no changes detected.', [
                    ...$this->mapperContext(),
                    'event_name' => 'mapper.commit.skipped',
                    'operation' => 'commit',
                    'outcome' => 'skipped',
                    'reason' => 'no_changes',
                ]);
                return $list;
            }
            $inDryRunMode = $this->inDryRunMode();

            if (true === $inDryRunMode) {
                $this->logger->notice('Recorded {total} mapper changes for \'{user}\' without committing them.', [
                    ...$this->mapperContext(),
                    'event_name' => 'mapper.commit.skipped',
                    'operation' => 'commit',
                    'outcome' => 'skipped',
                    'reason' => 'dry_run',
                    'total' => $count,
                ]);
            }

            foreach ($this->changed as $pointer) {
                try {
                    $entity = &$this->objects[$pointer];

                    if (null === $entity->id) {
                        if (false === $inDryRunMode) {
                            $db->insert($entity, $this->options);
                        }
                        $list[$entity->type]['added']++;
                    } else {
                        if (false === $inDryRunMode) {
                            $db->update($entity, $this->options);
                        }
                        $list[$entity->type]['updated']++;
                    }
                } catch (PDOException $e) {
                    $list[$entity->type]['failed']++;
                    $this->logger->error(
                        ...lw(
                            message: 'Failed to map {item_type} \'#{item_id}: {item_title}\' from \'{user}@{backend}\' during {operation}.',
                            context: [
                                ...$this->itemContext($entity),
                                'event_name' => 'mapper.item.failed',
                                'operation' => $entity->id === null ? 'add' : 'update',
                                'outcome' => 'failed',
                                'state' => $entity->getAll(),
                                ...exception_log($e),
                            ],
                            e: $e,
                        ),
                    );
                }
            }

            return $list;
        });

        if (true !== $this->inDryRunMode() && count($this->progressItems) >= 1) {
            try {
                $name = '{type}://{id}@{backend}';

                $opts = ['unique' => true];

                if (null !== $this->userContext) {
                    $opts = ag_set($opts, Options::CONTEXT_USER, $this->userContext->name);
                    $name = $name . '/' . $this->userContext->name;
                }

                foreach ($this->progressItems as $entity) {
                    $opts[EventsTable::COLUMN_REFERENCE] = r($name, [
                        'type' => $entity->type,
                        'backend' => $entity->via,
                        'id' => ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '??'),
                    ]);
                    queue_event(ProcessProgressEvent::NAME, [iState::COLUMN_ID => $entity->id], $opts);
                }
            } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
                $this->logger->error(
                    ...lw(
                        message: 'Failed to queue progress updates during mapper commit.',
                        context: [
                            ...$this->mapperContext(),
                            'event_name' => 'mapper.commit.failed',
                            'operation' => 'queue_progress',
                            'outcome' => 'failed',
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );
            }
        }

        $this->reset();

        return $state;
    }

    /**
     * @inheritdoc
     */
    public function has(iState $entity): bool
    {
        return null !== $this->get($entity);
    }

    /**
     * @inheritdoc
     */
    public function reset(): self
    {
        $this->fullyLoaded = false;
        $this->objects = $this->changed = $this->pointers = $this->progressItems = [];

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getObjects(array $opts = []): array
    {
        return $this->objects;
    }

    /**
     * @inheritdoc
     */
    public function getObjectsCount(): int
    {
        return count($this->objects);
    }

    /**
     * @inheritdoc
     */
    public function count(): int
    {
        return count($this->changed);
    }

    /**
     * @inheritdoc
     */
    public function setLogger(iLogger $logger): self
    {
        $this->logger = $logger;
        $this->db->setLogger($logger);
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getLogger(): iLogger
    {
        return $this->logger;
    }

    /**
     * @inheritdoc
     */
    public function setDatabase(iDB $db): self
    {
        $this->db = $db;
        return $this;
    }

    /**
     * Class Destructor
     *
     * This method is executed when an object of this class is destroyed.
     * It checks if the mapper disable autocommit option is false and the count
     * of objects is greater than or equal to 1. If both conditions are met,
     * it calls the commit() method to commit any pending changes.
     */
    public function __destruct()
    {
        if (false === (bool) ag($this->options, Options::MAPPER_DISABLE_AUTOCOMMIT) && $this->count() >= 1) {
            $this->commit();
        }
    }

    /**
     * @inheritdoc
     */
    public function inDryRunMode(): bool
    {
        return true === (bool) ag($this->options, Options::DRY_RUN, false);
    }

    /**
     * @inheritdoc
     */
    public function inTraceMode(): bool
    {
        return true === (bool) ag($this->options, Options::DEBUG_TRACE, false);
    }

    /**
     * @inheritdoc
     */
    public function getPointersList(): array
    {
        return $this->pointers;
    }

    /**
     * @inheritdoc
     */
    public function getChangedList(): array
    {
        return $this->changed;
    }

    /**
     * @inheritdoc
     */
    public function computeChanges(array $backends): array
    {
        $changes = [];

        foreach ($backends as $backend) {
            $changes[$backend] = [];
        }

        foreach ($this->objects as $entity) {
            $state = $entity->isSynced($backends);
            foreach ($state as $b => $value) {
                if (false !== $value) {
                    continue;
                }

                $changes[$b][] = $entity;
            }
        }

        return $changes;
    }

    /**
     * Add pointers to the pointer storage.
     *
     * @param iState $entity The entity containing the pointers.
     * @param string|int $pointer The pointer to database object id.
     *
     * @return static The current instance of mapper.
     */
    protected function addPointers(iState $entity, string|int $pointer): static
    {
        foreach ($entity->getRelativePointers() as $key) {
            $this->pointers[$key] = $pointer;
        }

        foreach ($entity->getPointers() as $key) {
            $this->pointers[$key . '/' . $entity->type] = $pointer;
        }

        return $this;
    }

    /**
     * Get the pointer value for the given entity.
     *
     * @param iState $entity The entity object to get the pointer for.
     *
     * @return int|string|bool The pointer value if found, otherwise false.
     */
    protected function getPointer(iState $entity): int|string|bool
    {
        if (null !== $entity->id && null !== ($this->objects[self::GUID . $entity->id] ?? null)) {
            return self::GUID . $entity->id;
        }

        foreach ($entity->getRelativePointers() as $key) {
            if (null === ($this->pointers[$key] ?? null)) {
                continue;
            }

            return $this->pointers[$key];
        }

        foreach ($entity->getPointers() as $key) {
            $lookup = $key . '/' . $entity->type;
            if (null !== ($this->pointers[$lookup] ?? null)) {
                return $this->pointers[$lookup];
            }
        }

        if (false === $this->fullyLoaded && null !== ($lazyEntity = $this->db->get($entity))) {
            $this->objects[self::GUID . $entity->id] = $lazyEntity;

            $this->addPointers($this->objects[self::GUID . $entity->id], self::GUID . $entity->id);

            return self::GUID . $entity->id;
        }

        return false;
    }

    /**
     * Removes pointers from the class's "pointers" array based on the given entity.
     *
     * @param iState $entity The entity object from which the pointers should be removed.
     *
     * @return static The current instance of mapper.
     */
    protected function removePointers(iState $entity): static
    {
        foreach ($entity->getPointers() as $key) {
            $lookup = $key . '/' . $entity->type;
            if (null !== ($this->pointers[$lookup] ?? null)) {
                unset($this->pointers[$lookup]);
            }
        }

        foreach ($entity->getRelativePointers() as $key) {
            if (null === ($this->pointers[$key] ?? null)) {
                continue;
            }

            unset($this->pointers[$key]);
        }

        return $this;
    }

    /**
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    private function mapperContext(array $extra = []): array
    {
        return array_merge([
            'mapper' => after_last(self::class, '\\'),
            'subsystem' => 'mapper',
            'user' => $this->userContext->name ?? 'main',
        ], $extra);
    }

    /**
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    private function itemContext(iState $entity, array $extra = []): array
    {
        return $this->mapperContext(array_merge([
            'backend' => $entity->via,
            'item_id' => $entity->id ?? ag($entity->getMetadata($entity->via), iState::COLUMN_ID, 'New'),
            'item_type' => $entity->type,
            'item_title' => $entity->getName(),
        ], $extra));
    }
}
