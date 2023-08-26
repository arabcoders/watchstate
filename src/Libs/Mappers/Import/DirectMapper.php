<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use DateTimeInterface as iDate;
use Exception;
use PDOException;
use Psr\Log\LoggerInterface as iLogger;

final class DirectMapper implements iImport
{
    /**
     * @var array<int,int> List used objects.
     */
    protected array $objects = [];

    /**
     * @var array<array-key,int>
     */
    protected array $pointers = [];

    /**
     * @var array<int,int> List changed entities.
     */
    protected array $changed = [];

    /**
     * @var array<array-key,<string,int>>
     */
    protected array $actions = [
        iState::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
        iState::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
    ];

    protected array $options = [];

    protected bool $fullyLoaded = false;

    public function __construct(protected iLogger $logger, protected iDB $db)
    {
    }

    public function setOptions(array $options = []): iImport
    {
        $this->options = $options;

        return $this;
    }

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

        $this->logger->info('MAPPER: Preloaded [%(pointers)] pointers into memory.', [
            'mapper' => afterLast(self::class, '\\'),
            'pointers' => number_format(count($this->pointers)),
        ]);

        return $this;
    }

    public function add(iState $entity, array $opts = []): self
    {
        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $this->logger->warning('MAPPER: Ignoring [%(backend)] [%(title)] no valid/supported external ids.', [
                'id' => $entity->id,
                'backend' => $entity->via,
                'title' => $entity->getName(),
            ]);
            Message::increment("{$entity->via}.{$entity->type}.failed_no_guid");
            return $this;
        }

        $metadataOnly = true === (bool)ag($opts, Options::IMPORT_METADATA_ONLY);
        $inDryRunMode = $this->inDryRunMode();
        $onStateUpdate = ag($opts, Options::STATE_UPDATE_EVENT, null);

        /**
         * Handle adding new item logic.
         */
        if (null === ($local = $this->get($entity))) {
            if (true === $metadataOnly) {
                $this->actions[$entity->type]['failed']++;
                Message::increment("{$entity->via}.{$entity->type}.failed");

                $this->logger->notice('MAPPER: Ignoring [%(backend)] [%(title)]. Does not exist in database.', [
                    'metaOnly' => true,
                    'backend' => $entity->via,
                    'title' => $entity->getName(),
                    'data' => $entity->getAll(),
                ]);

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

                $this->logger->notice('MAPPER: [%(backend)] added [%(title)] as new item.', [
                    'id' => $entity->id,
                    'backend' => $entity->via,
                    'title' => $entity->getName(),
                    $this->inTraceMode() ? 'trace' : 'metadata' => $data,
                ]);

                $this->addPointers($entity, $entity->id);

                if (null === ($this->changed[$entity->id] ?? null)) {
                    $this->actions[$entity->type]['added']++;
                    Message::increment("{$entity->via}.{$entity->type}.added");
                }

                $this->changed[$entity->id] = $this->objects[$entity->id] = $entity->id;
            } catch (PDOException|Exception $e) {
                $this->actions[$entity->type]['failed']++;
                Message::increment("{$entity->via}.{$entity->type}.failed");
                $this->logger->error(sprintf('MAPPER: %s', $e->getMessage()), [
                    'backend' => $entity->via,
                    'title' => $entity->getName(),
                    'state' => $entity->getAll()
                ]);
            }

            return $this;
        }

        $keys = [iState::COLUMN_META_DATA];

        /**
         * DO NOT operate directly on this object it should be cloned.
         * It should maintain pristine condition until changes are committed.
         */
        $cloned = clone $local;

        /**
         * ONLY update backend metadata as requested by caller.
         * if metadataOnly is set or the event is tainted.
         */
        if (true === $metadataOnly || true === $entity->isTainted()) {
            if (true === (clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
                try {
                    $local = $local->apply(entity: $entity, fields: array_merge($keys, [iState::COLUMN_EXTRA]));

                    $this->removePointers($cloned)->addPointers($local, $local->id);

                    $this->logger->notice('MAPPER: [%(backend)] updated [%(title)] metadata.', [
                        'id' => $local->id,
                        'backend' => $entity->via,
                        'title' => $local->getName(),
                        'changes' => $local->diff(fields: $keys)
                    ]);

                    if (false === $inDryRunMode) {
                        $this->db->update($local);
                    }

                    if (null === ($this->changed[$local->id] ?? null)) {
                        $this->actions[$local->type]['updated']++;
                        Message::increment("{$entity->via}.{$local->type}.updated");
                    }

                    $this->changed[$local->id] = $this->objects[$local->id] = $local->id;
                } catch (PDOException $e) {
                    $this->actions[$local->type]['failed']++;
                    Message::increment("{$entity->via}.{$local->type}.failed");
                    $this->logger->error(sprintf('MAPPER: %s', $e->getMessage()), [
                        'id' => $cloned->id,
                        'backend' => $entity->via,
                        'title' => $cloned->getName(),
                        'state' => [
                            'database' => $cloned->getAll(),
                            'backend' => $entity->getAll()
                        ],
                    ]);
                }

                return $this;
            }

            if ($this->inTraceMode()) {
                $this->logger->info('MAPPER: [%(backend)] [%(title)] No metadata changes detected.', [
                    'id' => $cloned->id,
                    'backend' => $entity->via,
                    'title' => $cloned->getName(),
                ]);
            }

            return $this;
        }

        // -- Item date is older than recorded last sync date logic handling.
        if (null !== ($opts['after'] ?? null) && true === ($opts['after'] instanceof iDate)) {
            if ($opts['after']->getTimestamp() >= $entity->updated) {
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

                        $this->logger->notice('MAPPER: [%(backend)] marked [%(title)] as unplayed.', [
                            'id' => $cloned->id,
                            'backend' => $entity->via,
                            'title' => $cloned->getName(),
                            'changes' => $local->diff(),
                        ]);

                        if (null === ($this->changed[$local->id] ?? null)) {
                            $this->actions[$local->type]['updated']++;
                            Message::increment("{$entity->via}.{$local->type}.updated");
                        }

                        $this->changed[$local->id] = $this->objects[$local->id] = $local->id;
                    } catch (PDOException $e) {
                        $this->actions[$local->type]['failed']++;
                        Message::increment("{$entity->via}.{$local->type}.failed");
                        $this->logger->error(sprintf('MAPPER: %s', $e->getMessage()), [
                            'id' => $cloned->id,
                            'backend' => $entity->via,
                            'title' => $cloned->getName(),
                            'state' => [
                                'database' => $cloned->getAll(),
                                'backend' => $entity->getAll()
                            ],
                        ]);
                    }

                    return $this;
                }

                // -- this sometimes leads to never ending updates as data from backends conflicts.
                if (true === (bool)ag($this->options, Options::MAPPER_ALWAYS_UPDATE_META)) {
                    if (true === (clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
                        try {
                            $local = $local->apply(
                                entity: $entity,
                                fields: array_merge($keys, [iState::COLUMN_EXTRA])
                            );

                            $this->removePointers($cloned)->addPointers($local, $local->id);

                            $changes = $local->diff(fields: $keys);

                            if (count($changes) >= 1) {
                                $this->logger->notice('MAPPER: [%(backend)] updated [%(title)] metadata.', [
                                    'id' => $cloned->id,
                                    'backend' => $entity->via,
                                    'title' => $cloned->getName(),
                                    'changes' => $changes,
                                ]);
                            }

                            if (false === $inDryRunMode) {
                                $this->db->update($local);
                            }

                            if (null === ($this->changed[$local->id] ?? null)) {
                                $this->actions[$local->type]['updated']++;
                                Message::increment("{$entity->via}.{$local->type}.updated");
                            }

                            $this->changed[$local->id] = $this->objects[$local->id] = $local->id;
                        } catch (PDOException $e) {
                            $this->actions[$local->type]['failed']++;
                            Message::increment("{$entity->via}.{$local->type}.failed");
                            $this->logger->error(sprintf('MAPPER: %s', $e->getMessage()), [
                                'id' => $cloned->id,
                                'title' => $cloned->getName(),
                                'state' => [
                                    'database' => $cloned->getAll(),
                                    'backend' => $entity->getAll()
                                ],
                            ]);
                        }

                        return $this;
                    }
                }

                if ($this->inTraceMode()) {
                    $this->logger->debug('MAPPER: Ignoring [%(backend)] [%(title)]. No changes detected.', [
                        'id' => $cloned->id,
                        'backend' => $entity->via,
                        'title' => $cloned->getName(),
                    ]);
                }

                Message::increment("{$entity->via}.{$entity->type}.ignored_not_played_since_last_sync");
                return $this;
            }
        }

        /**
         * Fix for #329
         */
        $ignoreKeys = iState::ENTITY_IGNORE_DIFF_CHANGES;
        if (true === $local->isWatched() && false === $cloned->isWatched()) {
            $ignoreKeys[] = iState::COLUMN_WATCHED;

            $this->logger->warning(
                'MAPPER: Play state conflict detected in [%(backend)] [%(title)] [%(new_state)] vs db [%(current_state)]. Ignoring state change.',
                [
                    'id' => $cloned->id,
                    'backend' => $entity->via,
                    'title' => $cloned->getName(),
                    'current_state' => $local->isWatched() ? 'played' : 'unplayed',
                    'new_state' => $cloned->isWatched() ? 'played' : 'unplayed',
                ]
            );
        }

        $keys = $opts['diff_keys'] ?? array_flip(
            array_keys_diff(
                base: array_flip(iState::ENTITY_KEYS),
                list: $ignoreKeys,
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

                $message = 'MAPPER: [%(backend)] Updated [%(title)].';

                if ($cloned->isWatched() !== $local->isWatched()) {
                    $message = 'MAPPER: [%(backend)] updated and marked [%(title)] as [%(state)].';

                    if (null !== $onStateUpdate) {
                        $onStateUpdate($local);
                    }
                }

                if (count($changes) >= 1) {
                    $this->logger->notice($message, [
                        'id' => $cloned->id,
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
                $this->logger->error(sprintf('MAPPER: %s', $e->getMessage()), [
                    'id' => $cloned->id,
                    'backend' => $entity->via,
                    'title' => $cloned->getName(),
                    'state' => [
                        'database' => $cloned->getAll(),
                        'backend' => $entity->getAll()
                    ],
                ]);
            }

            return $this;
        }

        $context = [
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

        $this->logger->debug('MAPPER: [%(backend)] [%(title)] metadata and play state is identical.', $context);

        Message::increment("{$entity->via}.{$entity->type}.ignored_no_change");

        return $this;
    }

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

    public function commit(): mixed
    {
        $list = $this->actions;

        $this->reset();

        return $list;
    }

    public function has(iState $entity): bool
    {
        return null !== $this->get($entity);
    }

    public function reset(): self
    {
        $this->actions = [
            iState::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
            iState::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
        ];

        $this->fullyLoaded = false;
        $this->changed = $this->objects = $this->pointers = [];

        return $this;
    }

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

    public function getObjectsCount(): int
    {
        return count($this->objects);
    }

    public function count(): int
    {
        return count($this->changed);
    }

    public function setLogger(iLogger $logger): self
    {
        $this->logger = $logger;
        $this->db->setLogger($logger);
        return $this;
    }

    public function setDatabase(iDB $db): self
    {
        $this->db = $db;
        return $this;
    }

    public function inDryRunMode(): bool
    {
        return true === (bool)ag($this->options, Options::DRY_RUN, false);
    }

    public function inTraceMode(): bool
    {
        return true === (bool)ag($this->options, Options::DEBUG_TRACE, false);
    }

    public function getPointersList(): array
    {
        return $this->pointers;
    }

    public function getChangedList(): array
    {
        return $this->changed;
    }

    protected function addPointers(iState $entity, string|int $pointer): iImport
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
     * Is the object already mapped?
     *
     * @param iState $entity
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

    protected function removePointers(iState $entity): iImport
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
