<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Data;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Options;
use App\Libs\Storage\StorageInterface;
use DateTimeInterface;
use PDOException;
use Psr\Log\LoggerInterface;

final class MemoryMapper implements ImportInterface
{
    private const GUID = 'local_db://';

    /**
     * @var array<int,iFace> Entities table.
     */
    private array $objects = [];

    /**
     * @var array<string,int> Map GUIDs to entities.
     */
    private array $guids = [];

    /**
     * @var array<int,int> List Changed Entities.
     */
    private array $changed = [];

    private array $options = [];

    private bool $fullyLoaded = false;

    public function __construct(private LoggerInterface $logger, private StorageInterface $storage)
    {
    }

    public function setUp(array $opts): ImportInterface
    {
        $this->options = $opts;

        return $this;
    }

    public function loadData(DateTimeInterface|null $date = null): self
    {
        $this->fullyLoaded = null === $date;

        foreach ($this->storage->getAll($date, $this->options['class'] ?? null) as $entity) {
            $pointer = self::GUID . $entity->id;

            if (null !== ($this->objects[$pointer] ?? null)) {
                continue;
            }

            $this->objects[$pointer] = $entity;
            $this->addPointers($this->objects[$pointer], $pointer);
        }

        return $this;
    }

    public function add(string $bucket, string $name, iFace $entity, array $opts = []): self
    {
        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $this->logger->warning(
                sprintf(
                    '%s: Ignoring \'%s\'. No valid/supported external ids.',
                    $bucket,
                    $entity->getName()
                )
            );
            Data::increment($bucket, $entity->type . '_failed_no_guid');
            return $this;
        }

        if (false === ($pointer = $this->getPointer($entity))) {
            $this->objects[] = $entity;

            $pointer = array_key_last($this->objects);
            $this->changed[$pointer] = $pointer;

            Data::increment($bucket, $entity->type . '_added');
            $this->addPointers($this->objects[$pointer], $pointer);

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

            $this->logger->notice(
                sprintf(
                    '%s: Adding \'%s\'. As new Item.',
                    $bucket,
                    $entity->getName()
                ),
                $data
            );

            return $this;
        }

