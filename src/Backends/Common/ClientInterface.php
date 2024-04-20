<?php

declare(strict_types=1);

namespace App\Backends\Common;

use App\Libs\Entity\StateInterface;
use App\Libs\Exceptions\Backends\InvalidContextException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\QueueRequests;
use DateTimeInterface as iDate;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

interface ClientInterface
{
    /**
     * Initiate client with context. It **MUST** return new instance.
     *
     * @param Context $context client context.
     *
     * @return ClientInterface new instance.
     */
    public function withContext(Context $context): ClientInterface;

    /**
     * Return client context.
     *
     * @return Context client context.
     */
    public function getContext(): Context;

    /**
     * Get backend name.
     *
     * @return string backend name.
     */
    public function getName(): string;

    /**
     * Inject logger.
     *
     * @param LoggerInterface $logger logger instance.
     *
     * @return ClientInterface Returns same instance.
     */
    public function setLogger(LoggerInterface $logger): ClientInterface;

    /**
     * Process the request for attributes extraction.
     *
     * @param ServerRequestInterface $request request to process.
     * @param array $opts options for processing the request.
     *
     * @return ServerRequestInterface processed request.
     */
    public function processRequest(ServerRequestInterface $request, array $opts = []): ServerRequestInterface;

    /**
     * Parse backend webhook event.
     *
     * @param ServerRequestInterface $request request to process.
     *
     * @return StateInterface state object.
     */
    public function parseWebhook(ServerRequestInterface $request): StateInterface;

    /**
     * Import play state and metadata from backend.
     *
     * @param iImport $mapper mapper to use.
     * @param iDate|null $after only import items after this date.
     *
     * @return array<array-key,ResponseInterface> responses.
     */
    public function pull(iImport $mapper, iDate|null $after = null): array;

    /**
     * Backup play state from backend.
     *
     * @param iImport $mapper mapper to use.
     * @param StreamInterface|null $writer writer to use.
     * @param array $opts options for backup.
     *
     * @return array<array-key,ResponseInterface> responses.
     */
    public function backup(iImport $mapper, StreamInterface|null $writer = null, array $opts = []): array;

    /**
     * Export play state back to backend.
     *
     * @param iImport $mapper mapper to use.
     * @param QueueRequests $queue queue to use.
     * @param iDate|null $after only export items after this date.
     *
     * @return array<array-key,ResponseInterface> responses.
     */
    public function export(iImport $mapper, QueueRequests $queue, iDate|null $after = null): array;

    /**
     * Compare webhook queued events and push them to backend.
     *
     * @param array<StateInterface> $entities entities to push.
     * @param QueueRequests $queue queue to use.
     * @param iDate|null $after only push items after this date.
     *
     * @return array empty array. The data is pushed to the queue.
     */
    public function push(array $entities, QueueRequests $queue, iDate|null $after = null): array;

    /**
     * Compare watch progress and push to backend.
     *
     * @param array<StateInterface> $entities entities to push.
     * @param QueueRequests $queue queue to use.
     * @param iDate|null $after only push items after this date.
     *
     * @return array empty array. The data is pushed to the queue.
     */
    public function progress(array $entities, QueueRequests $queue, iDate|null $after = null): array;

    /**
     * Search backend libraries.
     *
     * @param string $query search query.
     * @param int $limit limit results.
     * @param array $opts options.
     *
     * @return array<array{
     *    id: string|int,
     *    type: string,
     *    title: string|null,
     *    year: int|null,
     *    addedAt: string|null,
     *    watchedAt: string|null,
     * }>.
     */
    public function search(string $query, int $limit = 25, array $opts = []): array;

    /**
     * Search backend for item id.
     *
     * @param string|int $id item id.
     * @param array $opts options.
     *
     * @return array empty array if not found.
     */
    public function searchId(string|int $id, array $opts = []): array;

    /**
     * Search backend for specific item metadata.
     *
     * @param string|int $id item id.
     * @param array $opts options.
     *
     * @return array empty array if not found.
     */
    public function getMetadata(string|int $id, array $opts = []): array;

    /**
     * Get Library content.
     *
     * @param string|int $id library id.
     * @param array $opts options.
     *
     * @return array empty array if no items found.
     */
    public function getLibrary(string|int $id, array $opts = []): array;

    /**
     * Get backend unique id.
     *
     * @param bool $forceRefresh force reload from backend.
     *
     * @return int|string|null return backend unique id or null if not supported.
     */
    public function getIdentifier(bool $forceRefresh = false): int|string|null;

    /**
     * Return list of backend users.
     *
     * @param array $opts options.
     *
     * @return array empty array if not supported.
     *
     * @throws JsonException May throw if json decoding fails.
     * @throws ExceptionInterface May be thrown if there is HTTP request errors.
     */
    public function getUsersList(array $opts = []): array;

    /**
     * Return list of backend libraries.
     *
     * @param array $opts options.
     *
     * @return array
     */
    public function listLibraries(array $opts = []): array;

    /**
     * Parse client specific options from request.
     *
     * @param array $config The already pre-filled config.
     * @param ServerRequestInterface $request request to parse.
     *
     * @return array Return updated config.
     */
    public function fromRequest(array $config, ServerRequestInterface $request): array;

    /**
     * Validate backend context.
     *
     * @param Context $context context to validate.
     *
     * @return bool Returns true if context is valid.
     * @throws InvalidContextException if unable to validate context.
     */
    public function validateContext(Context $context): bool;

    /**
     * Add/Edit Backend.
     *
     * @param array $backend backend data.
     * @param array $opts options.
     *
     * @return array Returns backend with appended backend specific data.
     */
    public static function manage(array $backend, array $opts = []): array;

    /**
     * Return list of active sessions.
     *
     * @param array $opts (Optional) options.
     * @return array{sessions: array<array>}
     */
    public function getSessions(array $opts = []): array;

    /**
     * Return user access token.
     *
     * @param int|string $userId user id.
     * @param string $username username.
     *
     * @return string|bool return user token as string or bool(false) if not supported.
     */
    public function getUserToken(int|string $userId, string $username): string|bool;

    /**
     * Get backend info.
     *
     * @param array $opts options.
     *
     * @return array
     */
    public function getInfo(array $opts = []): array;

    /**
     * Get backend version.
     *
     * @param array $opts options.
     *
     * @return string backend version.
     */
    public function getVersion(array $opts = []): string;

}
