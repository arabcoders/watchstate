<?php

declare(strict_types=1);

namespace App\Backends\Common;

use App\Libs\Entity\StateInterface;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\QueueRequests;
use DateTimeInterface as iDate;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use SplFileObject;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

interface ClientInterface
{
    /**
     * Initiate Client with context. It **MUST** return new instance.
     *
     * @param Context $context
     *
     * @return ClientInterface
     */
    public function withContext(Context $context): ClientInterface;

    /**
     * Return client context.
     *
     * @return Context
     */
    public function getContext(): Context;

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
     * @return ClientInterface
     */
    public function setLogger(LoggerInterface $logger): ClientInterface;

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
     * Parse backend webhook event.
     *
     * @param ServerRequestInterface $request
     * @return StateInterface
     */
    public function parseWebhook(ServerRequestInterface $request): StateInterface;

    /**
     * Import metadata & play state.
     *
     * @param iImport $mapper
     * @param iDate|null $after
     *
     * @return array<array-key,ResponseInterface>
     */
    public function pull(iImport $mapper, iDate|null $after = null): array;

    /**
     * Backup play state.
     *
     * @param iImport $mapper
     * @param SplFileObject|null $writer
     * @param array $opts
     *
     * @return array<array-key,ResponseInterface>
     */
    public function backup(iImport $mapper, SplFileObject|null $writer = null, array $opts = []): array;

    /**
     * Compare play state and export.
     *
     * @param iImport $mapper
     * @param QueueRequests $queue
     * @param iDate|null $after
     *
     * @return array<array-key,ResponseInterface>
     */
    public function export(iImport $mapper, QueueRequests $queue, iDate|null $after = null): array;

    /**
     * Compare webhook queued events and push.
     *
     * @param array<StateInterface> $entities
     * @param QueueRequests $queue
     * @param iDate|null $after
     *
     * @return array
     */
    public function push(array $entities, QueueRequests $queue, iDate|null $after = null): array;

    /**
     * Search backend libraries.
     *
     * @param string $query
     * @param int $limit
     * @param array $opts
     *
     * @return array
     */
    public function search(string $query, int $limit = 25, array $opts = []): array;

    /**
     * Search backend for item id.
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
     * Get backend unique id.
     *
     * @param bool $forceRefresh force reload from backend.
     *
     * @return int|string|null
     */
    public function getIdentifier(bool $forceRefresh = false): int|string|null;

    /**
     * Return list of backend users.
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
     * Return list of backend libraries.
     *
     * @param array $opts
     *
     * @return array
     */
    public function listLibraries(array $opts = []): array;
}
