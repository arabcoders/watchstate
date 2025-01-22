<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Config;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\ExtendedImportInterface;
use App\Libs\Message;
use App\Libs\Options;
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
 * @implements ExtendedImportInterface
 */
class MemoryMapper implements ExtendedImportInterface
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
     * Class Constructor.
     *
     * @param iLogger $logger The instance of the logger interface.
     * @param iDB $db The instance of the database interface.
     * @param iCache $cache The instance of the cache interface.
     */
    public function __construct(protected iLogger $logger, protected iDB $db, protected iCache $cache)
    {
    }

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
    public function loadData(iDate|null $date = null): self
    {
        $this->fullyLoaded = null === $date;

        foreach ($this->db->getAll($date, opts: ['class' => $this->options['class'] ?? null]) as $entity) {
            $pointer = self::GUID . $entity->id;

            if (null !== ($this->objects[$pointer] ?? null)) {
                continue;
            }

            $this->objects[$pointer] = $entity;
            $this->addPointers($this->objects[$pointer], $pointer);
        }

        $this->logger->info("{mapper}: Preloaded '{pointers}' pointers, and '{objects}' objects into memory.", [
            'mapper' => afterLast(self::class, '\\'),
            'pointers' => number_format(count($this->pointers)),
            'objects' => number_format(count($this->objects)),
        ]);

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
        if (true === (bool)ag($opts, Options::IMPORT_METADATA_ONLY)) {
            Message::increment("{$entity->via}.{$entity->type}.failed");
            $this->logger->notice(
                "{mapper}: [N] Ignoring '{backend}' '{title}'. Does not exist in database. And backend set as metadata source only.",
                [
                    'mapper' => afterLast(self::class, '\\'),
                    'metaOnly' => true,
                    'backend' => $entity->via,
                    'title' => $entity->getName(),
                    'data' => $entity->getAll(),
                ]
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
            $data[iState::COLUMN_UPDATED] = makeDate($data[iState::COLUMN_UPDATED]);
            $data[iState::COLUMN_WATCHED] = 0 === $data[iState::COLUMN_WATCHED] ? 'No' : 'Yes';
            if ($entity->isMovie()) {
                unset($data[iState::COLUMN_SEASON], $data[iState::COLUMN_EPISODE], $data[iState::COLUMN_PARENT]);
            }
        } else {
            $data = [
                iState::COLUMN_META_DATA => [
                    $entity->via => [
                        iState::COLUMN_ID => ag($entity->getMetadata($entity->via), iState::COLUMN_ID),
                        iState::COLUMN_UPDATED => makeDate($entity->updated),
                        iState::COLUMN_GUIDS => $entity->getGuids(),
                        iState::COLUMN_PARENT => $entity->getParentGuids(),
                    ]
                ],
            ];
        }

        $this->logger->notice("{mapper}: [N] '{backend}' added '{title}' as new item.", [
            'mapper' => afterLast(self::class, '\\'),
            'backend' => $entity->via,
            'title' => $entity->getName(),
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
                fields: array_merge($keys, [iState::COLUMN_EXTRA])
            );

            $this->removePointers($cloned)->addPointers($this->objects[$pointer], $pointer);

            $changes = $this->objects[$pointer]->diff(fields: $keys);

            if (count($changes) >= 1) {
                $this->logger->notice("{mapper}: [T] '{backend}' updated '{title}' metadata.", [
                    'mapper' => afterLast(self::class, '\\'),
                    'id' => $cloned->id,
                    'backend' => $entity->via,
                    'title' => $cloned->getName(),
                    'changes' => $changes,
                ]);
            }
            return $this;
        }

        if ($entity->isWatched() !== $this->objects[$pointer]->isWatched()) {
            $reasons = [];

            if (true === $entity->isTainted()) {
                $reasons[] = 'event marked as tainted';
            }
            if (true === (bool)ag($opts, Options::IMPORT_METADATA_ONLY)) {
                $reasons[] = 'Mapper is in metadata only mode';
            }

            if (count($reasons) < 1) {
                $reasons[] = 'Abnormal state detected.';
            }

            $this->logger->notice(
                "{mapper}: [T] '{backend}' item '{id}: {title}' is marked as '{state}' vs local state '{local_state}', However due to the following reason '{reasons}' it was not considered as valid state.",
                [
                    'mapper' => afterLast(self::class, '\\'),
                    'id' => $this->objects[$pointer]->id,
                    'backend' => $entity->via,
                    'state' => $entity->isWatched() ? 'played' : 'unplayed',
                    'local_state' => $this->objects[$pointer]->isWatched() ? 'played' : 'unplayed',
                    'title' => $entity->getName(),
                    'reasons' => implode(', ', $reasons),
                ]
            );

            return $this;
        }

        if (true === $this->inTraceMode()) {
            $this->logger->info("{mapper}: [T] '{backend}' '{title}' No metadata changes detected.", [
                'mapper' => afterLast(self::class, '\\'),
                'id' => $cloned->id,
                'backend' => $entity->via,
                'title' => $cloned->getName(),
            ]);
        }

        return $this;
    }

    protected function handleOldEntity(string|int $pointer, iState $cloned, iState $entity, array $opts = []): self
    {
        $keys = [iState::COLUMN_META_DATA];

        // -- Handle mark as unplayed logic.
        if (false === $entity->isWatched() && true === $cloned->shouldMarkAsUnplayed(backend: $entity)) {
            $this->changed[$pointer] = $pointer;
            Message::increment("{$entity->via}.{$entity->type}.updated");

            $this->objects[$pointer] = $this->objects[$pointer]->apply(
                entity: $entity,
                fields: array_merge($keys, [iState::COLUMN_EXTRA])
            )->markAsUnplayed(backend: $entity);

            $changes = $this->objects[$pointer]->diff(
                array_merge($keys, [iState::COLUMN_WATCHED, iState::COLUMN_UPDATED])
            );

            if (count($changes) >= 1) {
                $this->logger->notice("{mapper}: [O] '{backend}' marked '{title}' as 'unplayed'.", [
                    'mapper' => afterLast(self::class, '\\'),
                    'id' => $cloned->id,
                    'backend' => $entity->via,
                    'title' => $cloned->getName(),
                    'changes' => $changes,
                ]);
            }

            return $this;
        }

        $newPlayProgress = (int)ag($entity->getMetadata($entity->via), iState::COLUMN_META_DATA_PROGRESS);
        $oldPlayProgress = (int)ag($cloned->getMetadata($entity->via), iState::COLUMN_META_DATA_PROGRESS);
        $playChanged = $newPlayProgress > ($oldPlayProgress + 10);
        $metaExists = count($cloned->getMetadata($entity->via)) >= 1;

        // -- this sometimes leads to never ending updates as data from backends conflicts.
        if (!$metaExists || $playChanged || true === (bool)ag($this->options, Options::MAPPER_ALWAYS_UPDATE_META)) {
            if (true === (clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
                $this->changed[$pointer] = $pointer;
                Message::increment("{$entity->via}.{$entity->type}.updated");

                $this->objects[$pointer] = $this->objects[$pointer]->apply(
                    entity: $entity,
                    fields: array_merge($keys, [iState::COLUMN_EXTRA])
                );

                $this->removePointers($cloned)->addPointers($this->objects[$pointer], $pointer);

                $changes = $this->objects[$pointer]->diff(fields: $keys);
                $progress = !$entity->isWatched() && $playChanged && $entity->hasPlayProgress();

                if (count($changes) >= 1) {
                    $_keys = array_merge($keys, [iState::COLUMN_EXTRA]);
                    if ($playChanged && $progress) {
                        $_keys[] = iState::COLUMN_VIA;
                    }

                    $this->objects[$pointer] = $this->objects[$pointer]->apply(entity: $entity, fields: $_keys);

                    $this->logger->notice(
                        $progress ? "{mapper}: [O] '{backend}' updated '{title}' due to play progress change." : "{mapper}: [O] '{backend}' updated '{title}' metadata.",
                        [
                            'mapper' => afterLast(self::class, '\\'),
                            'id' => $cloned->id,
                            'backend' => $entity->via,
                            'title' => $cloned->getName(),
                            'changes' => $progress ? $this->objects[$pointer]->diff(fields: $_keys) : $changes,
                            'fields' => implode(',', $keys),
                        ]
                    );

                    if (true === $entity->hasPlayProgress() && !$entity->isWatched()) {
                        $itemId = r('{type}://{id}:{tainted}@{backend}', [
                            'type' => $entity->type,
                            'backend' => $entity->via,
                            'tainted' => 'untainted',
                            'id' => ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '??'),
                        ]);

                        $this->progressItems[$itemId] = $this->objects[$pointer];
                    }
                }

                return $this;
            }
        }

        Message::increment("{$entity->via}.{$entity->type}.ignored_not_played_since_last_sync");

        if ($entity->isWatched() !== $this->objects[$pointer]->isWatched()) {
            $this->logger->notice(
                "{mapper}: [O] '{backend}' item '{id}: {title}' is marked as '{state}' vs local state '{local_state}', However due to the remote item date '{remote_date}' being older than the last backend sync date '{local_date}'. it was not considered as valid state.",
                [
                    'mapper' => afterLast(self::class, '\\'),
                    'id' => $this->objects[$pointer]->id,
                    'backend' => $entity->via,
                    'remote_date' => makeDate($entity->updated),
                    'local_date' => makeDate($opts['after']),
                    'state' => $entity->isWatched() ? 'played' : 'unplayed',
                    'local_state' => $this->objects[$pointer]->isWatched() ? 'played' : 'unplayed',
                    'title' => $entity->getName(),
                ]
            );
            return $this;
        }

        if ($this->inTraceMode()) {
            $this->logger->debug("{mapper}: [O] Ignoring '{backend}' '{title}'. No changes detected.", [
                'mapper' => afterLast(self::class, '\\'),
                'id' => $cloned->id,
                'backend' => $entity->via,
                'title' => $cloned->getName(),
            ]);
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function add(iState $entity, array $opts = []): self
    {
        if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
            $this->logger->warning("{mapper}: [O] Ignoring '{backend}' '{title}'. No valid/supported external ids.", [
                'mapper' => afterLast(self::class, '\\'),
                'id' => $entity->id,
                'backend' => $entity->via,
                'title' => $entity->getName(),
            ]);
            Message::increment("{$entity->via}.{$entity->type}.failed_no_guid");
            return $this;
        }

        if (true === $entity->isEpisode() && $entity->episode < 1) {
            $this->logger->warning(
                "{mapper}: [N] Ignoring '{backend}' '{id}: {title}'. Item was marked as episode but no episode number was provided.",
                [
                    'mapper' => afterLast(self::class, '\\'),
                    'id' => $entity->id ?? ag($entity->getMetadata($entity->via), iState::COLUMN_ID, ''),
                    'backend' => $entity->via,
                    'title' => $entity->getName(),
                    'data' => $entity->getAll(),
                ]
            );
            Message::increment("{$entity->via}.{$entity->type}.failed_no_episode_number");
            return $this;
        }

        $metadataOnly = true === (bool)ag($opts, Options::IMPORT_METADATA_ONLY);

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
        $hasAfter = null !== ($opts['after'] ?? null) && true === ($opts['after'] instanceof iDate);
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
            $message = "{mapper}: [N] Watch state conflict detected in '{backend}: {title}' '{new_state}' vs local state '{id}: {current_state}'.";
            $hasMeta = count($cloned->getMetadata($entity->via)) >= 1;
            $hasDate = $entity->updated === ag($cloned->getMetadata($entity->via), iState::COLUMN_META_DATA_PLAYED_AT);

            if (false === $hasMeta) {
                $message .= ' No metadata. Marking the item as tainted and re-processing.';
            } elseif (true === $hasDate) {
                $message .= ' db.metadata.played_at is equal to entity.updated. Marking the item as tainted and re-processing.';
            }

            $this->logger->warning($message, [
                'mapper' => afterLast(self::class, '\\'),
                'id' => $cloned->id,
                'backend' => $entity->via,
                'title' => $entity->getName(),
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
                has: false
            )
        );

        if (true === (clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
            $this->changed[$pointer] = $pointer;
            Message::increment("{$entity->via}.{$entity->type}.updated");

            $this->objects[$pointer] = $this->objects[$pointer]->apply(
                entity: $entity,
                fields: array_merge($keys, [iState::COLUMN_EXTRA])
            );

            $this->removePointers($cloned)->addPointers($this->objects[$pointer], $pointer);

            $changes = $this->objects[$pointer]->diff(fields: $keys);

            $message = "{mapper}: [N] '{backend}' Updated '{title}'.";

            if ($cloned->isWatched() !== $this->objects[$pointer]->isWatched()) {
                $message = "{mapper}: '{backend}' Updated and marked '{id}: {title}' as '{state}'.";
            }

            if (count($changes) >= 1) {
                $this->logger->notice($message, [
                    'mapper' => afterLast(self::class, '\\'),
                    'id' => $cloned->id,
                    'backend' => $entity->via,
                    'title' => $cloned->getName(),
                    'changes' => $changes,
                    'state' => $this->objects[$pointer]->isWatched() ? 'played' : 'unplayed',
                    'fields' => implode(', ', $keys),
                ]);
            }

            return $this;
        }

        $context = [
            'mapper' => afterLast(self::class, '\\'),
            'id' => $cloned->id,
            'backend' => $entity->via,
            'title' => $cloned->getName(),
        ];

        if (true === $this->inTraceMode()) {
            $context['state'] = [
                'database' => $cloned->getAll(),
                'backend' => $entity->getAll(),
            ];
        }

        $this->logger->debug(
            "{mapper}: [N] Ignoring '{backend}' '{title}'. Metadata & play state are identical.",
            $context
        );

        Message::increment("{$entity->via}.{$entity->type}.ignored_no_change");

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function get(iState $entity): null|iState
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
     * @noinspection PhpRedundantCatchClauseInspection
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
                $this->logger->notice(afterLast(__class__, '\\') . ': No changes detected.');
                return $list;
            }
            $inDryRunMode = $this->inDryRunMode();

            if (true === $inDryRunMode) {
                $this->logger->notice("{mapper}: Recorded '{total}' object changes.", [
                    'mapper' => afterLast(self::class, '\\'),
                    'total' => $count
                ]);
            }

            foreach ($this->changed as $pointer) {
                try {
                    $entity = &$this->objects[$pointer];

                    if (null === $entity->id) {
                        if (false === $inDryRunMode) {
                            $db->insert($entity);
                        }
                        $list[$entity->type]['added']++;
                    } else {
                        if (false === $inDryRunMode) {
                            $db->update($entity);
                        }
                        $list[$entity->type]['updated']++;
                    }
                } catch (PDOException $e) {
                    $list[$entity->type]['failed']++;
                    $this->logger->error(
                        ...lw(
                            message: "{mapper}: Exception '{error.kind}' was thrown unhandled in {mode} '{backend}: {title}'. '{error.message}' at '{error.file}:{error.line}'.",
                            context: [
                                'mapper' => afterLast(self::class, '\\'),
                                'error' => [
                                    'kind' => $e::class,
                                    'line' => $e->getLine(),
                                    'message' => $e->getMessage(),
                                    'file' => after($e->getFile(), ROOT_PATH),
                                ],
                                'state' => $entity->getAll(),
                                'backend' => $entity->via,
                                'title' => $entity->getName(),
                                'mode' => $entity->id === null ? 'add' : 'update',
                            ],
                            e: $e
                        )
                    );
                }
            }

            return $list;
        });

        if (true !== $this->inDryRunMode()) {
            if (true === (bool)Config::get('sync.progress', false) && count($this->progressItems) >= 1) {
                try {
                    $opts = [
                        'unique' => true,
                    ];

                    // -- make it possible to sync other users watch progress.
                    if (null !== ($altName = ag($this->options, Options::ALT_NAME))) {
                        $opts = ag_set($opts, EventsTable::COLUMN_OPTIONS . '.' . Options::ALT_NAME, $altName);
                    }

                    foreach ($this->progressItems as $entity) {
                        $opts[EventsTable::COLUMN_REFERENCE] = r('{type}://{id}@{backend}', [
                            'type' => $entity->type,
                            'backend' => $entity->via,
                            'id' => ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '??'),
                        ]);
                        queueEvent(ProcessProgressEvent::NAME, [iState::COLUMN_ID => $entity->id], $opts);
                    }
                } catch (\Psr\SimpleCache\InvalidArgumentException) {
                }
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
        if (false === (bool)ag($this->options, Options::MAPPER_DISABLE_AUTOCOMMIT) && $this->count() >= 1) {
            $this->commit();
        }
    }

    /**
     * @inheritdoc
     */
    public function inDryRunMode(): bool
    {
        return true === (bool)ag($this->options, Options::DRY_RUN, false);
    }

    /**
     * @inheritdoc
     */
    public function inTraceMode(): bool
    {
        return true === (bool)ag($this->options, Options::DEBUG_TRACE, false);
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
                if (false === $value) {
                    $changes[$b][] = $entity;
                }
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
            if (null !== ($this->pointers[$key] ?? null)) {
                return $this->pointers[$key];
            }
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
            if (null !== ($this->pointers[$key] ?? null)) {
                unset($this->pointers[$key]);
            }
        }

        return $this;
    }
}
