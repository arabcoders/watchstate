<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Data;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Options;
use App\Libs\Storage\StorageInterface;
use DateTimeInterface;
use PDOException;
use Psr\Log\LoggerInterface;

final class MemoryMapper implements ImportInterface
{
    protected const GUID = 'local_db://';

    /**
     * @var array<int,iFace> Entities table.
     */
    protected array $objects = [];

    /**
     * @var array<string,int> Map GUIDs to entities.
     */
    protected array $pointers = [];

    /**
     * @var array<int,int> List Changed Entities.
     */
    protected array $changed = [];

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

        foreach ($this->storage->getAll($date, opts: ['class' => $this->options['class'] ?? null]) as $entity) {
            $pointer = self::GUID . $entity->id;

            if (null !== ($this->objects[$pointer] ?? null)) {
                continue;
            }

            $this->objects[$pointer] = $entity;
            $this->addPointers($this->objects[$pointer], $pointer);
        }

        $this->logger->info('MAPPER: Preloaded [%(pointers)] pointer and [%(objects)] object into memory.', [
            'pointers' => number_format(count($this->pointers)),
            'objects' => number_format(count($this->objects)),
        ]);

        return $this;
    }

    public function add(iFace $entity, array $opts = []): self
    {
        if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
            $this->logger->warning('MAPPER: Ignoring [%(backend)] [%(title)] no valid/supported external ids.', [
                'id' => $entity->id,
                'backend' => $entity->via,
                'title' => $entity->getName(),
            ]);
            Data::increment($entity->via, $entity->type . '_failed_no_guid');
            return $this;
        }

        $metadataOnly = true === (bool)ag($opts, Options::IMPORT_METADATA_ONLY);

        /**
         * Handle new item logic here.
         * if getPointer return false, it means most likely the item is not found in storage.
         */
        if (false === ($pointer = $this->getPointer($entity))) {
            if (true === $metadataOnly) {
                Data::increment($entity->via, $entity->type . '_failed');
                $this->logger->notice('MAPPER: Ignoring [%(backend)] [%(title)]. Does not exist in storage.', [
                    'metaOnly' => true,
                    'backend' => $entity->via,
                    'title' => $entity->getName(),
                    'data' => $entity->getAll(),
                ]);
                return $this;
            }

            $this->objects[] = $entity;
            $pointer = array_key_last($this->objects);

            $this->changed[$pointer] = $pointer;

            Data::increment($entity->via, $entity->type . '_added');
            $this->addPointers($this->objects[$pointer], $pointer);

            if (true === $this->inTraceMode()) {
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

            $this->logger->notice('MAPPER: [%(backend)] added [%(title)] as new item.', [
                'backend' => $entity->via,
                'title' => $entity->getName(),
                true === $this->inTraceMode() ? 'trace' : 'metadata' => $data,
            ]);

            return $this;
        }

        $local = &$this->objects[$pointer];
        $keys = [iFace::COLUMN_META_DATA];

        /**
         * DO NOT operate directly on this object it should be cloned.
         * It should maintain pristine condition until changes are committed.
         */
        $cloned = clone $this->objects[$pointer];

        /**
         * ONLY update backend metadata as requested by caller.
         */
        if (true === $metadataOnly) {
            if (true === (clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
                $localFields = array_merge($keys, [iFace::COLUMN_GUIDS]);
                $this->changed[$pointer] = $pointer;
                Data::increment($entity->via, $entity->type . '_updated');

                $entity->guids = Guid::makeVirtualGuid(
                    $entity->via,
                    ag($entity->getMetadata($entity->via), iFace::COLUMN_ID)
                );

                $local = $local->apply(entity: $entity, fields: array_merge($localFields, [iFace::COLUMN_EXTRA]));

                $this->removePointers($cloned)->addPointers($local, $pointer);

                $this->logger->notice('MAPPER: [%(backend)] updated [%(title)] metadata.', [
                    'id' => $cloned->id,
                    'backend' => $entity->via,
                    'title' => $cloned->getName(),
                    'changes' => $local->diff(fields: $localFields)
                ]);

                return $this;
            }

            if (true === $this->inTraceMode()) {
                $this->logger->info('MAPPER: [%(backend)] [%(title)] No metadata changes detected.', [
                    'id' => $cloned->id,
                    'backend' => $entity->via,
                    'title' => $cloned->getName(),
                ]);
            }

            return $this;
        }

        // -- Item date is older than recorded last sync date logic handling.
        if (null !== ($opts['after'] ?? null) && true === ($opts['after'] instanceof DateTimeInterface)) {
            if ($opts['after']->getTimestamp() >= $entity->updated) {
                // -- Handle mark as unplayed logic.
                if (false === $entity->isWatched() && true === $cloned->shouldMarkAsUnplayed(backend: $entity)) {
                    $this->changed[$pointer] = $pointer;
                    Data::increment($entity->via, $entity->type . '_updated');

                    $local = $local->apply(
                        entity: $entity,
                        fields: array_merge($keys, [iFace::COLUMN_EXTRA])
                    )->markAsUnplayed(backend: $entity);

                    $this->logger->notice('MAPPER: [%(backend)] marked [%(title)] as unplayed.', [
                        'id' => $cloned->id,
                        'backend' => $entity->via,
                        'title' => $cloned->getName(),
                        'changes' => $local->diff(array_merge($keys, [iFace::COLUMN_WATCHED, iFace::COLUMN_UPDATED])),
                    ]);

                    return $this;
                }

                /**
                 * this sometimes leads to never ending updates as data from backends conflicts.
                 * as such we have it disabled by default.
                 */
                if (true === (bool)ag($this->options, Options::MAPPER_ALWAYS_UPDATE_META)) {
                    if (true === (clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
                        $localFields = array_merge($keys, [iFace::COLUMN_GUIDS]);
                        $this->changed[$pointer] = $pointer;
                        Data::increment($entity->via, $entity->type . '_updated');

                        $entity->guids = Guid::makeVirtualGuid(
                            $entity->via,
                            ag($entity->getMetadata($entity->via), iFace::COLUMN_ID)
                        );

                        $local = $local->apply(
                            entity: $entity,
                            fields: array_merge($localFields, [iFace::COLUMN_EXTRA])
                        );

                        $this->removePointers($cloned)->addPointers($local, $pointer);

                        $this->logger->notice('MAPPER: [%(backend)] updated [%(title)] metadata.', [
                            'id' => $cloned->id,
                            'backend' => $entity->via,
                            'title' => $cloned->getName(),
                            'changes' => $local::fromArray($cloned->getAll())->apply(
                                entity: $entity,
                                fields: $localFields
                            )->diff(fields: $keys),
                            'fields' => implode(',', $localFields),
                        ]);

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
            $this->changed[$pointer] = $pointer;
            Data::increment($entity->via, $entity->type . '_updated');

            $local = $local->apply(entity: $entity, fields: array_merge($keys, [iFace::COLUMN_EXTRA]));
            $this->removePointers($cloned)->addPointers($local, $pointer);

            $this->logger->notice('MAPPER: [%(backend)] Updated [%(title)].', [
                'id' => $cloned->id,
                'backend' => $entity->via,
                'title' => $cloned->getName(),
                'changes' => $local::fromArray($cloned->getAll())->apply(entity: $entity, fields: $keys)->diff(
                    fields: $keys
                ),
                'fields' => implode(', ', $keys),
            ]);

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
        return false === ($pointer = $this->getPointer($entity)) ? null : $this->objects[$pointer];
    }

    public function remove(iFace $entity): bool
    {
        if (false === ($pointer = $this->getPointer($entity))) {
            return false;
        }

        $this->storage->remove($this->objects[$pointer]);

        $this->removePointers($this->objects[$pointer]);

        unset($this->objects[$pointer]);

        if (null !== ($this->changed[$pointer] ?? null)) {
            unset($this->changed[$pointer]);
        }

        return true;
    }

    public function commit(): mixed
    {
        $state = $this->storage->transactional(function (StorageInterface $storage) {
            $list = [
                iFace::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
                iFace::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
            ];

            $count = count($this->changed);

            if (0 === $count) {
                $this->logger->notice('MAPPER: No changes detected.');
                return $list;
            }
            $inDryRunMode = $this->inDryRunMode();

            if (true === $inDryRunMode) {
                $this->logger->notice('MAPPER: Recorded [%(total)] object changes.', [
                    'total' => $count
                ]);
            }

            foreach ($this->changed as $pointer) {
                try {
                    $entity = &$this->objects[$pointer];

                    if (null === $entity->id) {
                        if (false === $inDryRunMode) {
                            $storage->insert($entity);
                        }
                        $list[$entity->type]['added']++;
                    } else {
                        if (false === $inDryRunMode) {
                            $storage->update($entity);
                        }
                        $list[$entity->type]['updated']++;
                    }
                } catch (PDOException $e) {
                    $list[$entity->type]['failed']++;
                    $this->logger->error($e->getMessage(), $entity->getAll());
                }
            }

            return $list;
        });

        $this->reset();

        return $state;
    }

    public function has(iFace $entity): bool
    {
        return null !== $this->get($entity);
    }

    public function reset(): self
    {
        $this->fullyLoaded = false;
        $this->objects = $this->changed = $this->pointers = [];

        return $this;
    }

    public function getObjects(array $opts = []): array
    {
        return $this->objects;
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

    public function __destruct()
    {
        if (false === (bool)ag($this->options, Options::MAPPER_DISABLE_AUTOCOMMIT) && $this->count() >= 1) {
            $this->commit();
        }
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
     * @return int|string|bool int pointer for the object, Or false if not registered.
     */
    protected function getPointer(iFace $entity): int|string|bool
    {
        if (null !== $entity->id && null !== ($this->objects[self::GUID . $entity->id] ?? null)) {
            return self::GUID . $entity->id;
        }

        // -- Prioritize relative ids for episodes, External ids are often incorrect for episodes.
        if (true === $entity->isEpisode()) {
            foreach ($entity->getRelativePointers() as $key) {
                $lookup = $key . '/' . $entity->type;
                if (null !== ($this->pointers[$lookup] ?? null)) {
                    return $this->pointers[$lookup];
                }
            }
        }

        // -- look up movies based on guid.
        // -- if episode didn't have any match using relative id then fallback to external ids.
        foreach ($entity->getPointers() as $key) {
            $lookup = $key . '/' . $entity->type;
            if (null !== ($this->pointers[$lookup] ?? null)) {
                return $this->pointers[$lookup];
            }
        }

        if (false === $this->fullyLoaded && null !== ($lazyEntity = $this->storage->get($entity))) {
            $this->objects[self::GUID . $entity->id] = $lazyEntity;

            $this->addPointers($this->objects[self::GUID . $entity->id], self::GUID . $entity->id);

            return self::GUID . $entity->id;
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
