<?php

declare(strict_types=1);

namespace App\Backends\Plex;

use App\Backends\Common\Cache;
use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Response;
use App\Backends\Plex\Action\Backup;
use App\Backends\Plex\Action\Export;
use App\Backends\Plex\Action\GetIdentifier;
use App\Backends\Plex\Action\getImagesUrl;
use App\Backends\Plex\Action\GetInfo;
use App\Backends\Plex\Action\GetLibrariesList;
use App\Backends\Plex\Action\GetLibrary;
use App\Backends\Plex\Action\GetMetaData;
use App\Backends\Plex\Action\GetSessions;
use App\Backends\Plex\Action\GetUsersList;
use App\Backends\Plex\Action\GetUserToken;
use App\Backends\Plex\Action\GetVersion;
use App\Backends\Plex\Action\GetWebUrl;
use App\Backends\Plex\Action\Import;
use App\Backends\Plex\Action\InspectRequest;
use App\Backends\Plex\Action\ParseWebhook;
use App\Backends\Plex\Action\Progress;
use App\Backends\Plex\Action\Proxy;
use App\Backends\Plex\Action\Push;
use App\Backends\Plex\Action\SearchId;
use App\Backends\Plex\Action\SearchQuery;
use App\Backends\Plex\Action\ToEntity;
use App\Backends\Plex\Action\UpdateState;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Exceptions\HttpException;
use App\Libs\Extends\HttpClient;
use App\Libs\Mappers\Import\ReadOnlyMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\Uri;
use App\Libs\UserContext;
use Closure;
use DateTimeInterface as iDate;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Message\StreamInterface as iStream;
use Psr\Http\Message\UriInterface as iUri;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

/**
 * Class PlexClient
 *
 * This class is responsible for facilitating communication with Plex Server backend.
 *
 * @implements iClient
 */
class PlexClient implements iClient
{
    public const string NAME = 'PlexBackend';

    public const string CLIENT_NAME = 'Plex';

    public const string TYPE_SHOW = 'show';
    public const string TYPE_MOVIE = 'movie';
    public const string TYPE_EPISODE = 'episode';

    /**
     * @var array Map plex types to iState types.
     */
    public const array TYPE_MAPPER = [
        PlexClient::TYPE_SHOW => iState::TYPE_SHOW,
        PlexClient::TYPE_MOVIE => iState::TYPE_MOVIE,
        PlexClient::TYPE_EPISODE => iState::TYPE_EPISODE,
    ];

    /**
     * @var array List of supported agents.
     */
    public const array SUPPORTED_AGENTS = [
        'com.plexapp.agents.imdb',
        'com.plexapp.agents.tmdb',
        'com.plexapp.agents.themoviedb',
        'com.plexapp.agents.xbmcnfo',
        'com.plexapp.agents.xbmcnfotv',
        'com.plexapp.agents.thetvdb',
        'com.plexapp.agents.hama',
        'com.plexapp.agents.ytinforeader',
        'com.plexapp.agents.cmdb',
        'tv.plex.agents.movie',
        'tv.plex.agents.series',
    ];

    /**
     * @var mixed $context Backend context.
     */
    private Context $context;
    /**
     * @var iLogger The logger object.
     */
    private iLogger $logger;
    /**
     * @var iGuid GUID parser.
     */
    private iGuid $guid;
    /**
     * @var Cache The Cache store.
     */
    private Cache $cache;

    /**
     * Class constructor.
     *
     * @param iLogger $logger The logger instance.
     * @param Cache $cache The cache instance.
     * @param PlexGuid $guid The PlexGuid instance.
     */
    public function __construct(iLogger $logger, Cache $cache, PlexGuid $guid, UserContext $userContext)
    {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->context = new Context(
            clientName: static::CLIENT_NAME,
            backendName: static::CLIENT_NAME,
            backendUrl: new Uri('http://localhost'),
            cache: $this->cache,
            userContext: $userContext,
        );
        $this->guid = $guid->withContext($this->context);
    }

