<?php

declare(strict_types=1);

namespace App\Libs\Mappers;

use App\Libs\Entity\StateEntity;
use App\Libs\Storage\StorageInterface;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

interface ExportInterface
{
    /**
     * Initiate Export Mapper.
     *
     * @param array $opts
     *
     * @return self
     */
    public function setUp(array $opts): self;

    /**
     * Load data from storage.
     *
     * @param DateTimeInterface|null $date
     *
     * @return self
     */
    public function loadData(DateTimeInterface|null $date = null): self;

    /**
     * Get All Queued Entities.
     *
     * @return array<string,array<int|string,StateEntity>
     */
    public function getQueue(): array;

    /**
     * Queue State change request.
     *
     * @param ResponseInterface $request
     *
     * @return self
     */
    public function queue(ResponseInterface $request): self;

    /**
     * Inject Logger.
     *
     * @param LoggerInterface $logger
     *
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self;

    /**
     * Inject Storage.
     *
     * @param StorageInterface $storage
     *
     * @return self
     */
    public function SetStorage(StorageInterface $storage): self;

    /**
     * Get Entity.
     *
     * @param StateEntity $entity
     *
     * @return null|StateEntity
     */
    public function get(StateEntity $entity): null|StateEntity;

    /**
     * Find Entity By Ids.
     *
     * @param array $ids
     *
     * @return StateEntity|null
     */
    public function findByIds(array $ids): null|StateEntity;

    /**
     * Has Entity.
     *
     * @param StateEntity $entity
     *
     * @return bool
     */
    public function has(StateEntity $entity): bool;
}
