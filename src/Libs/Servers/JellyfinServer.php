<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use App\Libs\Guid;
use App\Libs\HttpException;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Mappers\ImportInterface;
use Closure;
use DateInterval;
use DateTimeInterface;
use Exception;
use JsonException;
use JsonMachine\Exception\PathNotFoundException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;
use StdClass;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

class JellyfinServer implements ServerInterface
{
    private const GUID_MAPPER = [
        'plex' => Guid::GUID_PLEX,
        'imdb' => Guid::GUID_IMDB,
        'tmdb' => Guid::GUID_TMDB,
        'tvdb' => Guid::GUID_TVDB,
        'tvmaze' => Guid::GUID_TVMAZE,
        'tvrage' => Guid::GUID_TVRAGE,
        'anidb' => Guid::GUID_ANIDB,
    ];

    protected const WEBHOOK_ALLOWED_TYPES = [
        'Movie',
        'Episode',
    ];

    protected const WEBHOOK_ALLOWED_EVENTS = [
        'ItemAdded',
        'UserDataSaved',
        'PlaybackStart',
        'PlaybackStop',
    ];

    protected const WEBHOOK_TAINTED_EVENTS = [
        'PlaybackStart',
        'PlaybackStop',
    ];

    protected UriInterface|null $url = null;
    protected string|null $token = null;
    protected string|null $user = null;
    protected array $options = [];
    protected string $name = '';
    protected bool $initialized = false;
    protected bool $isEmby = false;
    protected array $persist = [];
    protected string $cacheKey = '';
    protected array $cacheData = [];
    protected string|int|null $uuid = null;

    protected array $showInfo = [];
    protected array $cacheShow = [];
    protected string $cacheShowKey = '';

    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
        protected CacheInterface $cache
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function setUp(
        string $name,
        UriInterface $url,
        string|int|null $token = null,
        string|int|null $userId = null,
        string|int|null $uuid = null,
        array $persist = [],
        array $options = []
    ): ServerInterface {
        if (null === $token) {
            throw new RuntimeException(afterLast(__CLASS__, '\\') . '->setState(): No token is set.');
        }

        $cloned = clone $this;

        $cloned->cacheData = [];
        $cloned->name = $name;
        $cloned->url = $url;
        $cloned->token = $token;
        $cloned->uuid = $uuid;
        $cloned->user = $userId;
        $cloned->persist = $persist;
        $cloned->isEmby = (bool)($options['emby'] ?? false);
        $cloned->initialized = true;

        $cloned->cacheKey = $options['cache_key'] ?? md5(__CLASS__ . '.' . $name . ($userId ?? $token) . $url);
        $cloned->cacheShowKey = $cloned->cacheKey . '_show';

        if ($cloned->cache->has($cloned->cacheKey)) {
            $cloned->cacheData = $cloned->cache->get($cloned->cacheKey);
        }

        if ($cloned->cache->has($cloned->cacheShowKey)) {
            $cloned->cacheShow = $cloned->cache->get($cloned->cacheShowKey);
        }

        if (null !== ($options['emby'] ?? null)) {
            unset($options['emby']);
        }

        $cloned->options = $options;
        $cloned->initialized = true;

        return $cloned;
    }

    public function getServerUUID(bool $forceRefresh = false): int|string|null
    {
        if (false === $forceRefresh && null !== $this->uuid) {
            return $this->uuid;
        }

        $this->checkConfig(checkUser: false);

        $this->logger->debug(
            sprintf('Requesting server Unique id info from %s.', $this->name),
            ['url' => $this->url->getHost()]
        );

        $url = $this->url->withPath('/system/Info');

        $response = $this->http->request('GET', (string)$url, $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            $this->logger->error(
                sprintf(
                    'Request to %s responded with unexpected code (%d).',
                    $this->name,
                    $response->getStatusCode()
                )
            );

            return null;
        }

        $json = json_decode($response->getContent(false), true, flags: JSON_THROW_ON_ERROR);

        $this->uuid = ag($json, 'Id', null);

        return $this->uuid;
    }

    public function getUsersList(array $opts = []): array
    {
        $this->checkConfig(checkUser: false);

        $response = $this->http->request('GET', (string)$this->url->withPath('/Users/'), $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(
                sprintf(
                    'Request to %s responded with unexpected code (%d).',
                    $this->name,
                    $response->getStatusCode()
                )
            );
        }

        $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $list = [];

        foreach ($json ?? [] as $user) {
            $date = $user['LastActivityDate'] ?? $user['LastLoginDate'] ?? null;

            $data = [
                'user_id' => ag($user, 'Id'),
                'username' => ag($user, 'Name'),
                'is_admin' => ag($user, 'Policy.IsAdministrator') ? 'Yes' : 'No',
                'is_hidden' => ag($user, 'Policy.IsHidden') ? 'Yes' : 'No',
                'is_disabled' => ag($user, 'Policy.IsDisabled') ? 'Yes' : 'No',
                'updated_at' => null !== $date ? makeDate($date) : 'Never',
            ];

            if (true === ($opts['tokens'] ?? false)) {
                $data['token'] = $this->token;
            }

            $list[] = $data;
        }

        return $list;
    }

    public function getPersist(): array
    {
        return $this->persist;
    }

    public function addPersist(string $key, mixed $value): ServerInterface
    {
        $this->persist = ag_set($this->persist, $key, $value);
        return $this;
    }

