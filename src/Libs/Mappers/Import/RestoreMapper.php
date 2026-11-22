<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\StreamableChunks;
use App\Libs\Guid;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Stream;
use App\Libs\UserContext;
use DateTimeInterface as iDate;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;

/**
 * Class RestoreMapper
 *
 * This class is responsible for mapping and manipulating entities during the restore backup process.
 *
 * @implements iImport
 */
final class RestoreMapper implements iImport
{
    /**
     * @var array<int,iState> In memory entities list.
     */
    protected array $objects = [];

    /**
     * @var array<string|int,iState> Backup items matched to destination IDs for progress restore.
     */
    private array $progressItems = [];

    /**
     * @var array<string,int> Map entity pointers to object pointers.
     */
    protected array $pointers = [];

    /**
     * @var array<string,mixed> Mapper options.
     */
    protected array $options = [];

    /**
     * @var UserContext|null The User Context
     */
    protected ?UserContext $userContext = null;

    /**
     * Class constructor.
     *
     * @param iLogger $logger An instance of the iLogger interface.
     * @param string|Stream $file The file or stream to be used by the constructor.
     * @param bool $restoreProgress Collect destination matches for saved resume positions.
     *
     * @return void
     */
    public function __construct(
        private iLogger $logger,
        private Stream|string $file,
        private readonly bool $restoreProgress = false,
    ) {}

    /**
     * @inheritdoc
     */
    public function withDB(iDB $db): self
    {
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function withCache(iCache $cache): self
    {
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function withUserContext(UserContext $userContext): self
    {
        $instance = clone $this;
        $instance->userContext = $userContext;
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
    public function setOptions(array $options = []): iImport
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function withOptions(array $options = []): iImport
    {
        $instance = clone $this;
        $instance->options = $options;
        return $instance;
    }

    /**
     * @inheritdoc
     * @throws \JsonMachine\Exception\InvalidArgumentException If unexpected things happen while loading the JSON file.
     */
    public function loadData(?iDate $date = null): self
    {
        if (false === $this->file instanceof Stream) {
            if (true === str_ends_with($this->file, '.zip')) {
                /** @noinspection PhpUnusedLocalVariableInspection */
                [$stream, $_] = read_file_from_archive($this->file, '*.json');
            } else {
                $stream = Stream::make($this->file, 'r');
            }
        } else {
            $stream = $this->file;
        }

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $it = new Items(
            bytesIterator: new StreamableChunks(
                stream: $stream,
                chunkSize: (int) ag($this->options, 'chunkSize', 1024 * 8),
            ),
            options: [
                'decoder' => new ErrorWrappingDecoder(new ExtJsonDecoder(true, JSON_INVALID_UTF8_IGNORE)),
            ],
        );

        $state = new StateEntity([]);

        foreach ($it as $entity) {
            if ($entity instanceof DecodingError) {
                continue;
            }

            $entity[iState::COLUMN_VIA] = 'backup_file';

            if (null !== ($entity[iState::COLUMN_GUIDS] ?? null)) {
                $entity[iState::COLUMN_GUIDS] = Guid::fromArray($entity[iState::COLUMN_GUIDS])->getAll();
            }

            if (null !== ($entity[iState::COLUMN_PARENT] ?? null)) {
                $entity[iState::COLUMN_PARENT] = Guid::fromArray($entity[iState::COLUMN_PARENT])->getAll();
            }

            if (null !== ($progress = $entity[iState::COLUMN_META_DATA_PROGRESS] ?? null)) {
                $entity[iState::COLUMN_META_DATA] = [
                    $entity[iState::COLUMN_VIA] => [
                        iState::COLUMN_META_DATA_PROGRESS => (string) $progress,
                    ],
                ];
                $entity[iState::COLUMN_EXTRA] = [
                    $entity[iState::COLUMN_VIA] => [
                        iState::COLUMN_EXTRA_EVENT => 'task.backup',
                        iState::COLUMN_EXTRA_DATE => time(),
                    ],
                ];
            }

            $item = $state::fromArray($entity);

            $this->add($item);
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function add(iState $entity, array $opts = []): self
    {
        if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
            $this->logger->debug("Ignoring '#{history.id}: {history.title}', no external guids.", [
                'mapper' => after_last(self::class, '\\'),
                'history' => [
                    'id' => $entity->id ?? 'New',
                    'title' => $entity->getName(),
                ],
            ]);
            return $this;
        }

        if (false === $this->getPointer($entity)) {
            $this->objects[] = $entity;
            $pointer = array_key_last($this->objects);
            $this->addPointers($this->objects[$pointer], $pointer);
        }

        return $this;
    }

    /**
     * @inheritdoc
     *
     * Retain destination IDs during export without copying destination progress into the backup.
     */
    public function get(iState $entity): ?iState
    {
        if (false === ($pointer = $this->getPointer($entity))) {
            return null;
        }

        $item = $this->objects[$pointer];

        if (true === $this->restoreProgress && 0 < $item->getPlayProgress()) {
            $id = $entity->getMetadata($entity->via)[iState::COLUMN_ID] ?? null;
            if (is_string($id) && '' !== $id) {
                $this->progressItems[$id] = (clone $item)->setMeta(iState::COLUMN_ID, $id, $entity->via);
            }
        }

        return $item;
    }

    /**
     * Return matched backup items with a restorable resume position.
     *
     * @return array<string|int,iState>
     */
    public function getProgressItems(): array
    {
        return $this->progressItems;
    }

    /**
     * @inheritdoc
     */
    public function remove(iState $entity): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function commit(): mixed
    {
        $this->reset();

        return [];
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
        $this->objects = $this->pointers = $this->progressItems = [];

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
        return 0;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(iLogger $logger): self
    {
        $this->logger = $logger;
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
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function inDryRunMode(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function inTraceMode(): bool
    {
        return false;
    }

    /**
     * Add pointers for the given entity.
     *
     * @param iState $entity The entity for which to add pointers.
     * @param string|int $pointer The pointer to database object id.
     *
     * @return iImport Returns the current iImport instance.
     */
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
     * Gets the pointer for a given entity.
     *
     * @param iState $entity The entity for which to get the pointer.
     *
     * @return int|string|bool Returns the pointer if found, otherwise returns false.
     */
    protected function getPointer(iState $entity): int|string|bool
    {
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

        return false;
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
        return [];
    }

    public function computeChanges(array $backends): array
    {
        return [];
    }
}
