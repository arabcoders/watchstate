<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Config;
use App\Libs\Container;
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
use Throwable;

/**
 * DirectMapper Class.
 *
 * This class is alternative implementation of the ImportInterface.
 * The difference between this and the MemoryMapper is that this mapper uses direct 1:1 database mapping.
 * Which leads to less memory usage overall and slower performance. This mapper should only be used when memory is a concern.
 * The only thing kept in memory is the list of pointers to the database objects.
 *
 * @implements ImportInterface
 */
class DirectMapper implements ImportInterface
{
    /**
     * @var array<int,int> List used objects.
     */
    protected array $objects = [];

    /**
     * @var array<array-key,int> List of pointers.
     */
    protected array $pointers = [];

    /**
     * @var array<int,int> List changed entities.
     */
    protected array $changed = [];

    /**
     * @var array<array-key,<string,int>> List of actions performed.
     */
    protected array $actions = [
        iState::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
        iState::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
    ];

    /**
     * @var array<string,mixed> Mapper options.
     */
    protected array $options = [];

    /**
     * @var bool $fullyLoaded Indicates whether the entire database loaded.
     */
    protected bool $fullyLoaded = false;

    /**
     * @var array<string,iState> List of items with play progress.
     */
    protected array $progressItems = [];

    /**
     * @var UserContext|null The User Context
     */
    protected UserContext|null $userContext = null;

