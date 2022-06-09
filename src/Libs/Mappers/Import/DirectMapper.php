<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Options;
use App\Libs\Storage\StorageInterface;
use DateTimeInterface;
use Exception;
use PDOException;
use Psr\Log\LoggerInterface;

final class DirectMapper implements ImportInterface
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
        iFace::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
        iFace::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
    ];

    protected array $options = [];

    protected bool $fullyLoaded = false;

    public function __construct(protected LoggerInterface $logger, protected StorageInterface $storage)
    {
    }

    public function setOptions(array $options = []): ImportInterface
    {
        $this->options = $options;

        return $this;
    }

    public function loadData(DateTimeInterface|null $date = null): self
    {
        $this->fullyLoaded = null === $date;

        $opts = [
            'class' => $this->options['class'] ?? null,
            'fields' => [
                iFace::COLUMN_ID,
                iFace::COLUMN_TYPE,
                iFace::COLUMN_PARENT,
                iFace::COLUMN_GUIDS,
            ],
        ];

        foreach ($this->storage->getAll($date, opts: $opts) as $entity) {
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

    public function add(iFace $entity, array $opts = []): self
    {
        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $this->logger->warning('MAPPER: Ignoring [%(backend)] [%(title)] no valid/supported external ids.', [
                'id' => $entity->id,
                'backend' => $entity->via,
                'title' => $entity->getName(),
            ]);
            Data::increment($entity->via, $entity->type . '_failed_no_guid');
            return $this;
        }

        $metadataOnly = true === (bool)ag($opts, Options::IMPORT_METADATA_ONLY);
        $inDryRunMode = $this->inDryRunMode();

        /**
         * Handle adding new item logic.
         */
        if (null === ($local = $this->get($entity))) {
            if (true === $metadataOnly) {
                $this->actions[$entity->type]['failed']++;
                Data::increment($entity->via, $entity->type . '_failed');

                $this->logger->notice('MAPPER: Ignoring [%(backend)] [%(title)]. Does not exist in storage.', [
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
                    $data[iFace::COLUMN_UPDATED] = makeDate($data[iFace::COLUMN_UPDATED]);
                    $data[iFace::COLUMN_WATCHED] = 0 === $data[iFace::COLUMN_WATCHED] ? 'No' : 'Yes';
                    if ($entity->isMovie()) {
                        unset($data[iFace::COLUMN_SEASON], $data[iFace::COLUMN_EPISODE], $data[iFace::COLUMN_PARENT]);
                    }
                } else {
                    $data = [
                        iFace::COLUMN_META_DATA => [
                            $entity->via => [
                                iFace::COLUMN_ID => ag($entity->getMetadata($entity->via), iFace::COLUMN_ID),
                                iFace::COLUMN_UPDATED => makeDate($entity->updated),
                                iFace::COLUMN_GUIDS => $entity->getGuids(),
                                iFace::COLUMN_PARENT => $entity->getParentGuids(),
                            ]
                        ],
                    ];
                }

                if (true === $inDryRunMode) {
                    $entity->id = random_int((int)(PHP_INT_MAX / 2), PHP_INT_MAX);
                } else {
                    $entity = $this->storage->insert($entity);
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
                    Data::increment($entity->via, $entity->type . '_added');
                }

                $this->changed[$entity->id] = $this->objects[$entity->id] = $entity->id;
            } catch (PDOException|Exception $e) {
                $this->actions[$entity->type]['failed']++;
                Data::increment($entity->via, $entity->type . '_failed');
                $this->logger->error(sprintf('MAPPER: %s', $e->getMessage()), [
                    'backend' => $entity->via,
                    'title' => $entity->getName(),
                    'state' => $entity->getAll()
                ]);
            }

            return $this;
        }

        $cloned = clone $local;
        $keys = [iFace::COLUMN_META_DATA];

        /**
         * Only allow metadata updates no play state changes.
         */
        if (true === $metadataOnly) {
            if (true === (clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
                try {
                    $localFields = array_merge($keys, [iFace::COLUMN_GUIDS]);

                    $entity->guids = Guid::makeVirtualGuid(
                        $entity->via,
                        ag($entity->getMetadata($entity->via), iFace::COLUMN_ID)
                    );

                    $local = $local->apply(entity: $entity, fields: array_merge($localFields, [iFace::COLUMN_EXTRA]));
                    $this->removePointers($cloned)->addPointers($local, $local->id);

                    $this->logger->notice('MAPPER: [%(backend)] updated [%(title)] metadata.', [
                        'id' => $local->id,
                        'backend' => $entity->via,
                        'title' => $local->getName(),
                        'changes' => $local->diff(fields: $localFields)
                    ]);

                    if (false === $inDryRunMode) {
                        $this->storage->update($local);
                    }

                    if (null === ($this->changed[$local->id] ?? null)) {
                        $this->actions[$local->type]['updated']++;
                        Data::increment($entity->via, $local->type . '_updated');
                    }

                    $this->changed[$local->id] = $this->objects[$local->id] = $local->id;
                } catch (PDOException $e) {
                    $this->actions[$local->type]['failed']++;
                    Data::increment($entity->via, $local->type . '_failed');
                    $this->logger->error(sprintf('MAPPER: %s', $e->getMessage()), [
                        'id' => $cloned->id,
                        'backend' => $entity->via,
                        'title' => $cloned->getName(),
                        'state' => [
                            'storage' => $cloned->getAll(),
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

        // -- Item date is older than recorded last sync date,
        if (null !== ($opts['after'] ?? null) && true === ($opts['after'] instanceof DateTimeInterface)) {
            if ($opts['after']->getTimestamp() >= $entity->updated) {
                // -- Handle mark as unplayed logic.
                if (false === $entity->isWatched() && true === $cloned->shouldMarkAsUnplayed(backend: $entity)) {
                    try {
                        $local = $local->apply(
                            entity: $entity,
                            fields: array_merge($keys, [iFace::COLUMN_EXTRA])
                        )->markAsUnplayed($entity);

                        if (false === $inDryRunMode) {
                            $this->storage->update($local);
                        }

                        $this->logger->notice('MAPPER: [%(backend)] marked [%(title)] as unplayed.', [
                            'id' => $cloned->id,
                            'backend' => $entity->via,
                            'title' => $cloned->getName(),
                            'changes' => $local->diff(),
                        ]);

                        if (null === ($this->changed[$local->id] ?? null)) {
                            $this->actions[$local->type]['updated']++;
                            Data::increment($entity->via, $local->type . '_updated');
                        }

                        $this->changed[$local->id] = $this->objects[$local->id] = $local->id;
                    } catch (PDOException $e) {
                        $this->actions[$local->type]['failed']++;
                        Data::increment($entity->via, $local->type . '_failed');
                        $this->logger->error(sprintf('MAPPER: %s', $e->getMessage()), [
                            'id' => $cloned->id,
                            'backend' => $entity->via,
                            'title' => $cloned->getName(),
                            'state' => [
                                'storage' => $cloned->getAll(),
                                'backend' => $entity->getAll()
                            ],
                        ]);
                    }

                    return $this;
                }

                /**
                 * this sometimes leads to never ending updates as data from backends conflicts.
                 * as such we have it disabled by default.
                 */
                if (true === (bool)ag($this->options, Options::MAPPER_ALWAYS_UPDATE_META)) {
                    if (true === (clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
                        try {
                            $localFields = array_merge($keys, [iFace::COLUMN_GUIDS]);

                            $entity->guids = Guid::makeVirtualGuid(
                                $entity->via,
                                ag($entity->getMetadata($entity->via), 'id')
                            );

                            $local = $local->apply(
                                entity: $entity,
                                fields: array_merge($localFields, [iFace::COLUMN_EXTRA])
                            );

                            $this->logger->notice('MAPPER: [%(backend)] updated [%(title)] metadata.', [
                                'id' => $cloned->id,
                                'backend' => $entity->via,
                                'title' => $cloned->getName(),
                                'changes' => $local->diff(fields: $localFields),
                            ]);

                            if (false === $inDryRunMode) {
                                $this->storage->update($local);
                            }

                            if (null === ($this->changed[$local->id] ?? null)) {
                                $this->actions[$local->type]['updated']++;
                                Data::increment($entity->via, $local->type . '_updated');
                            }

                            $this->changed[$local->id] = $this->objects[$local->id] = $local->id;
                        } catch (PDOException $e) {
                            $this->actions[$local->type]['failed']++;
                            Data::increment($entity->via, $local->type . '_failed');
                            $this->logger->error(sprintf('MAPPER: %s', $e->getMessage()), [
                                'id' => $cloned->id,
                                'title' => $cloned->getName(),
                                'state' => [
                                    'storage' => $cloned->getAll(),
                                    'backend' => $entity->getAll()
                                ],
                            ]);
                        }

                        return $this;
                    }
                }

                Data::increment($entity->via, $entity->type . '_ignored_not_played_since_last_sync');
                return $this;
            }
        }

        $keys = $opts['diff_keys'] ?? array_flip(
                array_keys_diff(
                    base: array_flip(iFace::ENTITY_KEYS),
                    list: iFace::ENTITY_IGNORE_DIFF_CHANGES,
                    has:  false
                )
            );

        if (true === (clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
            try {
                $local = $local->apply(entity: $entity, fields: array_merge($keys, [iFace::COLUMN_EXTRA]));

                $this->logger->notice('MAPPER: [%(backend)] Updated [%(title)].', [
                    'id' => $cloned->id,
                    'backend' => $entity->via,
                    'title' => $cloned->getName(),
                    'changes' => $local->diff(fields: $keys)
                ]);

                if (false === $inDryRunMode) {
                    $this->storage->update($local);
                }

                if (null === ($this->changed[$local->id] ?? null)) {
                    $this->actions[$local->type]['updated']++;
                    Data::increment($entity->via, $entity->type . '_updated');
                }

                $this->changed[$local->id] = $this->objects[$local->id] = $local->id;
            } catch (PDOException $e) {
                $this->actions[$local->type]['failed']++;
                Data::increment($entity->via, $local->type . '_failed');
                $this->logger->error(sprintf('MAPPER: %s', $e->getMessage()), [
                    'id' => $cloned->id,
                    'backend' => $entity->via,
                    'title' => $cloned->getName(),
                    'state' => [
                        'storage' => $cloned->getAll(),
                        'backend' => $entity->getAll()
                    ],
                ]);
            }

            return $this;
        }

        if (true === $this->inTraceMode()) {
            $this->logger->debug('MAPPER: [%(backend)] [%(title)] metadata and play state is identical.', [
                'id' => $cloned->id,
                'backend' => $entity->via,
                'title' => $cloned->getName(),
                'state' => [
                    'storage' => $cloned->getAll(),
                    'backend' => $entity->getAll(),
                ],
            ]);
        }

        Data::increment($entity->via, $entity->type . '_ignored_no_change');

        return $this;
    }

    public function get(iFace $entity): null|iFace
    {
        if (false === ($pointer = $this->getPointer($entity))) {
            return null;
        }

        if (true === ($pointer instanceof iFace)) {
            return $pointer;
        }

        $entity->id = $pointer;

        return $this->storage->get($entity);
    }

    public function remove(iFace $entity): bool
    {
        return $this->storage->remove($entity);
    }

    public function commit(): mixed
    {
        $list = $this->actions;

        $this->reset();

        return $list;
    }

    public function has(iFace $entity): bool
    {
        return null !== $this->get($entity);
    }

    public function reset(): self
    {
        $this->actions = [
            iFace::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
            iFace::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
        ];

        $this->fullyLoaded = false;
        $this->changed = $this->objects = $this->pointers = [];

        return $this;
    }

    public function getObjects(array $opts = []): array
    {
        $list = [];

        $entity = $this->options['class'] ?? Container::get(iFace::class);

        foreach ($this->objects as $id) {
            $list[] = $entity::fromArray([iFace::COLUMN_ID => $id]);
        }

        if (empty($list)) {
            return [];
        }

        return $this->storage->find(...$list);
    }

    public function getObjectsCount(): int
    {
        return count($this->objects);
    }

    public function count(): int
    {
        return count($this->changed);
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        $this->storage->setLogger($logger);
        return $this;
    }

    public function setStorage(StorageInterface $storage): self
    {
        $this->storage = $storage;
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

    protected function addPointers(iFace $entity, string|int $pointer): ImportInterface
    {
        foreach ([...$entity->getPointers(), ...$entity->getRelativePointers()] as $key) {
            $this->pointers[$key . '/' . $entity->type] = $pointer;
        }

        return $this;
    }

    /**
     * Is the object already mapped?
     *
     * @param iFace $entity
     *
     * @return iFace|int|string|bool int pointer for the object, Or false if not registered.
     */
    protected function getPointer(iFace $entity): iFace|int|string|bool
    {
        if (null !== $entity->id && null !== ($this->objects[$entity->id] ?? null)) {
            return $entity->id;
        }

        foreach ([...$entity->getRelativePointers(), ...$entity->getPointers()] as $key) {
            $lookup = $key . '/' . $entity->type;
            if (null !== ($this->pointers[$lookup] ?? null)) {
                return $this->pointers[$lookup];
            }
        }

        if (false === $this->fullyLoaded && null !== ($lazyEntity = $this->storage->get($entity))) {
            $this->objects[$lazyEntity->id] = $lazyEntity->id;

            $this->addPointers($lazyEntity, $lazyEntity->id);

            return $entity;
        }

        return false;
    }

    protected function removePointers(iFace $entity): ImportInterface
    {
        foreach ([...$entity->getPointers(), ...$entity->getRelativePointers()] as $key) {
            $lookup = $key . '/' . $entity->type;
            if (null !== ($this->pointers[$lookup] ?? null)) {
                unset($this->pointers[$lookup]);
            }
        }
        return $this;
    }
}
