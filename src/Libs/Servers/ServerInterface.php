<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Entity\StateInterface;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Mappers\ImportInterface;
use DateTimeInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

interface ServerInterface
{
    public const OPT_IMPORT_UNWATCHED = 'importUnwatched';
    public const OPT_EXPORT_IGNORE_DATE = 'exportIgnoreDate';

    /**
     * Initiate server. It should return **NEW OBJECT**
     *
     * @param string $name Server name
     * @param UriInterface $url Server url
     * @param null|int|string $token Server Token
     * @param null|int|string $userId Server user Id
     * @param array $persist persistent data saved by server.
     * @param array $options array of options.
     *
     * @return self
     */
    public function setUp(
        string $name,
        UriInterface $url,
        null|string|int $token = null,
        null|string|int $userId = null,
        array $persist = [],
        array $options = []
    ): self;

    /**
     * Inject logger.
     *
     * @param LoggerInterface $logger
     *
     * @return ServerInterface
     */
    public function setLogger(LoggerInterface $logger): ServerInterface;

    /**
     * Process The request For attributes extraction.
     *
     * @param ServerRequestInterface $request
     * @return ServerRequestInterface
     */
    public static function processRequest(ServerRequestInterface $request): ServerRequestInterface;

    /**
     * Parse server specific webhook event. for play/unplayed event.
     *
     * @param ServerRequestInterface $request
     * @return StateInterface
     */
    public function parseWebhook(ServerRequestInterface $request): StateInterface;

    /**
     * Import watch state.
     *
     * @param ImportInterface $mapper
     * @param DateTimeInterface|null $after
     *
     * @return array<array-key,ResponseInterface>
     */
    public function pull(ImportInterface $mapper, DateTimeInterface|null $after = null): array;

    /**
     * Export watch state to server.
     *
     * @param ExportInterface $mapper
     * @param DateTimeInterface|null $after
     *
     * @return array<array-key,ResponseInterface>
     */
    public function export(ExportInterface $mapper, DateTimeInterface|null $after = null): array;

    /**
     * Push webhook queued states.
     *
     * @param array<StateInterface> $entities
     * @param DateTimeInterface|null $after
     *
     * @return array
     */
    public function push(array $entities, DateTimeInterface|null $after = null): array;

    /**
     * Get all persistent data.
     *
     * @return array
     */
    public function getPersist(): array;

    /**
     * Add persistent data to config.
     *
     * @param string $key
     * @param mixed $value
     * @return ServerInterface
     */
    public function addPersist(string $key, mixed $value): ServerInterface;

    /**
     * Get Server Unique ID.
     *
     * @return int|string|null
     */
    public function getServerUUID(): int|string|null;

    /**
     * Return List of users from server.
     *
     * @param array $server server bucket.
     *
     * @return array|null null if not implemented or backend does not support users.
     */
    public function getUsersList(array $server = []): array|null;
}
