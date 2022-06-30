<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Entity\StateInterface;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\QueueRequests;
use DateTimeInterface as iDate;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use SplFileObject;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

interface ServerInterface
{
    /**
     * Initiate server. It should return **NEW OBJECT**
     *
     * @param string $name Server name
     * @param UriInterface $url Server url
     * @param null|int|string $token Server Token
     * @param null|int|string $userId Server user Id
     * @param string|int|null $uuid
     * @param array $options array of options.
     *
     * @return self
     */
    public function setUp(
        string $name,
        UriInterface $url,
        null|string|int $token = null,
        null|string|int $userId = null,
        null|string|int $uuid = null,
        array $options = []
    ): self;

    /**
     * Get Backend name.
     *
     * @return string
     */
    public function getName(): string;

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
     * @param array $opts
     *
     * @return ServerRequestInterface
     */
    public function processRequest(ServerRequestInterface $request, array $opts = []): ServerRequestInterface;

    /**
     * Parse server specific webhook event. for play/un-played event.
     *
     * @param ServerRequestInterface $request
     * @return StateInterface
     */
    public function parseWebhook(ServerRequestInterface $request): StateInterface;

    /**
     * Import watch state.
     *
     * @param iImport $mapper
     * @param iDate|null $after
     *
     * @return array<array-key,ResponseInterface>
     */
    public function pull(iImport $mapper, iDate|null $after = null): array;

    /**
     * Backup watch state.
     *
     * @param iImport $mapper
     *
     * @return array<array-key,ResponseInterface>
     */
    public function backup(iImport $mapper, SplFileObject $writer, array $opts = []): array;

    /**
     * Export watch state to server.
     *
     * @param iImport $mapper
     * @param QueueRequests $queue
     * @param iDate|null $after
     *
     * @return array<array-key,ResponseInterface>
     */
    public function export(iImport $mapper, QueueRequests $queue, iDate|null $after = null): array;

    /**
     * Push webhook queued states.
     *
     * @param array<StateInterface> $entities
     * @param QueueRequests $queue
     * @param iDate|null $after
     *
     * @return array
     */
    public function push(array $entities, QueueRequests $queue, iDate|null $after = null): array;

    /**
     * Search server libraries.
     *
     * @param string $query
     * @param int $limit
     * @param array $opts
     *
     * @return array
     */
    public function search(string $query, int $limit = 25, array $opts = []): array;

    /**
     * Server Backend id.
     *
     * @param string|int $id
     * @param array $opts
     *
     * @return array
     */
    public function searchId(string|int $id, array $opts = []): array;

    /**
     * Get Specific item metadata.
     *
     * @param string|int $id
     * @param array $opts
     *
     * @return array
     */
    public function getMetadata(string|int $id, array $opts = []): array;

    /**
     * Get Library content.
     *
     * @param string|int $id
     * @param array $opts
     *
     * @return array
     */
    public function getLibrary(string|int $id, array $opts = []): array;

    /**
     * Get Server Unique ID.
     *
     * @param bool $forceRefresh force read uuid from server.
     *
     * @return int|string|null
     *
     * @throws JsonException May throw if json decoding fails.
     * @throws ExceptionInterface May be thrown if there is HTTP request errors.
     */
    public function getServerUUID(bool $forceRefresh = false): int|string|null;

    /**
     * Return List of users from server.
     *
     * @param array $opts
     *
     * @return array empty error if not supported.
     *
     * @throws JsonException May throw if json decoding fails.
     * @throws ExceptionInterface May be thrown if there is HTTP request errors.
     */
    public function getUsersList(array $opts = []): array;

    /**
     * Return list of server libraries.
     *
     * @param array $opts
     *
     * @return array
     */
    public function listLibraries(array $opts = []): array;
}
