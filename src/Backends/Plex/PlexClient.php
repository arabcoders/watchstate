<?php

declare(strict_types=1);

namespace App\Backends\Plex;

use App\Backends\Common\Cache;
use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Plex\Action\Backup;
use App\Backends\Plex\Action\Export;
use App\Backends\Plex\Action\GetIdentifier;
use App\Backends\Plex\Action\GetInfo;
use App\Backends\Plex\Action\GetLibrariesList;
use App\Backends\Plex\Action\GetLibrary;
use App\Backends\Plex\Action\GetMetaData;
use App\Backends\Plex\Action\GetUsersList;
use App\Backends\Plex\Action\GetUserToken;
use App\Backends\Plex\Action\GetVersion;
use App\Backends\Plex\Action\Import;
use App\Backends\Plex\Action\InspectRequest;
use App\Backends\Plex\Action\ParseWebhook;
use App\Backends\Plex\Action\Progress;
use App\Backends\Plex\Action\Push;
use App\Backends\Plex\Action\SearchId;
use App\Backends\Plex\Action\SearchQuery;
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
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PlexClient implements iClient
{
    public const NAME = 'PlexBackend';

    public const CLIENT_NAME = 'Plex';

    public const TYPE_SHOW = 'show';
    public const TYPE_MOVIE = 'movie';
    public const TYPE_EPISODE = 'episode';

    public const TYPE_MAPPER = [
        PlexClient::TYPE_SHOW => iState::TYPE_SHOW,
        PlexClient::TYPE_MOVIE => iState::TYPE_MOVIE,
        PlexClient::TYPE_EPISODE => iState::TYPE_EPISODE,
    ];

    public const SUPPORTED_AGENTS = [
        'com.plexapp.agents.imdb',
        'com.plexapp.agents.tmdb',
        'com.plexapp.agents.themoviedb',
        'com.plexapp.agents.xbmcnfo',
        'com.plexapp.agents.xbmcnfotv',
        'com.plexapp.agents.thetvdb',
        'com.plexapp.agents.hama',
        'com.plexapp.agents.youtube',
        'com.plexapp.agents.cmdb',
        'tv.plex.agents.movie',
        'tv.plex.agents.series',
    ];
    private Context $context;
    private iLogger $logger;
    private iGuid $guid;
    private Cache $cache;

    public function __construct(iLogger $logger, Cache $cache, PlexGuid $guid)
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
                    'X-Plex-Token' => $context->backendToken,
                    'X-Plex-Container-Size' => 0,
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
            guid: $this->guid,
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
        $response = Container::get(GetMetaData::class)(context: $this->context, id: $id, opts: $opts);

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

    public function getUserToken(int|string $userId, string $username): string|bool
    {
        $response = Container::get(GetUserToken::class)($this->context, $userId, $username);

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

    public function getInfo(array $opts = []): array
    {
        $response = Container::get(GetInfo::class)(context: $this->context, opts: $opts);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }

    public function getVersion(array $opts = []): string
    {
        $response = Container::get(GetVersion::class)(context: $this->context, opts: $opts);

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
        return Container::get(PlexManage::class)->manage(backend: $backend, opts: $opts);
    }

    /**
     * Discover Servers linked to plex token.
     *
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     */
    public static function discover(HttpClientInterface $http, string $token, array $opts = []): array
    {
        try {
            $response = $http->request('GET', 'https://plex.tv/api/resources?includeHttps=1&includeRelay=0', [
                'headers' => [
                    'X-Plex-Token' => $token,
                ],
            ]);

            $payload = $response->getContent(false);

            if (200 !== $response->getStatusCode()) {
                throw new RuntimeException(
                    r('Request for servers list returned with unexpected [{status_code}] status code. {context}', [
                        'status_code' => $response->getStatusCode(),
                        'context' => arrayToString(['payload' => $payload]),
                    ])
                );
            }
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException(
                r(
                    'Unexpected exception [{exception}] was thrown during request for servers list, likely network related error. [{error}]',
                    [
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]
                )
            );
        }

        $xml = simplexml_load_string($payload);

        $list = [];

        if (false === $xml->Device) {
            throw new RuntimeException('No devices found associated with the given token.');
        }

        foreach ($xml->Device as $device) {
            if (null === ($attr = $device->attributes())) {
                continue;
            }

            $attr = ag((array)$attr, '@attributes');

            if ('server' !== ag($attr, 'provides')) {
                continue;
            }

            if (!property_exists($device, 'Connection') || false === $device->Connection) {
                continue;
            }

            foreach ($device->Connection as $uri) {
                if (null === ($cAttr = $uri->attributes())) {
                    continue;
                }

                $cAttr = ag((array)$cAttr, '@attributes');

                $arr = [
                    'name' => ag($attr, 'name'),
                    'identifier' => ag($attr, 'clientIdentifier'),
                    'proto' => ag($cAttr, 'protocol'),
                    'address' => ag($cAttr, 'address'),
                    'port' => (int)ag($cAttr, 'port'),
                    'uri' => ag($cAttr, 'uri'),
                    'online' => 1 === (int)ag($attr, 'presence') ? 'Yes' : 'No',
                ];

                if (true === ag_exists($opts, 'with-tokens')) {
                    $arr['AccessToken'] = ag($attr, 'accessToken');
                }

                $list['list'][] = $arr;
            }
        }

        if (true === ag_exists($opts, Options::RAW_RESPONSE)) {
            $list[Options::RAW_RESPONSE] = $xml;
        }

        return $list;
    }
}