    /**
     * Class constructor.
     *
     * @param iLogger $logger The logger instance.
     * @param iDB $db The database instance.
     * @param iCache $cache The cache instance.
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
    public function withUserContext(UserContext $userContext): static
    {
        $instance = clone $this;
        $instance->userContext = $userContext;
        return $instance;
    }

    /**
     * @inheritdoc
     */
    public function loadData(iDate|null $date = null): self
    {
        $this->fullyLoaded = null === $date;

        $opts = [
            'class' => $this->options['class'] ?? null,
            'fields' => [
                iState::COLUMN_ID,
                iState::COLUMN_TYPE,
                iState::COLUMN_PARENT,
                iState::COLUMN_GUIDS,
                iState::COLUMN_SEASON,
                iState::COLUMN_EPISODE,
            ],
        ];

        foreach ($this->db->getAll($date, opts: $opts) as $entity) {
            $pointer = $entity->id;

            if (null !== ($this->objects[$pointer] ?? null)) {
                continue;
            }

            $this->objects[$pointer] = $pointer;
            $this->addPointers($entity, $pointer);
        }

        $this->logger->info("{mapper}: Preloaded '{user}' '{pointers}' pointers into memory.", [
            'mapper' => afterLast(self::class, '\\'),
            'pointers' => number_format(count($this->pointers)),
            'user' => $this->userContext?->name ?? 'main',
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
    private function addNewItem(iState $entity, array $opts = []): self
    {
        $metadataOnly = true === (bool)ag($opts, Options::IMPORT_METADATA_ONLY);
        $inDryRunMode = $this->inDryRunMode();
        $onStateUpdate = ag($opts, Options::STATE_UPDATE_EVENT, null);

        if (true === $metadataOnly) {
            $this->actions[$entity->type]['failed']++;
            Message::increment("{$entity->via}.{$entity->type}.failed");

            $this->logger->notice(
                "{mapper}: [N] Ignoring '{user}@{backend}' - '{title}'. Not found locally, and backend set as metadata source only.",
                [
                    'mapper' => afterLast(self::class, '\\'),
                    'metaOnly' => true,
                    'backend' => $entity->via,
                    'title' => $entity->getName(),
                    'data' => $entity->getAll(),
                    'user' => $this->userContext?->name ?? 'main',
                ]
            );

            return $this;
        }

        try {
            if ($this->inTraceMode()) {
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

            if (true === $inDryRunMode) {
                $entity->id = random_int((int)(PHP_INT_MAX / 2), PHP_INT_MAX);
            } else {
                $entity = $this->db->insert($entity);

                if (null !== $onStateUpdate && true === $entity->isWatched()) {
                    $onStateUpdate($entity);
                }
            }

            $this->logger->notice("{mapper}: [N] '{user}@{backend}' added '#{id}: {title}' as new item.", [
                'id' => $entity->id ?? 'New',
                'user' => $this->userContext?->name ?? 'main',
                'mapper' => afterLast(self::class, '\\'),
                'backend' => $entity->via,
                'title' => $entity->getName(),
                true === $this->inTraceMode() ? 'trace' : 'metadata' => $data,
            ]);

            $this->addPointers($entity, $entity->id);

            if (null === ($this->changed[$entity->id] ?? null)) {
                $this->actions[$entity->type]['added']++;
                Message::increment("{$entity->via}.{$entity->type}.added");
            }

            $this->changed[$entity->id] = $this->objects[$entity->id] = $entity->id;
        } catch (PDOException|Throwable $e) {
            $this->actions[$entity->type]['failed']++;
            Message::increment("{$entity->via}.{$entity->type}.failed");
            $this->logger->error(
                ...lw(
                    message: "{mapper}: [N] Exception '{error.kind}' was thrown unhandled during '{user}@{backend}' - '{title}' add as new item. {error.message} at '{error.file}:{error.line}'.",
                    context: [
                        'user' => $this->userContext?->name ?? 'main',
                        'mapper' => afterLast(self::class, '\\'),
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'backend' => $entity->via,
                        'title' => $entity->getName(),
                        'state' => $entity->getAll()
                    ],
                    e: $e
                )
            );
        }

        return $this;
    }

    /**
     * Handle tainted entities.
     *
     * @param iState $local The local entity.
     * @param iState $entity The entity to handle.
     * @param array $opts Additional options.
     *
     * @return self
     */
    private function handleTainted(iState $local, iState $entity, array $opts = []): self
    {
        $metadataOnly = true === (bool)ag($opts, Options::IMPORT_METADATA_ONLY);
        $inDryRunMode = $this->inDryRunMode();
        $keys = [iState::COLUMN_META_DATA];

        $cloned = clone $local;

        $newPlayProgress = (int)ag($entity->getMetadata($entity->via), iState::COLUMN_META_DATA_PROGRESS);
        $oldPlayProgress = (int)ag($cloned->getMetadata($entity->via), iState::COLUMN_META_DATA_PROGRESS);
        $playChanged = $newPlayProgress > ($oldPlayProgress + 10);

        if ($playChanged || true === (clone $local)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
            try {
                $local = $local->apply(
                    entity: $entity,
                    fields: array_merge($keys, [iState::COLUMN_EXTRA])
                );

                $this->removePointers($local)->addPointers($local, $local->id);

                $changes = $local->diff(fields: $keys);
                $allowUpdate = (int)Config::get('progress.threshold', 0);

                $progress = $playChanged && $entity->hasPlayProgress();
                if ($entity->isWatched() && $allowUpdate < 180) {
                    $progress = false;
                }

                if (count($changes) >= 1) {
                    $_keys = array_merge($keys, [iState::COLUMN_EXTRA]);
                    if ($playChanged && $progress) {
                        $_keys[] = iState::COLUMN_VIA;
                    }
                    $local = $local->apply($entity, fields: $_keys);
                    $this->logger->notice(
                        $progress ? "{mapper}: [T] '{user}@{backend}' updated '#{id}: {title}' due to play progress change." : "{mapper}: [T] '{user}@{backend}' updated '#{id}: {title}' metadata.",
                        [
                            'user' => $this->userContext?->name ?? 'main',
                            'mapper' => afterLast(self::class, '\\'),
                            'id' => $cloned->id ?? 'New',
                            'backend' => $entity->via,
                            'title' => $cloned->getName(),
                            'changes' => $progress ? $local->diff(fields: $_keys) : $changes,
                        ]
                    );
                }

                if (false === $inDryRunMode) {
                    $this->db->update($local);
                    if (true === $progress) {
                        $itemId = r('{type}://{id}:{tainted}@{backend}', [
                            'type' => $entity->type,
                            'backend' => $entity->via,
                            'tainted' => 'untainted',
                            'id' => ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '??'),
                        ]);

                        $this->progressItems[$itemId] = $local;
                    }
                }

                if (null === ($this->changed[$local->id] ?? null)) {
                    $this->actions[$local->type]['updated']++;
                    Message::increment("{$entity->via}.{$local->type}.updated");
                }

                $this->changed[$local->id] = $this->objects[$local->id] = $local->id;
            } catch (PDOException $e) {
                $this->actions[$local->type]['failed']++;
                Message::increment("{$entity->via}.{$local->type}.failed");
                $this->logger->error(
                    ...lw(
                        message: "{mapper}: [T] Exception '{error.kind}' was thrown unhandled during '{user}@{backend}' - '{title}' handle tainted. {error.message} at '{error.file}:{error.line}'.",
                        context: [
                            'user' => $this->userContext?->name ?? 'main',
                            'mapper' => afterLast(self::class, '\\'),
                            'error' => [
                                'kind' => $e::class,
                                'line' => $e->getLine(),
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                            'id' => $local->id ?? 'New',
                            'backend' => $entity->via,
                            'title' => $local->getName(),
                            'state' => [
                                'database' => $local->getAll(),
                                'backend' => $entity->getAll()
                            ],
                        ],
                        e: $e
                    )
                );
            }

            return $this;
        }

        if ($entity->isWatched() !== $local->isWatched()) {
            $reasons = [];

            if (true === $entity->isTainted()) {
                $reasons[] = 'event marked as tainted';
            }
            if (true === $metadataOnly) {
                $reasons[] = 'mapper is in metadata only mode';
            }

            if (count($reasons) < 1) {
                $reasons[] = 'Abnormal state detected.';
            }

            $this->logger->notice(
                "{mapper}: [T] '{user}@{backend}' item '#{id}: {title}' is marked as '{state}' vs local '{local_state}', However due to the following reason '{reasons}' it was not considered as valid state.",
                [
                    'user' => $this->userContext?->name ?? 'main',
                    'mapper' => afterLast(self::class, '\\'),
                    'id' => $local->id ?? 'New',
                    'backend' => $entity->via,
                    'state' => $entity->isWatched() ? 'played' : 'unplayed',
                    'local_state' => $local->isWatched() ? 'played' : 'unplayed',
                    'title' => $entity->getName(),
                    'reasons' => implode(', ', $reasons),
                ]
            );

            return $this;
        }

        if ($this->inTraceMode()) {
            $this->logger->info(
                "{mapper}: [T] Ignoring '{user}@{backend}' - '#{id}: {title}'. No metadata changes detected.",
                [
                    'user' => $this->userContext?->name ?? 'main',
                    'mapper' => afterLast(self::class, '\\'),
                    'id' => $local->id ?? 'New',
                    'backend' => $entity->via,
                    'title' => $local->getName(),
                ]
            );
        }

        return $this;
    }

    /**
     * Handle old entities.
     *
     * @param iState $local The local entity.
     * @param iState $entity The entity to handle.
     * @param array $opts Additional options.
     *
     * @return self
     */
    private function handleOldEntity(iState $local, iState $entity, array $opts = []): self
    {
        $keys = [iState::COLUMN_META_DATA];
        $inDryRunMode = $this->inDryRunMode();
        $onStateUpdate = ag($opts, Options::STATE_UPDATE_EVENT, null);

        $cloned = clone $local;

        // -- Handle mark as unplayed logic.
        if (false === $entity->isWatched() && true === $cloned->shouldMarkAsUnplayed(backend: $entity)) {
            try {
                $local = $local->apply(
                    entity: $entity,
                    fields: array_merge($keys, [iState::COLUMN_EXTRA])
                )->markAsUnplayed($entity);

                if (false === $inDryRunMode) {
                    $this->db->update($local);

                    if (null !== $onStateUpdate) {
                        $onStateUpdate($local);
                    }
                }

                $this->logger->notice("{mapper}: [O] '{user}@{backend}' marked '#{id}: {title}' as 'unplayed'.", [
                    'mapper' => afterLast(self::class, '\\'),
                    'id' => $cloned->id ?? 'New',
                    'backend' => $entity->via,
                    'title' => $cloned->getName(),
                    'changes' => $local->diff(),
                    'user' => $this->userContext?->name ?? 'main',
                ]);

                if (null === ($this->changed[$local->id] ?? null)) {
                    $this->actions[$local->type]['updated']++;
                    Message::increment("{$entity->via}.{$local->type}.updated");
                }

                $this->changed[$local->id] = $this->objects[$local->id] = $local->id;
            } catch (PDOException $e) {
                $this->actions[$local->type]['failed']++;
                Message::increment("{$entity->via}.{$local->type}.failed");
                $this->logger->error(
                    ...lw(
                        message: "{mapper}: [O] Exception '{error.kind}' was thrown unhandled during '{user}@{backend}' - '{title}' handle old entity unplayed. {error.message} at '{error.file}:{error.line}'.",
                        context: [
                            'user' => $this->userContext?->name ?? 'main',
                            'mapper' => afterLast(self::class, '\\'),
                            'error' => [
                                'kind' => $e::class,
                                'line' => $e->getLine(),
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                            'id' => $cloned->id ?? 'New',
                            'backend' => $entity->via,
                            'title' => $cloned->getName(),
                            'state' => [
                                'database' => $cloned->getAll(),
                                'backend' => $entity->getAll()
                            ],
                        ],
                        e: $e
                    )
                );
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
                try {
                    $local = $local->apply(
                        entity: $entity,
                        fields: array_merge($keys, [iState::COLUMN_EXTRA])
                    );

                    $this->removePointers($cloned)->addPointers($local, $local->id);

                    $allowUpdate = (int)Config::get('progress.threshold', 0);

                    $changes = $local->diff(fields: $keys);
                    $progress = $playChanged && $entity->hasPlayProgress();
                    if ($entity->isWatched() && $allowUpdate < 180) {
                        $progress = false;
                    }

                    if (count($changes) >= 1) {
                        $_keys = array_merge($keys, [iState::COLUMN_EXTRA]);
                        if ($playChanged && $progress) {
                            $_keys[] = iState::COLUMN_VIA;
                        }
                        $local = $local->apply($entity, fields: $_keys);
                        $this->logger->notice(
                            $progress ? "{mapper}: [O] '{user}@{backend}' updated '#{id}: {title}' due to play progress change." : "{mapper}: [O] '{user}@{backend}' updated '#{id}: {title}' metadata.",
                            [
                                'user' => $this->userContext?->name ?? 'main',
                                'mapper' => afterLast(self::class, '\\'),
                                'id' => $cloned->id ?? 'New',
                                'backend' => $entity->via,
                                'title' => $cloned->getName(),
                                'changes' => $progress ? $local->diff(fields: $_keys) : $changes,
                            ]
                        );
                    }

                    if (false === $inDryRunMode) {
                        $this->db->update($local);
                        if ($progress) {
                            $itemId = r('{type}://{id}:{tainted}@{backend}', [
                                'type' => $entity->type,
                                'backend' => $entity->via,
                                'tainted' => 'untainted',
                                'id' => ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '??'),
                            ]);

                            $this->progressItems[$itemId] = $local;
                        }
                    }

                    if (null === ($this->changed[$local->id] ?? null)) {
                        $this->actions[$local->type]['updated']++;
                        Message::increment("{$entity->via}.{$local->type}.updated");
                    }

                    $this->changed[$local->id] = $this->objects[$local->id] = $local->id;
                } catch (PDOException $e) {
                    $this->actions[$local->type]['failed']++;
                    Message::increment("{$entity->via}.{$local->type}.failed");
                    $this->logger->error(
                        ...lw(
                            message: "{mapper}: [O] Exception '{error.kind}' was thrown unhandled during '{user}@{backend}' - '{title}' handle old entity always update metadata. {error.message} at '{error.file}:{error.line}'.",
                            context: [
                                'user' => $this->userContext?->name ?? 'main',
                                'mapper' => afterLast(self::class, '\\'),
                                'error' => [
                                    'kind' => $e::class,
                                    'line' => $e->getLine(),
                                    'message' => $e->getMessage(),
                                    'file' => after($e->getFile(), ROOT_PATH),
                                ],
                                'id' => $cloned->id ?? 'New',
                                'backend' => $entity->via,
                                'title' => $cloned->getName(),
                                'state' => [
                                    'database' => $cloned->getAll(),
                                    'backend' => $entity->getAll()
                                ],
                            ],
                            e: $e
                        )
                    );
                }

                return $this;
            }
        }

        Message::increment("{$entity->via}.{$entity->type}.ignored_not_played_since_last_sync");

        if ($entity->isWatched() !== $local->isWatched()) {
            $this->logger->notice(
                "{mapper}: [O] '{user}@{backend}' item '#{id}: {title}' is marked as '{state}' vs local '{local_state}', however due to the remote item date '{remote_date}' being older than the last backend sync date '{local_date}'. it was not considered as valid state.",
                [
                    'user' => $this->userContext?->name ?? 'main',
                    'mapper' => afterLast(self::class, '\\'),
                    'id' => $cloned->id ?? 'New',
                    'backend' => $entity->via,
                    'remote_date' => makeDate($entity->updated),
                    'local_date' => makeDate($opts['after']),
                    'state' => $entity->isWatched() ? 'played' : 'unplayed',
                    'local_state' => $local->isWatched() ? 'played' : 'unplayed',
                    'title' => $entity->getName(),
                ]
            );
            return $this;
        }

        if ($this->inTraceMode()) {
            $this->logger->debug("{mapper}: [O] Ignoring '{user}@{backend}' - '#{id}: {title}'. No changes detected.", [
                'user' => $this->userContext?->name ?? 'main',
                'mapper' => afterLast(self::class, '\\'),
                'id' => $cloned->id ?? 'New',
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
        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $this->logger->warning(
                "{mapper}: [A] Ignoring '{user}@{backend}' - '{title}'. No valid/supported external ids.",
                [
                    'mapper' => afterLast(self::class, '\\'),
                    'id' => $entity->id ?? 'New',
                    'backend' => $entity->via,
                    'title' => $entity->getName(),
                    'user' => $this->userContext?->name ?? 'main',
                ]
            );
            Message::increment("{$entity->via}.{$entity->type}.failed_no_guid");
            return $this;
        }

        if (true === $entity->isEpisode() && $entity->episode < 1) {
            $this->logger->warning(
                "{mapper}: [A] Ignoring '{user}@{backend}' '{id}: {title}'. Item was marked as episode but no episode number was provided.",
                [
                    'user' => $this->userContext?->name ?? 'main',
                    'mapper' => afterLast(self::class, '\\'),
                    'id' => $entity->id ?? ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '??'),
                    'backend' => $entity->via,
                    'title' => $entity->getName(),
                    'data' => $entity->getAll(),
                ]
            );
            Message::increment("{$entity->via}.{$entity->type}.failed_no_episode_number");
            return $this;
        }

        $metadataOnly = true === (bool)ag($opts, Options::IMPORT_METADATA_ONLY);
        $inDryRunMode = $this->inDryRunMode();
        $onStateUpdate = ag($opts, Options::STATE_UPDATE_EVENT, null);

        /**
         * Handle adding new item logic.
         */
        if (null === ($local = $this->get($entity))) {
            return $this->addNewItem($entity, $opts);
        }

        /**
         * DO NOT operate directly on this object it should be cloned.
         * It should maintain pristine condition until changes are committed.
         */
        $cloned = clone $local;

        /**
         * ONLY update backend metadata
         * if metadataOnly is set or the event is tainted.
         */
        if (true === $metadataOnly || true === $entity->isTainted()) {
            return $this->handleTainted($cloned, $entity, $opts);
        }

        // -- Item date is older than recorded last sync date logic handling.
        $hasAfter = null !== ($opts['after'] ?? null) && true === ($opts['after'] instanceof iDate);
        if (true === $hasAfter && $opts['after']->getTimestamp() >= $entity->updated) {
            return $this->handleOldEntity($cloned, $entity, $opts);
        }

        /**
         * Fix for #329 {@see https://github.com/arabcoders/watchstate/issues/329}
         *
         * This conditional block should proceed only if specific conditions are met.
         * 1- the backend state is [unwatched] while the db state is [watched]
         * 2- if the db.metadata.backend.played_at is equal to entity.updated or the db.metadata has no data.
         * 3 - mark entity as tainted and re-process it.
         */
        if (true === $hasAfter && true === $cloned->isWatched() && false === $entity->isWatched()) {
            $message = "{mapper}: [A] Conflict detected in '{user}@{backend}: {title}' '{new_state}' vs local '#{id}: {current_state}'.";
            $hasMeta = count($cloned->getMetadata($entity->via)) >= 1;
            $hasDate = $entity->updated === ag($cloned->getMetadata($entity->via), iState::COLUMN_META_DATA_PLAYED_AT);

            if (false === $hasMeta) {
                $message .= ' No metadata. Marking the item as tainted and re-processing.';
            } elseif (true === $hasDate) {
                $message .= ' db.metadata.played_at is equal to entity.updated. Marking the item as tainted and re-processing.';
            }

            $this->logger->warning($message, [
                'user' => $this->userContext?->name ?? 'main',
                'mapper' => afterLast(self::class, '\\'),
                'id' => $cloned->id ?? 'New',
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
            try {
                $local = $local->apply(
                    entity: $entity,
                    fields: array_merge($keys, [iState::COLUMN_EXTRA])
                );

                $this->removePointers($cloned)->addPointers($local, $local->id);

                $changes = $local->diff(fields: $keys);

                $message = "{mapper}: [A] '{user}@{backend}' Updated '#{id}: {title}'.";

                if ($cloned->isWatched() !== $local->isWatched()) {
                    $message = "{mapper}: [A] '{user}@{backend}' Updated and marked '#{id}: {title}' as '{state}'.";

                    if (null !== $onStateUpdate) {
                        $onStateUpdate($local);
                    }
                }

                if (count($changes) >= 1) {
                    $this->logger->notice($message, [
                        'user' => $this->userContext?->name ?? 'main',
                        'mapper' => afterLast(self::class, '\\'),
                        'id' => $cloned->id ?? 'New',
                        'backend' => $entity->via,
                        'title' => $cloned->getName(),
                        'state' => $local->isWatched() ? 'played' : 'unplayed',
                        'changes' => $local->diff(fields: $keys)
                    ]);
                }

                if (false === $inDryRunMode) {
                    $this->db->update($local);
                }

                if (null === ($this->changed[$local->id] ?? null)) {
                    $this->actions[$local->type]['updated']++;
                    Message::increment("{$entity->via}.{$entity->type}.updated");
                }

                $this->changed[$local->id] = $this->objects[$local->id] = $local->id;
            } catch (PDOException $e) {
                $this->actions[$local->type]['failed']++;
                Message::increment("{$entity->via}.{$local->type}.failed");
                $this->logger->error(
                    ...lw(
                        message: "{mapper}: [A] Exception '{error.kind}' was thrown unhandled during '{user}@{backend}' - '{title}' add. {error.message} at '{error.file}:{error.line}'.",
                        context: [
                            'user' => $this->userContext?->name ?? 'main',
                            'mapper' => afterLast(self::class, '\\'),
                            'error' => [
                                'kind' => $e::class,
                                'line' => $e->getLine(),
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                            'id' => $cloned->id ?? 'New',
                            'backend' => $entity->via,
                            'title' => $cloned->getName(),
                            'state' => [
                                'database' => $cloned->getAll(),
                                'backend' => $entity->getAll()
                            ],
                            'trace' => $e->getTrace(),
                        ],
                        e: $e
                    )
                );
            }

            return $this;
        }

        $context = [
            'mapper' => afterLast(self::class, '\\'),
            'id' => $cloned->id ?? 'New',
            'backend' => $entity->via,
            'title' => $cloned->getName(),
            'user' => $this->userContext?->name ?? 'main',
        ];

        if (true === $this->inTraceMode()) {
            $context['state'] = [
                'database' => $cloned->getAll(),
                'backend' => $entity->getAll(),
            ];
        }

        $this->logger->debug(
            "{mapper}: [A] Ignoring '{user}@{backend}' - '#{id}: {title}'. Metadata & play state are identical.",
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
        if (false === ($pointer = $this->getPointer($entity))) {
            return null;
        }

        if (true === ($pointer instanceof iState)) {
            return $pointer;
        }

        $entity->id = $pointer;

        return $this->db->get($entity);
    }

    /**
     * @inheritdoc
     */
    public function remove(iState $entity): bool
    {
        $this->removePointers($entity);

        if (null !== ($this->objects[$entity->id] ?? null)) {
            unset($this->objects[$entity->id]);
        }

        if (null !== ($this->changed[$entity->id] ?? null)) {
            unset($this->changed[$entity->id]);
        }

        return $this->db->remove($entity);
    }

    /**
     * @inheritdoc
     * @noinspection PhpRedundantCatchClauseInspection
     */
    public function commit(): array
    {
        if (true === (bool)Config::get('sync.progress', false) && count($this->progressItems) >= 1) {
            try {
                $opts = [
                    'unique' => true,
                ];

                if (null !== $this->userContext) {
                    $opts = ag_set($opts, Options::CONTEXT_USER, $this->userContext->name);
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

        $list = $this->actions;

        $this->reset();

        return $list;
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
        $this->actions = [
            iState::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
            iState::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
        ];

        $this->fullyLoaded = false;
        $this->changed = $this->objects = $this->pointers = $this->progressItems = [];

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getObjects(array $opts = []): array
    {
        $list = [];

        $entity = $this->options['class'] ?? Container::get(iState::class);

        foreach ($this->objects as $id) {
            $list[] = $entity::fromArray([iState::COLUMN_ID => $id]);
        }

        if (empty($list)) {
            return [];
        }

        return $this->db->find(...$list);
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
        return [];
    }

    /**
     * Adds pointers to the entity.
     *
     * @param iState $entity The entity to extract the pointers from.
     * @param string|int $pointer The pointer to database object id.
     *
     * @return static The updated import instance.
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
     * Get pointer for database entity if exists.
     *
     * @param iState $entity The entity to get the pointer for.
     *
     * @return iState|int|string|bool int|string pointer for the object, or false if not registered.
     */
    protected function getPointer(iState $entity): iState|int|string|bool
    {
        if (null !== $entity->id && null !== ($this->objects[$entity->id] ?? null)) {
            return $entity->id;
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
            $this->objects[$lazyEntity->id] = $lazyEntity->id;

            $this->addPointers($lazyEntity, $lazyEntity->id);

            return $lazyEntity;
        }

        return false;
    }

    /**
     * Removes entity pointers from mapper.
     *
     * @param iState $entity The entity contains the pointers to remove.
     *
     * @return static The updated instance of the class.
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
