<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin;

use App\Backends\Common\Cache;
use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Jellyfin\Action\Backup;
use App\Backends\Jellyfin\Action\Export;
use App\Backends\Jellyfin\Action\GetIdentifier;
use App\Backends\Jellyfin\Action\GetLibrariesList;
use App\Backends\Jellyfin\Action\GetLibrary;
use App\Backends\Jellyfin\Action\GetMetaData;
use App\Backends\Jellyfin\Action\GetUsersList;
use App\Backends\Jellyfin\Action\Import;
use App\Backends\Jellyfin\Action\InspectRequest;
use App\Backends\Jellyfin\Action\ParseWebhook;
use App\Backends\Jellyfin\Action\Progress;
use App\Backends\Jellyfin\Action\Push;
use App\Backends\Jellyfin\Action\SearchId;
use App\Backends\Jellyfin\Action\SearchQuery;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\HttpException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\Uri;
use DateTimeInterface as iDate;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface as iLogger;
use RuntimeException;
use SplFileObject;

class JellyfinClient implements iClient
{
    public const NAME = 'JellyfinBackend';

    public const CLIENT_NAME = 'Jellyfin';

    public const TYPE_MOVIE = 'Movie';
    public const TYPE_SHOW = 'Series';
    public const TYPE_EPISODE = 'Episode';

    public const COLLECTION_TYPE_SHOWS = 'tvshows';
    public const COLLECTION_TYPE_MOVIES = 'movies';

    public const EXTRA_FIELDS = [
        'ProviderIds',
        'DateCreated',
        'OriginalTitle',
        'SeasonUserData',
        'DateLastSaved',
        'PremiereDate',
        'ProductionYear',
        'Path',
        'UserDataLastPlayedDate',
    ];

    public const TYPE_MAPPER = [
        JellyfinClient::TYPE_SHOW => iState::TYPE_SHOW,
        JellyfinClient::TYPE_MOVIE => iState::TYPE_MOVIE,
        JellyfinClient::TYPE_EPISODE => iState::TYPE_EPISODE,
    ];

    private Context $context;
    private iGuid $guid;
    private iLogger $logger;
    private Cache $cache;

    public function __construct(Cache $cache, iLogger $logger, JellyfinGuid $guid)
    {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->context = new Context(
            clientName: static::CLIENT_NAME,
            backendName: static::CLIENT_NAME,
            backendUrl: new Uri('http://localhost'),
            cache: $this->cache,
        );
        $this->guid = $guid->withContext($this->context);
    }

    public function withContext(Context $context): self
    {
        $cloned = clone $this;
        $cloned->context = new Context(
            clientName: static::CLIENT_NAME,
            backendName: $context->backendName,
            backendUrl: $context->backendUrl,
            cache: $this->cache->withData(static::CLIENT_NAME . '_' . $context->backendName, $context->options),
            backendId: $context->backendId,
            backendToken: $context->backendToken,
            backendUser: $context->backendUser,
            backendHeaders: array_replace_recursive([
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => r(
                        'MediaBrowser Token="{token}", Client="{app}", Device="{os}", DeviceId="{id}", Version="{version}", UserId="{user}"',
                        [
                            'token' => $context->backendToken,
                            'app' => Config::get('name') . '/' . static::CLIENT_NAME,
                            'os' => PHP_OS,
                            'id' => md5(Config::get('name') . '/' . static::CLIENT_NAME . $context->backendUser),
                            'version' => getAppVersion(),
                            'user' => $context->backendUser,
                        ]
                    ),
                ],
            ], ag($context->options, 'client', [])),
            trace: true === ag($context->options, Options::DEBUG_TRACE),
            options: array_replace_recursive($context->options, [
                Options::LIBRARY_SEGMENT => (int)ag(
                    $context->options,
                    Options::LIBRARY_SEGMENT,
                    Config::get('library.segment')
                ),
            ])
        );

        $cloned->guid = $cloned->guid->withContext($cloned->context);

        return $cloned;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getName(): string
    {
        return $this->context?->backendName ?? static::CLIENT_NAME;
    }

    public function setLogger(iLogger $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function processRequest(ServerRequestInterface $request, array $opts = []): ServerRequestInterface
    {
        $response = Container::get(InspectRequest::class)(context: $this->context, request: $request);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        return $response->isSuccessful() ? $response->response : $request;
    }

    public function parseWebhook(ServerRequestInterface $request): iState
    {
        $response = Container::get(ParseWebhook::class)(
            context: $this->context,
            guid: $this->guid,
            request: $request
        );

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

    public function pull(iImport $mapper, iDate|null $after = null): array
    {
        $response = Container::get(Import::class)(
            context: $this->context,
            guid: $this->guid,
            mapper: $mapper,
            after: $after,
            opts: [
                Options::DISABLE_GUID => (bool)Config::get('episodes.disable.guid'),
            ]
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }

    public function backup(iImport $mapper, SplFileObject|null $writer = null, array $opts = []): array
    {
        $response = Container::get(Backup::class)(
            context: $this->context,
            guid: $this->guid,
            mapper: $mapper,
            opts: $opts + ['writer' => $writer]
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }

    public function export(iImport $mapper, QueueRequests $queue, iDate|null $after = null): array
    {
        $response = Container::get(Export::class)(
            context: $this->context,
            guid: $this->guid,
            mapper: $mapper,
            after: $after,
            opts: [
                'queue' => $queue,
                Options::DISABLE_GUID => (bool)Config::get('episodes.disable.guid'),
            ],
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }

    public function push(array $entities, QueueRequests $queue, iDate|null $after = null): array
    {
        $response = Container::get(Push::class)(
            context: $this->context,
            entities: $entities,
            queue: $queue,
            after: $after
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return [];
    }

    public function progress(array $entities, QueueRequests $queue, iDate|null $after = null): array
    {
        $response = Container::get(Progress::class)(
            context: $this->context,
            entities: $entities,
            queue: $queue,
            after: $after
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return [];
    }

    public function search(string $query, int $limit = 25, array $opts = []): array
    {
        $response = Container::get(SearchQuery::class)(
            context: $this->context,
            query: $query,
            limit: $limit,
            opts: $opts
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }

    public function searchId(string|int $id, array $opts = []): array
    {
        $response = Container::get(SearchId::class)(context: $this->context, id: $id, opts: $opts);

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
        $response = Container::get(GetMetaData::class)(
            context: $this->context,
            id: $id,
            opts: $opts
        );

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(message: $response->error->format(), previous: $response->error->previous);
        }

        return $response->response;
    }

    public function getLibrary(string|int $id, array $opts = []): array
    {
        $response = Container::get(GetLibrary::class)(context: $this->context, guid: $this->guid, id: $id, opts: $opts);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }

    public function getIdentifier(bool $forceRefresh = false): int|string|null
    {
        if (false === $forceRefresh && null !== $this->context->backendId) {
            return $this->context->backendId;
        }

        $response = Container::get(GetIdentifier::class)(context: $this->context);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

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

    /**
     * For Jellyfin we do not generate api access token, thus we simply return
     * the given the access token.
     *
     * @param int|string $userId
     * @param string $username
     * @return string|bool
     */
    public function getUserToken(int|string $userId, string $username): string|bool
    {
        return $this->context->backendToken;
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

    public static function manage(array $backend, array $opts = []): array
    {
        return Container::get(JellyfinManage::class)->manage(backend: $backend, opts: $opts);
    }
}