    /**
     * @inheritdoc
     */
    public function withContext(Context $context): self
    {
        $cloned = clone $this;

        $cloned->context = new Context(
            clientName: static::CLIENT_NAME,
            backendName: $context->backendName,
            backendUrl: $context->backendUrl,
            cache: $context->cache->withData(static::CLIENT_NAME . '_' . $context->backendName, $context->options),
            userContext: $context->userContext,
            logger: $context->logger,
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

    /**
     * @inheritdoc
     */
    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return $this->context?->backendName ?? static::CLIENT_NAME;
    }

    public function getType(): string
    {
        return static::CLIENT_NAME;
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
    public function processRequest(iRequest $request, array $opts = []): iRequest
    {
        $response = Container::get(InspectRequest::class)(context: $this->context, request: $request);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        return $response->isSuccessful() ? $response->response : $request;
    }

    /**
     * @inheritdoc
     */
    public function parseWebhook(iRequest $request): iState
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
            $this->throwError($response, HttpException::class, ag($response->extra, 'http_code', 400));
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function pull(iImport $mapper, iDate|null $after = null): array
    {
        $response = Container::get(Import::class)(
            context: $this->context,
            guid: $this->guid,
            mapper: $mapper,
            after: $after,
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function backup(iImport $mapper, iStream|null $writer = null, array $opts = []): array
    {
        $response = Container::get(Backup::class)(
            context: $this->context,
            guid: $this->guid,
            mapper: $mapper,
            opts: ag_sets($opts, ['writer' => $writer])
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function export(iImport $mapper, QueueRequests $queue, iDate|null $after = null): array
    {
        $response = Container::get(Export::class)(
            context: $this->context,
            guid: $this->guid,
            mapper: $mapper,
            after: $after,
            opts: ['queue' => $queue],
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
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
            $this->throwError($response);
        }

        return [];
    }

    /**
     * @inheritdoc
     */
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
            $this->throwError($response);
        }

        return [];
    }

    /**
     * @inheritdoc
     */
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
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function searchId(string|int $id, array $opts = []): array
    {
        $response = Container::get(SearchId::class)(context: $this->context, id: $id, opts: $opts);

        if ($response->hasError() && false === (bool)ag($opts, Options::NO_LOGGING, false)) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function getMetadata(string|int $id, array $opts = []): array
    {
        $response = Container::get(GetMetaData::class)(context: $this->context, id: $id, opts: $opts);

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function getImagesUrl(string|int $id, array $opts = []): array
    {
        $response = Container::get(getImagesUrl::class)(context: $this->context, id: $id, opts: $opts);

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function proxy(Method $method, iUri $uri, array|iStream $body = [], array $opts = []): Response
    {
        return Container::get(Proxy::class)(
            context: $this->context,
            method: $method,
            uri: $uri,
            body: $body,
            opts: $opts
        );
    }

    /**
     * @inheritdoc
     */
    public function getLibraryContent(string|int $libraryId, array $opts = []): array
    {
        $mapper = Container::get(ReadOnlyMapper::class)->withOptions([]);
        assert($mapper instanceof ReadOnlyMapper);
        $mapper->asContainer();

        $response = Container::get(Import::class)(
            context: $this->context,
            guid: $this->guid,
            mapper: $mapper,
            after: null,
            opts: ag_sets($opts, [Options::ONLY_LIBRARY_ID => $libraryId]),
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        if (null === ($queue = $response->response)) {
            return [];
        }

        foreach ($queue as $_key => $response) {
            $requestData = $response->getInfo('user_data');

            try {
                $requestData['ok']($response);
            } catch (Throwable $e) {
                $requestData['error']($e);
            }

            $queue[$_key] = null;

            gc_collect_cycles();
        }

        return $mapper->getObjects();
    }

    /**
     * @inheritdoc
     */
    public function getLibrary(string|int $id, array $opts = []): array
    {
        $response = Container::get(GetLibrary::class)(context: $this->context, guid: $this->guid, id: $id, opts: $opts);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
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

    /**
     * @inheritdoc
     */
    public function getUsersList(array $opts = []): array
    {
        $response = Container::get(GetUsersList::class)($this->context, $opts);

        if (false === $response->isSuccessful()) {
            if ($response->hasError()) {
                $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
            }

            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function getSessions(array $opts = []): array
    {
        $response = Container::get(GetSessions::class)($this->context, $opts);

        if (false === $response->isSuccessful()) {
            if ($response->hasError()) {
                $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
            }

            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function getUserToken(int|string $userId, string $username, array $opts = []): string|bool
    {
        $response = Container::get(GetUserToken::class)($this->context, $userId, $username, $opts);

        if (false === $response->isSuccessful()) {
            if ($response->hasError()) {
                $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
            }

            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function getWebUrl(string $type, int|string $id): iUri
    {
        $response = Container::get(GetWebUrl::class)($this->context, $type, $id);

        if (false === $response->isSuccessful()) {
            if ($response->hasError()) {
                $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
            }

            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function toEntity(array $item, array $opts = []): iState
    {
        $response = Container::get(ToEntity::class)($this->context, $item, $opts);

        if (false === $response->isSuccessful()) {
            if ($response->hasError()) {
                $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
            }

            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function listLibraries(array $opts = []): array
    {
        $response = Container::get(GetLibrariesList::class)(context: $this->context, opts: $opts);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function getInfo(array $opts = []): array
    {
        $response = Container::get(GetInfo::class)(context: $this->context, opts: $opts);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function getVersion(array $opts = []): string
    {
        $response = Container::get(GetVersion::class)(context: $this->context, opts: $opts);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function fromRequest(array $config, iRequest $request): array
    {
        $params = DataUtil::fromArray($request->getParsedBody());

        if (null !== ($val = $params->get('options.' . Options::PLEX_USER_UUID))) {
            $config = ag_set($config, 'options.' . Options::PLEX_USER_UUID, $val);
        }

        if (null !== ($val = $params->get('options.' . Options::ADMIN_TOKEN))) {
            $config = ag_set($config, 'options.' . Options::ADMIN_TOKEN, $val);
        }

        if (null !== ($val = $params->get('options.' . Options::PLEX_USER_PIN))) {
            $config = ag_set($config, 'options.' . Options::PLEX_USER_PIN, $val);
        }

        if (null !== ($userId = ag($config, 'user')) && !is_int($userId)) {
            $config = ag_set($config, 'user', (int)$userId);
        }

        return $config;
    }

    /**
     * @inheritdoc
     */
    public function validateContext(Context $context): bool
    {
        return Container::get(PlexValidateContext::class)($context);
    }

    public function updateState(array $entities, QueueRequests $queue, array $opts = []): void
    {
        $response = Container::get(UpdateState::class)(
            context: $this->context,
            entities: $entities,
            queue: $queue,
            opts: $opts
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }
    }

    /**
     * @inheritdoc
     */
    public function generateAccessToken(string|int $identifier, string $password, array $opts = []): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public static function manage(array $backend, array $opts = []): array
    {
        return Container::get(PlexManage::class)->manage(backend: $backend, opts: $opts);
    }

    /**
     * Retrieves a list of Plex servers using the Plex.tv API.
     *
     * @param iHttp&HttpClient $http The HTTP client used to send the request.
     * @param string $token The Plex authentication token.
     * @param array $opts (Optional) options.
     *
     * @return array The list of Plex servers.
     *
     * @throws RuntimeException When an unexpected status code is returned or a network-related exception occurs.
     * @throws ClientExceptionInterface When a client error is encountered.
     * @throws RedirectionExceptionInterface When a redirection error is encountered.
     * @throws ServerExceptionInterface When a server error is encountered.
     */
    public static function discover(iHttp $http, string $token, array $opts = []): array
    {
        try {
            $response = $http->request(
                method: Method::GET,
                url: 'https://plex.tv/api/resources?includeHttps=1&includeRelay=0',
                options: ['headers' => ['X-Plex-Token' => $token]]
            );

            $payload = $response->getContent(false);

            if (Status::OK !== Status::from($response->getStatusCode())) {
                if (Status::UNAUTHORIZED === Status::from($response->getStatusCode())) {
                    if (null !== ($adminToken = ag($opts, Options::ADMIN_TOKEN))) {
                        $opts['with_admin'] = true;
                        return self::discover($http, $adminToken, ag_delete($opts, Options::ADMIN_TOKEN));
                    }
                }

                throw new RuntimeException(
                    r(
                        text: "PlexClient: Request for servers list returned with unexpected '{status_code}' status code. {context}",
                        context: [
                            'status_code' => $response->getStatusCode(),
                            'context' => arrayToString([
                                'with_admin' => true === ag($opts, 'with_admin'),
                                'payload' => $payload
                            ]),
                        ]
                    ),
                    $response->getStatusCode()
                );
            }
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException(
                r(
                    text: "PlexClient: Exception '{kind}' was thrown unhandled during request for plex servers list, likely network related error. {error} at '{file}:{line}'.",
                    context: [
                        'kind' => $e::class,
                        'error' => $e->getMessage(),
                        'line' => $e->getLine(),
                        'file' => after($e->getFile(), ROOT_PATH),
                    ]
                ),
                code: 500,
                previous: $e
            );
        }

        $xml = simplexml_load_string($payload);

        $list = [];

        if (false === $xml->Device) {
            throw new RuntimeException('PlexClient: No backends were associated with the given token.');
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

                if (false !== filter_var(ag($arr, 'address'), FILTER_VALIDATE_IP)) {
                    $list['list'][] = array_replace_recursive($arr, [
                        'proto' => 'http',
                        'uri' => r('http://{ip}:{port}', ['ip' => ag($arr, 'address'), 'port' => ag($arr, 'port')]),
                    ]);
                }

                $list['list'][] = $arr;
            }
        }

        if (true === ag_exists($opts, Options::RAW_RESPONSE)) {
            $list[Options::RAW_RESPONSE] = $xml;
        }

        return $list;
    }

    /**
     * Check if given plex token is valid.
     *
     * @param iHttp&HttpClient $http The HTTP client used to send the request.
     * @param string $token The Plex authentication token.
     * @param array $opts (Optional) options.
     *
     * @return bool return true if token is valid.
     *
     * @throws RuntimeException When an unexpected status code is returned or a network-related exception occurs.
     * @throws ClientExceptionInterface When a client error is encountered.
     * @throws RedirectionExceptionInterface When a redirection error is encountered.
     * @throws ServerExceptionInterface When a server error is encountered.
     */
    public static function validate_token(iHttp $http, string $token, array $opts = []): bool
    {
        try {
            $url = 'https://plex.tv/api/users';
            $response = $http->request(
                method: Method::GET,
                url: $url,
                options: ['headers' => ['X-Plex-Token' => $token]]
            );

            $status = Status::from($response->getStatusCode());

            try {
                $body = $response->getContent(false);
                $payload = json_decode(
                    json: json_encode(simplexml_load_string($body)),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                );
                if (false === is_array($payload)) {
                    $payload = ['body' => $body];
                }
            } catch (Throwable $e) {
                $payload = [
                    'body' => $e->getMessage(),
                ];
            }

            if (Status::UNAUTHORIZED === $status) {
                throw new RuntimeException(r("{client}: plex.tv says the token is invalid. '{status}: {msg}'", [
                    'client' => static::CLIENT_NAME,
                    'status' => $status->value,
                    'msg' => ag($payload, ['error', 'body'], '??'),
                ]), $status->value);
            }

            $callback = ag($opts, Options::RAW_RESPONSE_CALLBACK, null);

            if (true === ($callback instanceof Closure)) {
                ($callback)([['url' => $url, 'headers' => $response->getHeaders(false), 'body' => $payload]]);
            }

            return true;
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException(
                r(
                    text: "PlexClient: Exception '{kind}' was thrown unhandled during request for plex servers list, likely network related error. {error} at '{file}:{line}'.",
                    context: [
                        'kind' => $e::class,
                        'error' => $e->getMessage(),
                        'line' => $e->getLine(),
                        'file' => after($e->getFile(), ROOT_PATH),
                    ]
                ),
                code: 500,
                previous: $e
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function getGuid(): iGuid
    {
        return $this->guid;
    }

    /**
     * Throws an exception with the specified message and previous exception.
     *
     * @template T
     * @param Response $response The response object containing the error details.
     * @param class-string<T> $className The exception class name.
     * @param int $code The exception code.
     */
    private function throwError(Response $response, string $className = RuntimeException::class, int $code = 0): void
    {
        throw new $className(
            message: ag($response->extra, 'message', fn() => $response->error->format()),
            code: $code,
            previous: $response->error->previous
        );
    }
}