    public function setLogger(LoggerInterface $logger): ServerInterface
    {
        $this->logger = $logger;

        return $this;
    }

    public static function processRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        $userAgent = ag($request->getServerParams(), 'HTTP_USER_AGENT', '');

        if (false === Config::get('webhook.debug', false) && !str_starts_with($userAgent, 'Jellyfin-Server/')) {
            return $request;
        }

        $body = (string)$request->getBody();

        if (null === ($json = json_decode($body, true))) {
            return $request;
        }

        $request = $request->withParsedBody($json);

        $attributes = [
            'SERVER_ID' => ag($json, 'ServerId', ''),
            'SERVER_NAME' => ag($json, 'ServerName', ''),
            'SERVER_VERSION' => afterLast($userAgent, '/'),
            'USER_ID' => ag($json, 'UserId', ''),
            'USER_NAME' => ag($json, 'NotificationUsername', ''),
            'WH_EVENT' => ag($json, 'NotificationType', 'not_set'),
            'WH_TYPE' => ag($json, 'ItemType', 'not_set'),
        ];

        foreach ($attributes as $key => $val) {
            $request = $request->withAttribute($key, $val);
        }

        return $request;
    }

    public function parseWebhook(ServerRequestInterface $request): StateInterface
    {
        if (null === ($json = $request->getParsedBody())) {
            throw new HttpException(sprintf('%s: No payload.', afterLast(__CLASS__, '\\')), 400);
        }

        $event = ag($json, 'NotificationType', 'unknown');
        $type = ag($json, 'ItemType', 'not_found');

        if (null === $type || !in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            throw new HttpException(sprintf('%s: Not allowed type [%s]', afterLast(__CLASS__, '\\'), $type), 200);
        }

        $type = strtolower($type);

        if (null === $event || !in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            throw new HttpException(sprintf('%s: Not allowed event [%s]', afterLast(__CLASS__, '\\'), $event), 200);
        }

        $isTainted = in_array($event, self::WEBHOOK_TAINTED_EVENTS);

        $meta = match ($type) {
            StateInterface::TYPE_MOVIE => [
                'via' => $this->name,
                'title' => ag($json, 'Name', '??'),
                'year' => ag($json, 'Year', 0000),
                'webhook' => [
                    'event' => $event,
                ],
            ],
            StateInterface::TYPE_EPISODE => [
                'via' => $this->name,
                'series' => ag($json, 'SeriesName', '??'),
                'year' => ag($json, 'Year', 0000),
                'season' => ag($json, 'SeasonNumber', 0),
                'episode' => ag($json, 'EpisodeNumber', 0),
                'title' => ag($json, 'Name', '??'),
                'webhook' => [
                    'event' => $event,
                ],
            ],
            default => throw new HttpException(sprintf('%s: Invalid content type.', afterLast(__CLASS__, '\\')), 400),
        };

        $providersId = [];

        foreach ($json as $key => $val) {
            if (!str_starts_with($key, 'Provider_')) {
                continue;
            }
            $providersId[self::afterString($key, 'Provider_')] = $val;
        }

        // We use SeriesName to overcome jellyfin webhook limitation, it does not send series id.
        if (StateInterface::TYPE_EPISODE === $type && null !== ag($json, 'SeriesName')) {
            $meta['parent'] = $this->getEpisodeParent(ag($json, 'ItemId'), ag($json, 'SeriesName'));
        }

        $row = [
            'type' => $type,
            'updated' => time(),
            'watched' => (int)(bool)ag($json, 'Played', ag($json, 'PlayedToCompletion', 0)),
            'meta' => $meta,
            ...$this->getGuids($providersId, $type)
        ];

        $entity = Container::get(StateInterface::class)::fromArray($row)->setIsTainted($isTainted);

        if (!$entity->hasGuids()) {
            throw new HttpException(
                sprintf(
                    '%s: No supported GUID was given. [%s]',
                    afterLast(__CLASS__, '\\'),
                    arrayToString(
                        [
                            'guids' => !empty($providersId) ? $providersId : 'None',
                            'rGuids' => $entity->hasRelativeGuid() ? $entity->getRelativeGuids() : 'None',
                        ]
                    )
                ), 400
            );
        }

        foreach ($entity->getPointers() as $guid) {
            $this->cacheData[$guid] = ag($json, 'Item.ItemId');
        }

        if (false === $isTainted && (true === Config::get('webhook.debug') || null !== ag(
                    $request->getQueryParams(),
                    'debug'
                ))) {
            saveWebhookPayload($this->name . '.' . $event, $request, [
                'entity' => $entity->getAll(),
                'payload' => $json,
            ]);
        }

        return $entity;
    }

    protected function getEpisodeParent(mixed $id, string|null $series): array
    {
        if (null !== $series && array_key_exists($series, $this->cacheShow)) {
            return $this->cacheShow[$series];
        }

        try {
            $response = $this->http->request(
                'GET',
                (string)$this->url->withPath(
                    sprintf('/Users/%s/items/' . $id, $this->user)
                ),
                $this->getHeaders()
            );

            if (200 !== $response->getStatusCode()) {
                return [];
            }

            $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

            if (null === ($type = ag($json, 'Type'))) {
                return [];
            }

            if (StateInterface::TYPE_EPISODE !== strtolower($type)) {
                return [];
            }

            if (null === ($seriesId = ag($json, 'SeriesId'))) {
                return [];
            }

            $response = $this->http->request(
                'GET',
                (string)$this->url->withPath(
                    sprintf('/Users/%s/items/' . $seriesId, $this->user)
                )->withQuery(http_build_query(['Fields' => 'ProviderIds'])),
                $this->getHeaders()
            );

            if (200 !== $response->getStatusCode()) {
                return [];
            }

            $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

            $series = $json['Name'] ?? $json['OriginalTitle'] ?? $json['Id'] ?? random_int(1, PHP_INT_MAX);

            $providersId = (array)ag($json, 'ProviderIds', []);

            if (!$this->hasSupportedIds($providersId)) {
                $this->cacheShow[$series] = [];
                return $this->cacheShow[$series];
            }

            $guids = [];

            foreach (Guid::fromArray($this->getGuids($providersId))->getPointers() as $guid) {
                [$type, $id] = explode('://', $guid);
                $guids[$type] = $id;
            }

            $this->cacheShow[$series] = $guids;

            return $this->cacheShow[$series];
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('Unable to decode %s response. Reason: \'%s\'.', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            );
            return [];
        } catch (Exception $e) {
            $this->logger->error(
                sprintf('ERROR: %s response. Reason: \'%s\'.', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            );
            return [];
        }
    }

    protected function getHeaders(): array
    {
        $opts = [
            'headers' => [
                'Accept' => 'application/json',
            ],
        ];

        if (true === $this->isEmby) {
            $opts['headers']['X-MediaBrowser-Token'] = $this->token;
        } else {
            $opts['headers']['X-Emby-Authorization'] = sprintf(
                'MediaBrowser Client="%s", Device="script", DeviceId="", Version="%s", Token="%s"',
                Config::get('name'),
                Config::get('version'),
                $this->token
            );
        }

        return array_replace_recursive($opts, $this->options['client'] ?? []);
    }

    protected function getLibraries(Closure $ok, Closure $error, bool $includeParent = false): array
    {
        $this->checkConfig(true);

        try {
            $this->logger->debug(
                sprintf('Requesting libraries From %s.', $this->name),
                ['url' => $this->url->getHost()]
            );

            $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
                http_build_query(
                    [
                        'Recursive' => 'false',
                        'enableUserData' => 'false',
                        'enableImages' => 'false',
                    ]
                )
            );

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                $this->logger->error(
                    sprintf(
                        'Request to %s responded with unexpected code (%d).',
                        $this->name,
                        $response->getStatusCode()
                    )
                );
                Data::add($this->name, 'no_import_update', true);
                return [];
            }

            $json = json_decode($response->getContent(false), true, flags: JSON_THROW_ON_ERROR);

            $listDirs = ag($json, 'Items', []);

            if (empty($listDirs)) {
                $this->logger->notice(sprintf('No libraries found at %s.', $this->name));
                Data::add($this->name, 'no_import_update', true);
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            Data::add($this->name, 'no_import_update', true);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('Unable to decode %s response. Reason: \'%s\'.', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            );
            Data::add($this->name, 'no_import_update', true);
            return [];
        }

        $ignoreIds = null;

        if (null !== ($this->options['ignore'] ?? null)) {
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', (string)$this->options['ignore']));
        }

        $promises = [];
        $ignored = $unsupported = 0;

        if (true === $includeParent) {
            foreach ($listDirs as $section) {
                $key = (string)ag($section, 'Id');
                $title = ag($section, 'Name', '???');

                if ('tvshows' !== ag($section, 'CollectionType', 'unknown')) {
                    continue;
                }

                $cName = sprintf('(%s) - (%s:%s)', $title, 'show', $key);

                if (null !== $ignoreIds && in_array($key, $ignoreIds, true)) {
                    continue;
                }

                $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
                    http_build_query(
                        [
                            'parentId' => $key,
                            'recursive' => 'false',
                            'enableUserData' => 'false',
                            'enableImages' => 'false',
                            'Fields' => 'ProviderIds,DateCreated,OriginalTitle',
                        ]
                    )
                );

                $this->logger->debug(
                    sprintf('Requesting %s - %s library parents content.', $this->name, $cName),
                    ['url' => $url]
                );

                try {
                    $promises[] = $this->http->request(
                        'GET',
                        (string)$url,
                        array_replace_recursive($this->getHeaders(), [
                            'user_data' => [
                                'ok' => $ok($cName, 'show', $url),
                                'error' => $error($cName, 'show', $url),
                            ]
                        ])
                    );
                } catch (ExceptionInterface $e) {
                    $this->logger->error(
                        sprintf(
                            'Request to %s library - %s parents failed. Reason: %s',
                            $this->name,
                            $cName,
                            $e->getMessage()
                        ),
                        ['url' => $url]
                    );
                    continue;
                }
            }
        }

        foreach ($listDirs as $section) {
            $key = (string)ag($section, 'Id');
            $title = ag($section, 'Name', '???');
            $type = ag($section, 'CollectionType', 'unknown');

            if ('movies' !== $type && 'tvshows' !== $type) {
                $unsupported++;
                $this->logger->debug(sprintf('Skipping %s library - %s. Not supported type.', $this->name, $title));

                continue;
            }

            $type = $type === 'movies' ? StateInterface::TYPE_MOVIE : StateInterface::TYPE_EPISODE;
            $cName = sprintf('(%s) - (%s:%s)', $title, $type, $key);

            if (null !== $ignoreIds && in_array($key, $ignoreIds, true)) {
                $ignored++;
                $this->logger->notice(
                    sprintf('Skipping %s library - %s. Ignored by user config option.', $this->name, $cName)
                );
                continue;
            }

            $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
                http_build_query(
                    [
                        'parentId' => $key,
                        'recursive' => 'true',
                        'enableUserData' => 'true',
                        'enableImages' => 'false',
                        'includeItemTypes' => 'Movie,Episode',
                        'Fields' => 'ProviderIds,DateCreated,OriginalTitle,SeasonUserData,DateLastSaved',
                    ]
                )
            );

            $this->logger->debug(sprintf('Requesting %s - %s library content.', $this->name, $cName), ['url' => $url]);

            try {
                $promises[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'ok' => $ok($cName, $type, $url),
                            'error' => $error($cName, $type, $url),
                        ]
                    ])
                );
            } catch (ExceptionInterface $e) {
                $this->logger->error(
                    sprintf('Request to %s library - %s failed. Reason: %s', $this->name, $cName, $e->getMessage()),
                    ['url' => $url]
                );
                continue;
            }
        }

        if (0 === count($promises)) {
            $this->logger->notice(
                sprintf(
                    'No requests were made to any of %s libraries. (total: %d, ignored: %d, Unsupported: %d).',
                    $this->name,
                    count($listDirs),
                    $ignored,
                    $unsupported
                )
            );
            Data::add($this->name, 'no_import_update', true);
            return [];
        }

        return $promises;
    }

    public function search(string $query, int $limit = 25): array
    {
        $this->checkConfig(true);

        try {
            $this->logger->debug(
                sprintf('Search for \'%s\' in %s.', $query, $this->name),
                ['url' => $this->url->getHost()]
            );

            $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
                http_build_query(
                    [
                        'searchTerm' => $query,
                        'Limit' => $limit,
                        'Recursive' => 'true',
                        'Fields' => 'ProviderIds',
                        'enableUserData' => 'true',
                        'enableImages' => 'false',
                        'IncludeItemTypes' => 'Episode,Movie,Series',
                    ]
                )
            );

            $this->logger->debug('Request', ['url' => $url]);

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                throw new RuntimeException(
                    sprintf(
                        'Request to %s responded with unexpected code (%d).',
                        $this->name,
                        $response->getStatusCode()
                    )
                );
            }

            $json = json_decode($response->getContent(false), true, flags: JSON_THROW_ON_ERROR);

            return ag($json, 'Items', []);
        } catch (ExceptionInterface|JsonException $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function listLibraries(): array
    {
        $this->checkConfig(true);

        try {
            $this->logger->debug(
                sprintf('Requesting libraries From %s.', $this->name),
                ['url' => $this->url->getHost()]
            );

            $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
                http_build_query(
                    [
                        'Recursive' => 'false',
                        'Fields' => 'ProviderIds',
                        'enableUserData' => 'true',
                        'enableImages' => 'false',
                    ]
                )
            );

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                $this->logger->error(
                    sprintf(
                        'Request to %s responded with unexpected code (%d).',
                        $this->name,
                        $response->getStatusCode()
                    )
                );
                return [];
            }

            $json = json_decode($response->getContent(false), true, flags: JSON_THROW_ON_ERROR);

            $listDirs = ag($json, 'Items', []);

            if (empty($listDirs)) {
                $this->logger->error(sprintf('No libraries found at %s.', $this->name));
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('Unable to decode %s response. Reason: \'%s\'.', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            );
            return [];
        }

        $ignoreIds = null;

        if (null !== ($this->options['ignore'] ?? null)) {
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', (string)$this->options['ignore']));
        }

        $list = [];

        foreach ($listDirs as $section) {
            $key = (string)ag($section, 'Id');
            $title = ag($section, 'Name', '???');
            $type = ag($section, 'CollectionType', 'unknown');
            $isIgnored = null !== $ignoreIds && in_array($key, $ignoreIds);

            $list[] = [
                'ID' => $key,
                'Title' => $title,
                'Type' => $type,
                'Ignored' => $isIgnored ? 'Yes' : 'No',
                'Supported' => 'movies' !== $type && 'tvshows' !== $type ? 'No' : 'Yes',
            ];
        }

        return $list;
    }

    public function pull(ImportInterface $mapper, DateTimeInterface|null $after = null): array
    {
        return $this->getLibraries(
            ok: function (string $cName, string $type) use ($after, $mapper) {
                return function (ResponseInterface $response) use ($mapper, $cName, $type, $after) {
                    try {
                        if (200 !== $response->getStatusCode()) {
                            $this->logger->error(
                                sprintf(
                                    'Request to %s - %s responded with (%d) unexpected code.',
                                    $this->name,
                                    $cName,
                                    $response->getStatusCode()
                                )
                            );
                            return;
                        }

                        // -- sandbox external library code to prevent complete failure when error occurs.
                        try {
                            $it = Items::fromIterable(
                                httpClientChunks($this->http->stream($response)),
                                [
                                    'pointer' => '/Items',
                                ],
                                [
                                    'decoder' => new ErrorWrappingDecoder(
                                        new ExtJsonDecoder(options: JSON_INVALID_UTF8_IGNORE)
                                    )
                                ]
                            );

                            $this->logger->info(sprintf('Parsing %s - %s response.', $this->name, $cName));

                            foreach ($it as $entity) {
                                if ($entity instanceof DecodingError) {
                                    $this->logger->debug(
                                        sprintf('Failed to decode one result of %s - %s response.', $this->name, $cName)
                                    );
                                    continue;
                                }
                                $this->processImport($mapper, $type, $cName, $entity, $after);
                            }
                        } catch (PathNotFoundException $e) {
                            $this->logger->error(
                                sprintf(
                                    'Failed to find media items path in %s - %s - response. Most likely empty section?',
                                    $this->name,
                                    $cName,
                                ),
                                [
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'kind' => get_class($e),
                                    'error' => $e->getMessage(),
                                ],
                            );
                            return;
                        } catch (Throwable $e) {
                            $this->logger->error(
                                sprintf(
                                    'Unable to parse %s - %s response.',
                                    $this->name,
                                    $cName,
                                ),
                                [
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'kind' => get_class($e),
                                    'error' => $e->getMessage(),
                                ],
                            );
                            return;
                        }

                        $this->logger->info(
                            sprintf(
                                'Finished Parsing %s - %s (%d objects) response.',
                                $this->name,
                                $cName,
                                Data::get("{$this->name}.{$cName}_total")
                            )
                        );
                    } catch (JsonException $e) {
                        $this->logger->error(
                            sprintf(
                                'Failed to decode %s - %s - response. Reason: \'%s\'.',
                                $this->name,
                                $cName,
                                $e->getMessage()
                            )
                        );
                        return;
                    }
                };
            },
            error: function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'url' => $url
                    ]
                );
            },
            includeParent: true,
        );
    }

    public function push(array $entities, DateTimeInterface|null $after = null): array
    {
        $this->checkConfig(true);

        $requests = [];

        foreach ($entities as &$entity) {
            if (false === ($this->options[ServerInterface::OPT_EXPORT_IGNORE_DATE] ?? false)) {
                if (null !== $after && $after->getTimestamp() > $entity->updated) {
                    $entity = null;
                    continue;
                }
            }

            $entity->jf_id = null;
            $entity->plex_guid = null;

            if (!$entity->hasGuids()) {
                continue;
            }

            foreach ($entity->getPointers() as $guid) {
                if (null === ($this->cacheData[$guid] ?? null)) {
                    continue;
                }
                $entity->jf_id = $this->cacheData[$guid];
                break;
            }
        }

        unset($entity);

        foreach ($entities as $entity) {
            if (StateInterface::TYPE_MOVIE === $entity->type) {
                $iName = sprintf(
                    '%s - [%s (%d)]',
                    $this->name,
                    $entity->meta['title'] ?? '??',
                    $entity->meta['year'] ?? 0000,
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - [%s - (%dx%d)]',
                        $this->name,
                        $entity->meta['series'] ?? '??',
                        $entity->meta['season'] ?? 0,
                        $entity->meta['episode'] ?? 0,
                    )
                );
            }

            if (null === ($entity->jf_id ?? null)) {
                $this->logger->notice(sprintf('Ignoring %s. Not found in cache.', $iName));
                continue;
            }

            try {
                $requests[] = $this->http->request(
                    'GET',
                    (string)$this->url->withPath(sprintf('/Users/%s/items', $this->user))->withQuery(
                        http_build_query(
                            [
                                'ids' => $entity->jf_id,
                                'Fields' => 'ProviderIds,DateCreated,OriginalTitle,SeasonUserData,DateLastSaved',
                                'enableUserData' => 'true',
                                'enableImages' => 'false',
                            ]
                        )
                    ),
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'state' => &$entity,
                        ]
                    ])
                );
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage());
            }
        }

        $stateRequests = [];

        foreach ($requests as $response) {
            try {
                $json = ag(
                        json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR),
                        'Items',
                        []
                    )[0] ?? [];

                $state = $response->getInfo('user_data')['state'];
                assert($state instanceof StateInterface);

                if (StateInterface::TYPE_MOVIE === $state->type) {
                    $iName = sprintf(
                        '%s - [%s (%d)]',
                        $this->name,
                        $state->meta['title'] ?? '??',
                        $state->meta['year'] ?? 0000,
                    );
                } else {
                    $iName = trim(
                        sprintf(
                            '%s - [%s - (%dx%d)]',
                            $this->name,
                            $state->meta['series'] ?? '??',
                            $state->meta['season'] ?? 0,
                            $state->meta['episode'] ?? 0,
                        )
                    );
                }

                if (empty($json)) {
                    $this->logger->info(sprintf('Ignoring %s. does not exists.', $iName));
                    continue;
                }

                $isWatched = (int)(bool)ag($json, 'UserData.Played', false);

                if ($state->watched === $isWatched) {
                    $this->logger->debug(sprintf('Ignoring %s. State is unchanged.', $iName));
                    continue;
                }

                if (false === ($this->options[ServerInterface::OPT_EXPORT_IGNORE_DATE] ?? false)) {
                    $date = ag(
                        $json,
                        'UserData.LastPlayedDate',
                        ag($json, 'DateCreated', ag($json, 'PremiereDate', null))
                    );

                    if (null === $date) {
                        $this->logger->error(sprintf('Ignoring %s. No date is set.', $iName));
                        continue;
                    }

                    $date = strtotime($date);

                    if ($date >= $state->updated) {
                        $this->logger->debug(sprintf('Ignoring %s. Date is newer then what in db.', $iName));
                        continue;
                    }
                }

                $stateRequests[] = $this->http->request(
                    1 === $state->watched ? 'POST' : 'DELETE',
                    (string)$this->url->withPath(sprintf('/Users/%s/PlayedItems/%s', $this->user, ag($json, 'Id'))),
                    array_replace_recursive(
                        $this->getHeaders(),
                        [
                            'user_data' => [
                                'state' => 1 === $state->watched ? 'Watched' : 'Unwatched',
                                'itemName' => $iName,
                            ],
                        ]
                    )
                );
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage());
            }
        }

        unset($requests);

        return $stateRequests;
    }

    public function export(ExportInterface $mapper, DateTimeInterface|null $after = null): array
    {
        return $this->getLibraries(
            ok: function (string $cName, string $type) use ($mapper, $after) {
                return function (ResponseInterface $response) use ($mapper, $cName, $type, $after) {
                    try {
                        if (200 !== $response->getStatusCode()) {
                            $this->logger->error(
                                sprintf(
                                    'Request to %s - %s responded unexpected http status code with (%d).',
                                    $this->name,
                                    $cName,
                                    $response->getStatusCode()
                                )
                            );
                            return;
                        }

                        try {
                            $it = Items::fromIterable(
                                httpClientChunks($this->http->stream($response)),
                                [
                                    'pointer' => '/Items',
                                ],
                                [
                                    'decoder' => new ErrorWrappingDecoder(
                                        new ExtJsonDecoder(options: JSON_INVALID_UTF8_IGNORE)
                                    )
                                ]
                            );

                            $this->logger->info(sprintf('Parsing %s - %s response.', $this->name, $cName));

                            foreach ($it as $entity) {
                                if ($entity instanceof DecodingError) {
                                    $this->logger->debug(
                                        sprintf('Failed to decode one result of %s - %s response.', $this->name, $cName)
                                    );
                                    continue;
                                }
                                $this->processExport($mapper, $type, $cName, $entity, $after);
                            }
                        } catch (PathNotFoundException $e) {
                            $this->logger->error(
                                sprintf(
                                    'Failed to find media items path in %s - %s - response. Most likely empty section?',
                                    $this->name,
                                    $cName,
                                ),
                                [
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'kind' => get_class($e),
                                    'error' => $e->getMessage(),
                                ],
                            );
                            return;
                        } catch (Throwable $e) {
                            $this->logger->error(
                                sprintf(
                                    'Unable to parse %s - %s response.',
                                    $this->name,
                                    $cName,
                                ),
                                [
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'kind' => get_class($e),
                                    'error' => $e->getMessage(),
                                ],
                            );
                            return;
                        }

                        $this->logger->info(
                            sprintf(
                                'Finished Parsing %s - %s (%d objects) response.',
                                $this->name,
                                $cName,
                                Data::get("{$this->name}.{$type}_total")
                            )
                        );
                    } catch (JsonException $e) {
                        $this->logger->error(
                            sprintf(
                                'Failed to decode %s - %s - response. Reason: \'%s\'.',
                                $this->name,
                                $cName,
                                $e->getMessage()
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                            ]
                        );
                        return;
                    }
                };
            },
            error: function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'url' => $url
                    ]
                );
            },
            includeParent: false,
        );
    }

    public function cache(): array
    {
        return $this->getLibraries(
            ok: function (string $cName, string $type) {
                return function (ResponseInterface $response) use ($cName, $type) {
                    try {
                        if (200 !== $response->getStatusCode()) {
                            $this->logger->error(
                                sprintf(
                                    'Request to %s - %s responded with (%d) unexpected code.',
                                    $this->name,
                                    $cName,
                                    $response->getStatusCode()
                                )
                            );
                            return;
                        }

                        try {
                            $it = Items::fromIterable(
                                httpClientChunks($this->http->stream($response)),
                                [
                                    'pointer' => '/Items',
                                ],
                                [
                                    'decoder' => new ErrorWrappingDecoder(
                                        new ExtJsonDecoder(options: JSON_INVALID_UTF8_IGNORE)
                                    )
                                ]
                            );

                            $this->logger->info(
                                sprintf('Parsing %s - %s response.', $this->name, $cName)
                            );

                            foreach ($it as $entity) {
                                if ($entity instanceof DecodingError) {
                                    $this->logger->debug(
                                        sprintf('Failed to decode one result of %s - %s response.', $this->name, $cName)
                                    );
                                    continue;
                                }
                                $this->processForCache($entity, $type, $cName);
                            }
                        } catch (PathNotFoundException $e) {
                            $this->logger->error(
                                sprintf(
                                    'Failed to find media items path in %s - %s - response. Most likely empty section?',
                                    $this->name,
                                    $cName,
                                ),
                                [
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'kind' => get_class($e),
                                    'error' => $e->getMessage(),
                                ],
                            );
                            return;
                        } catch (Throwable $e) {
                            $this->logger->error(
                                sprintf(
                                    'Unable to parse %s - %s response.',
                                    $this->name,
                                    $cName,
                                ),
                                [
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'kind' => get_class($e),
                                    'error' => $e->getMessage(),
                                ],
                            );
                            return;
                        }

                        $this->logger->info(sprintf('Finished Parsing %s - %s response.', $this->name, $cName));
                    } catch (JsonException $e) {
                        $this->logger->error(
                            sprintf(
                                'Failed to decode %s - %s - response. Reason: \'%s\'.',
                                $this->name,
                                $cName,
                                $e->getMessage()
                            )
                        );
                        return;
                    }
                };
            },
            error: function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'url' => $url
                    ]
                );
            },
            includeParent: true,
        );
    }

    protected function processExport(
        ExportInterface $mapper,
        string $type,
        string $library,
        StdClass $item,
        DateTimeInterface|null $after = null
    ): void {
        Data::increment($this->name, $type . '_total');

        try {
            if (StateInterface::TYPE_MOVIE === $type) {
                $iName = sprintf(
                    '%s - %s - [%s (%d)]',
                    $this->name,
                    $library,
                    $item->Name ?? $item->OriginalTitle ?? '??',
                    $item->ProductionYear ?? 0000
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - %s - [%s - (%dx%d)]',
                        $this->name,
                        $library,
                        $item->SeriesName ?? '??',
                        $item->ParentIndexNumber ?? 0,
                        $item->IndexNumber ?? 0,
                    )
                );
            }

            $date = $item->UserData?->LastPlayedDate ?? $item->DateCreated ?? $item->PremiereDate ?? null;

            if (null === $date) {
                $this->logger->error(sprintf('Ignoring %s. No date is set.', $iName), [
                    'item' => (array)$item,
                ]);
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $rItem = $this->createEntity($item, $type);

            if (!$rItem->hasGuids()) {
                $guids = (array)($item->ProviderIds ?? []);
                $this->logger->debug(sprintf('Ignoring %s. No Valid/supported guids.', $iName), [
                    'guids' => !empty($guids) ? $guids : 'None',
                    'rGuids' => $rItem->hasRelativeGuid() ? $rItem->getRelativeGuids() : 'None',
                ]);
                Data::increment($this->name, $type . '_ignored_no_supported_guid');
                return;
            }

            if (null !== $after && $rItem->updated >= $after->getTimestamp()) {
                $this->logger->debug(sprintf('Ignoring %s. Ignored date is equal or newer than lastSync.', $iName), [
                    'itemDate' => makeDate($rItem->updated),
                    'lastSync' => makeDate($after->getTimestamp()),
                ]);
                Data::increment($this->name, $type . '_ignored_date_is_equal_or_higher');
                return;
            }

            if (null === ($entity = $mapper->get($rItem))) {
                $guids = (array)($item->ProviderIds ?? []);
                $this->logger->debug(
                    sprintf(
                        'Ignoring %s. [State: %s] - Not found in db.',
                        $iName,
                        $rItem->watched ? 'Played' : 'Unplayed'
                    ),
                    [
                        'guids' => !empty($guids) ? $guids : 'None',
                        'rGuids' => $rItem->hasRelativeGuid() ? $rItem->getRelativeGuids() : 'None',
                    ]
                );
                Data::increment($this->name, $type . '_ignored_not_found_in_db');
                return;
            }

            if ($rItem->watched === $entity->watched) {
                $this->logger->debug(sprintf('Ignoring %s. State is equal to db state.', $iName), [
                    'State' => $entity->watched ? 'Played' : 'Unplayed'
                ]);
                Data::increment($this->name, $type . '_ignored_state_unchanged');
                return;
            }

            if (false === ($this->options[ServerInterface::OPT_EXPORT_IGNORE_DATE] ?? false)) {
                if ($rItem->updated >= $entity->updated) {
                    $this->logger->debug(sprintf('Ignoring %s. Date is newer or equal to db entry.', $iName), [
                        'db' => makeDate($rItem->updated),
                        'server' => makeDate($entity->updated),
                    ]);
                    Data::increment($this->name, $type . '_ignored_date_is_newer');
                    return;
                }
            }

            $this->logger->info(sprintf('Queuing %s.', $iName), [
                'State' => [
                    'db' => $entity->watched ? 'Played' : 'Unplayed',
                    'server' => $rItem->watched ? 'Played' : 'Unplayed'
                ],
            ]);

            $mapper->queue(
                $this->http->request(
                    1 === $entity->watched ? 'POST' : 'DELETE',
                    (string)$this->url->withPath(sprintf('/Users/%s/PlayedItems/%s', $this->user, $item->Id)),
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'state' => 1 === $entity->watched ? 'Watched' : 'Unwatched',
                            'itemName' => $iName,
                        ],
                    ])
                )
            );
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    protected function processShow(StdClass $item, string $library): void
    {
        $iName = sprintf(
            '%s - %s - [%s (%d)]',
            $this->name,
            $library,
            $item->Name ?? $item->OriginalTitle ?? '??',
            $item->ProductionYear ?? 0000
        );

        $this->logger->debug(sprintf('Processing %s. For GUIDs.', $iName));

        $providersId = (array)($item->ProviderIds ?? []);

        if (!$this->hasSupportedIds($providersId)) {
            $message = sprintf('Ignoring %s. No valid/supported GUIDs.', $iName);
            if (empty($providersId)) {
                $message .= ' Most likely unmatched TV show.';
            }
            $this->logger->info($message, ['guids' => empty($providersId) ? 'None' : $providersId]);
            return;
        }

        $guids = [];

        foreach (Guid::fromArray($this->getGuids($providersId))->getPointers() as $guid) {
            [$type, $id] = explode('://', $guid);
            $guids[$type] = $id;
        }

        $this->showInfo[$item->Id] = $guids;
    }

    protected function processImport(
        ImportInterface $mapper,
        string $type,
        string $library,
        StdClass $item,
        DateTimeInterface|null $after = null
    ): void {
        try {
            if ('show' === $type) {
                $this->processShow($item, $library);
                return;
            }

            Data::increment($this->name, $type . '_total');
            Data::increment($this->name, $library . '_total');

            if (StateInterface::TYPE_MOVIE === $type) {
                $iName = sprintf(
                    '%s - %s - [%s (%d)]',
                    $this->name,
                    $library,
                    $item->Name ?? $item->OriginalTitle ?? '??',
                    $item->ProductionYear ?? 0000
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - %s - [%s - (%dx%d)]',
                        $this->name,
                        $library,
                        $item->SeriesName ?? '??',
                        $item->ParentIndexNumber ?? 0,
                        $item->IndexNumber ?? 0,
                    )
                );
            }

            $date = $item->UserData?->LastPlayedDate ?? $item->DateCreated ?? $item->PremiereDate ?? null;

            if (null === $date) {
                $this->logger->error(sprintf('Ignoring %s. No date is set.', $iName));
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $entity = $this->createEntity($item, $type);

            if (!$entity->hasGuids()) {
                if (true === Config::get('debug.import')) {
                    $name = Config::get('tmpDir') . '/debug/' . $this->name . '.' . $item->Id . '.json';

                    if (!file_exists($name)) {
                        file_put_contents($name, json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                }

                $guids = (array)($item->ProviderIds ?? []);

                $message = sprintf('Ignoring %s. No valid/supported GUIDs.', $iName);

                if (empty($guids)) {
                    $message .= ' Most likely unmatched item.';
                }

                $this->logger->info($message, [
                    'guids' => empty($guids) ? 'None' : $guids,
                    'rGuids' => $entity->hasRelativeGuid() ? $entity->getRelativeGuids() : 'None',
                ]);

                Data::increment($this->name, $type . '_ignored_no_supported_guid');

                return;
            }

            $mapper->add($this->name, $iName, $entity, ['after' => $after]);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    protected function processForCache(StdClass $item, string $type, string $library): void
    {
        try {
            if ('show' === $type) {
                $this->processShow($item, $library);
                return;
            }

            $date = $item->UserData?->LastPlayedDate ?? $item->DateCreated ?? $item->PremiereDate ?? null;

            if (null === $date) {
                return;
            }

            $this->createEntity($item, $type);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    protected function getGuids(array $ids, string|null $type = null): array
    {
        $guid = [];

        $ids = array_change_key_case($ids, CASE_LOWER);

        foreach ($ids as $key => $value) {
            if (null === (self::GUID_MAPPER[$key] ?? null) || empty($value)) {
                continue;
            }

            if (null !== $type) {
                $value = $type . '/' . $value;
            }

            $guid[self::GUID_MAPPER[$key]] = $value;
        }

        return $guid;
    }

    protected function hasSupportedIds(array $ids): bool
    {
        $ids = array_change_key_case($ids, CASE_LOWER);

        foreach ($ids as $key => $value) {
            if (null !== (self::GUID_MAPPER[$key] ?? null) && !empty($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function __destruct()
    {
        if (!empty($this->cacheKey)) {
            $this->cache->set($this->cacheKey, $this->cacheData, new DateInterval('P1Y'));
        }

        if (!empty($this->cacheShowKey)) {
            $this->cache->set($this->cacheShowKey, $this->cacheShow, new DateInterval('PT30M'));
        }
    }

    protected static function afterString(string $subject, string $search): string
    {
        return empty($search) ? $subject : array_reverse(explode($search, $subject, 2))[0];
    }

    protected function checkConfig(bool $checkUrl = true, bool $checkToken = true, bool $checkUser = true): void
    {
        if (true === $checkUrl && !($this->url instanceof UriInterface)) {
            throw new RuntimeException(afterLast(__CLASS__, '\\') . ': No host was set.');
        }

        if (true === $checkToken && null === $this->token) {
            throw new RuntimeException(afterLast(__CLASS__, '\\') . ': No token was set.');
        }

        if (true === $checkUser && null === $this->user) {
            throw new RuntimeException(afterLast(__CLASS__, '\\') . ': No User was set.');
        }
    }

    private function createEntity(stdClass $item, string $type): StateEntity
    {
        $guids = $this->getGuids((array)($item->ProviderIds ?? []), $type);

        foreach (Guid::fromArray($guids)->getPointers() as $guid) {
            $this->cacheData[$guid] = $item->Id;
        }

        $date = strtotime($item->UserData?->LastPlayedDate ?? $item->DateCreated ?? $item->PremiereDate);

        if (StateInterface::TYPE_MOVIE === $type) {
            $meta = [
                'via' => $this->name,
                'title' => $item->Name ?? $item->OriginalTitle ?? '??',
                'year' => $item->ProductionYear ?? 0000,
                'date' => makeDate($item->PremiereDate ?? $item->ProductionYear ?? 'now')->format('Y-m-d'),
            ];
        } else {
            $meta = [
                'via' => $this->name,
                'series' => $item->SeriesName ?? '??',
                'year' => $item->ProductionYear ?? 0000,
                'season' => $item->ParentIndexNumber ?? 0,
                'episode' => $item->IndexNumber ?? 0,
                'title' => $item->Name ?? '',
                'date' => makeDate($item->PremiereDate ?? $item->ProductionYear ?? 'now')->format('Y-m-d'),
            ];

            if (null !== ($item->SeriesId ?? null)) {
                $meta['parent'] = $this->showInfo[$item->SeriesId] ?? [];
            }
        }

        return Container::get(StateInterface::class)::fromArray(
            [
                'type' => $type,
                'updated' => $date,
                'watched' => (int)(bool)($item->UserData?->Played ?? false),
                'meta' => $meta,
                ...$guids,
            ]
        );
    }
}