        // -- Item date is older than recorded last sync date,
        if (null !== ($opts['after'] ?? null) && true === ($opts['after'] instanceof DateTimeInterface)) {
            if ($opts['after']->getTimestamp() >= $entity->updated) {
                $keys = [iFace::COLUMN_META_DATA];

                // -- Handle mark as unplayed logic.
                if (false === $entity->isWatched()) {
                    $cloned = clone $this->objects[$pointer];
                    if (true === $cloned->shouldMarkAsUnplayed($entity)) {
                        $this->objects[$pointer] = $this->objects[$pointer]->apply(
                            entity: $entity,
                            fields: $keys
                        )->markAsUnplayed($entity);
                        $this->changed[$pointer] = $pointer;
                        Data::increment($bucket, $entity->type . '_updated');

                        $this->logger->notice(
                            sprintf('%s: Updating \'%s\'. Item marked as unplayed.', $bucket, $entity->getName()),
                            $this->objects[$pointer]->diff(
                                fields: [iFace::COLUMN_UPDATED, iFace::COLUMN_WATCHED, iFace::COLUMN_META_DATA]
                            ),
                        );

                        return $this;
                    }
                }

                // -- this sometimes leads to never ending updates as data from backends conflicts.
                // -- as such we have it disabled by default.

                if (true === (bool)ag($this->options, Options::MAPPER_ALWAYS_UPDATE_META)) {
                    $cloned = clone $this->objects[$pointer];

                    if (true === $cloned->apply($entity, $keys)->isChanged($keys)) {
                        Data::increment($bucket, $entity->type . '_updated');
                        $this->changed[$pointer] = $pointer;

                        $this->removePointers($this->objects[$pointer]);

                        $this->objects[$pointer] = $this->objects[$pointer]->apply(
                            entity: $entity,
                            fields: $keys
                        );

                        $this->addPointers($this->objects[$pointer], $pointer);

                        $this->logger->notice(
                            sprintf(
                                '%s: Updating \'%s\'. Metadata field.',
                                $bucket,
                                $this->objects[$pointer]->getName()
                            ),
                            $this->objects[$pointer]->diff(fields: $keys),
                        );

                        return $this;
                    }
                }

                Data::increment($bucket, $entity->type . '_ignored_not_played_since_last_sync');
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

        $cloned = clone $this->objects[$pointer];

        if (true === $cloned->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
            Data::increment($bucket, $entity->type . '_updated');
            $this->changed[$pointer] = $pointer;

            $this->removePointers($this->objects[$pointer]);

            $this->objects[$pointer]->apply(entity: $entity, fields: $keys);
            $this->addPointers($this->objects[$pointer], $pointer);

            $this->logger->notice(
                sprintf('%s: Updating \'%s\'. State changed.', $bucket, $this->objects[$pointer]->getName()),
                $this->objects[$pointer]->diff(fields: $keys),
            );

            return $this;
        }

        if ($this->inTraceMode()) {
            $this->logger->debug(
                sprintf('%s: \'%s\'. is identical.', $bucket, $entity->getName()),
                [
                    'backend' => $cloned->getAll(),
                    'remote' => $entity->getAll(),
                ]
            );
        }

        Data::increment($bucket, $entity->type . '_ignored_no_change');

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

            $this->logger->notice(
                0 === $count ? 'MAPPER: No changes detected.' : sprintf('MAPPER: Updating \'%d\' db records.', $count)
            );

            $inDryRunMode = $this->inDryRunMode();

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
        $this->objects = $this->changed = $this->guids = [];

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

    private function addPointers(iFace $entity, string|int $pointer): void
    {
        foreach ([...$entity->getPointers(), ...$entity->getRelativePointers()] as $key) {
            $this->guids[$key . '/' . $entity->type] = $pointer;
        }

        foreach ($entity->metadata ?? [] as $backend => $meta) {
            if (null === ($meta[iFace::COLUMN_ID] ?? null)) {
                continue;
            }

            $key = self::GUID . $backend . '-' . $entity->metadata[$backend][iFace::COLUMN_ID];

            $this->guids[$key] = $pointer;
        }
    }

    /**
     * Is the object already mapped?
     *
     * @param iFace $entity
     *
     * @return int|string|bool int pointer for the object, Or false if not registered.
     */
    private function getPointer(iFace $entity): int|string|bool
    {
        if (null !== $entity->id && null !== ($this->objects[self::GUID . $entity->id] ?? null)) {
            return self::GUID . $entity->id;
        }

        if (!empty($entity->via) && null !== ($entity->metadata[$entity->via][iFace::COLUMN_ID] ?? null)) {
            $key = self::GUID . $entity->via . '-' . $entity->metadata[$entity->via][iFace::COLUMN_ID];

            if (null !== ($this->guids[$key] ?? null)) {
                return $this->guids[$key];
            }
        }

        foreach ([...$entity->getRelativePointers(), ...$entity->getPointers()] as $key) {
            $lookup = $key . '/' . $entity->type;
            if (null !== ($this->guids[$lookup] ?? null)) {
                return $this->guids[$lookup];
            }
        }

        if (false === $this->fullyLoaded && null !== ($lazyEntity = $this->storage->get($entity))) {
            $this->objects[self::GUID . $entity->id] = $lazyEntity;

            $this->addPointers($this->objects[self::GUID . $entity->id], self::GUID . $entity->id);

            return self::GUID . $entity->id;
        }

        return false;
    }

    private function removePointers(iFace $entity): void
    {
        foreach ([...$entity->getPointers(), ...$entity->getRelativePointers()] as $key) {
            $lookup = $key . '/' . $entity->type;
            if (null !== ($this->guids[$lookup] ?? null)) {
                unset($this->guids[$lookup]);
            }
        }

        foreach ($entity->metadata ?? [] as $backend => $meta) {
            if (null === ($meta[iFace::COLUMN_ID] ?? null)) {
                continue;
            }

            unset($this->guids[self::GUID . $backend . '-' . $meta[iFace::COLUMN_ID]]);
        }
    }

}
