<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Backends\Common\Cache;
use App\Backends\Common\Context;
use App\Backends\Jellyfin\Action\Export;
use App\Backends\Jellyfin\Action\GetIdentifier;
use App\Backends\Jellyfin\Action\GetLibrariesList;
use App\Backends\Jellyfin\Action\GetLibrary;
use App\Backends\Jellyfin\Action\GetUsersList;
use App\Backends\Jellyfin\Action\Import;
use App\Backends\Jellyfin\Action\InspectRequest;
use App\Backends\Jellyfin\Action\ParseWebhook;
use App\Backends\Jellyfin\Action\Push;
use App\Backends\Jellyfin\Action\SearchId;
use App\Backends\Jellyfin\Action\SearchQuery;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\HttpException;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class JellyfinServer implements ServerInterface
{
    use JellyfinActionTrait;

    public const NAME = 'JellyfinBackend';
    protected Context|null $context = null;

    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
        protected Cache $cache,
        protected JellyfinGuid $guid,
    ) {
    }

    public function setUp(
        string $name,
        UriInterface $url,
        string|int|null $token = null,
        string|int|null $userId = null,
        string|int|null $uuid = null,
        array $options = []
    ): ServerInterface {
        $cloned = clone $this;
        $cloned->context = new Context(
            clientName:     static::NAME,
            backendName:    $name,
            backendUrl:     $url,
            cache:          $this->cache->withData(static::NAME . '_' . $name, $options),
            backendId:      $uuid,
            backendToken:   $token,
            backendUser:    $userId,
            backendHeaders: array_replace_recursive(
                                [
                                    'headers' => [
                                        'Accept' => 'application/json',
                                        'X-MediaBrowser-Token' => $token,
                                    ],
                                ],
                                $options['client'] ?? []
                            ),
            trace:          true === ag($options, Options::DEBUG_TRACE),
            options:        $options
        );

        $cloned->guid = $cloned->guid->withContext($cloned->context);

        return $cloned;
    }

    public function getServerUUID(bool $forceRefresh = false): int|string|null
    {
        if (false === $forceRefresh && null !== $this->context->backendId) {
            return $this->context->backendId;
        }

        $response = Container::get(GetIdentifier::class)(context: $this->context);

        return $response->isSuccessful() ? $response->response : null;
    }

    public function getUsersList(array $opts = []): array
    {
        $response = Container::get(GetUsersList::class)($this->context, $opts);

        if (false === $response->isSuccessful()) {
            if ($response->hasError()) {
                $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
            }

            throw new RuntimeException(
                ag($response->extra, 'message', fn() => $response->error->format())
            );
        }

        return $response->response;
    }

    public function setLogger(LoggerInterface $logger): ServerInterface
    {
        $this->logger = $logger;

        return $this;
    }

    public function getName(): string
    {
        return $this->context->backendName ?? static::NAME;
    }

    public function processRequest(ServerRequestInterface $request, array $opts = []): ServerRequestInterface
    {
        $response = Container::get(InspectRequest::class)(context: $this->context, request: $request);

        return $response->isSuccessful() ? $response->response : $request;
    }

    public function parseWebhook(ServerRequestInterface $request): iFace
    {
        $response = Container::get(ParseWebhook::class)(context: $this->context, guid: $this->guid, request: $request);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new HttpException(
                ag($response->extra, 'message', fn() => $response->error->format()),
                ag($response->extra, 'http_code', 400),
            );
        }

        return $response->response;
    }

    public function search(string $query, int $limit = 25, array $opts = []): array
    {
        $response = Container::get(SearchQuery::class)(
            context: $this->context,
            query:   $query,
            limit:   $limit,
            opts:    $opts
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function searchId(string|int $id, array $opts = []): array
    {
        $response = Container::get(SearchId::class)(
            context: $this->context,
            id:      $id,
            opts:    $opts
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }

    public function getMetadata(string|int $id, array $opts = []): array
    {
        return $this->getItemDetails(context: $this->context, id: $id, opts: $opts);
    }

    /**
     * @throws Throwable
     */
    public function getLibrary(string|int $id, array $opts = []): array
    {
        $response = Container::get(GetLibrary::class)(
            context: $this->context,
            guid:    $this->guid,
            id:      $id,
            opts:    $opts
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }

    public function listLibraries(array $opts = []): array
    {
        $response = Container::get(GetLibrariesList::class)(context: $this->context, opts: $opts);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }

    public function push(array $entities, QueueRequests $queue, DateTimeInterface|null $after = null): array
    {
        $response = Container::get(Push::class)(
            context:  $this->context,
            entities: $entities,
            queue:    $queue,
            after:    $after
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return [];
    }

    public function pull(ImportInterface $mapper, DateTimeInterface|null $after = null): array
    {
        $response = Container::get(Import::class)(
            context: $this->context,
            guid:    $this->guid,
            mapper:  $mapper,
            after:   $after
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }

    public function export(ImportInterface $mapper, QueueRequests $queue, DateTimeInterface|null $after = null): array
    {
        $response = Container::get(Export::class)(
            context: $this->context,
            guid:    $this->guid,
            mapper:  $mapper,
            after:   $after,
            opts:    ['queue' => $queue]
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }
}
