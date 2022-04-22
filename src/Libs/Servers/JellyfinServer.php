<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Entity\StateInterface;
use App\Libs\Guid;
use App\Libs\HttpException;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Mappers\ImportInterface;
use Closure;
use DateInterval;
use DateTimeInterface;
use JsonException;
use JsonMachine\Exception\PathNotFoundException;
use JsonMachine\Items;
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
    protected string $cacheKey;
    protected array $cacheData = [];
    protected string|int|null $uuid = null;

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

        if ($cloned->cache->has($cloned->cacheKey)) {
            $cloned->cacheData = $cloned->cache->get($cloned->cacheKey);
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

        $date = time();

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

        $guids = [];

        foreach ($json as $key => $val) {
            if (str_starts_with($key, 'Provider_')) {
                $guids[self::afterString($key, 'Provider_')] = $val;
            }
        }

        if (!$this->hasSupportedIds($guids)) {
            throw new HttpException(
                sprintf('%s: No supported GUID was given. [%s]', afterLast(__CLASS__, '\\'), arrayToString($guids)),
                400
            );
        }

        $guids = $this->getGuids($type, $guids);

        foreach (Guid::fromArray($guids)->getPointers() as $guid) {
            $this->cacheData[$guid] = ag($json, 'Item.ItemId');
        }

        $isWatched = (int)(bool)ag($json, 'Played', ag($json, 'PlayedToCompletion', 0));

        $row = [
            'type' => $type,
            'updated' => $date,
            'watched' => $isWatched,
            'meta' => $meta,
            ...$guids
        ];

        if (true === Config::get('webhook.debug') || null !== ag($request->getQueryParams(), 'debug')) {
            saveWebhookPayload($request, "{$this->name}.{$event}", $json + ['entity' => $row]);
        }

        return Container::get(StateInterface::class)::fromArray($row)->setIsTainted(
            in_array($event, self::WEBHOOK_TAINTED_EVENTS)
        );
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

    protected function getLibraries(Closure $ok, Closure $error): array
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
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', $this->options['ignore']));
        }

        $promises = [];
        $ignored = $unsupported = 0;

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
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', $this->options['ignore']));
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
            function (string $cName, string $type) use ($after, $mapper) {
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

                        $it = Items::fromIterable(
                            httpClientChunks($this->http->stream($response)),
                            [
                                'pointer' => '/Items',
                            ],
                        );

                        $this->logger->info(sprintf('Parsing Successful %s - %s response.', $this->name, $cName));

                        foreach ($it as $entity) {
                            $this->processImport($mapper, $type, $cName, $entity, $after);
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
                    } catch (PathNotFoundException $e) {
                        $this->logger->error(
                            sprintf(
                                'Failed to find media items path in %s - %s - response. Most likely empty section? reported error: \'%s\'.',
                                $this->name,
                                $cName,
                                $e->getMessage()
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                            ],
                        );
                        return;
                    }
                };
            },
            function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'url' => $url
                    ]
                );
            }
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
                        '%s - [%s - (%dx%d) - %s]',
                        $this->name,
                        $entity->meta['series'] ?? '??',
                        $entity->meta['season'] ?? 0,
                        $entity->meta['episode'] ?? 0,
                        $entity->meta['title'] ?? '??',
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
                            '%s - [%s - (%dx%d) - %s]',
                            $this->name,
                            $state->meta['series'] ?? '??',
                            $state->meta['season'] ?? 0,
                            $state->meta['episode'] ?? 0,
                            $state->meta['title'] ?? '??',
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
            function (string $cName, string $type) use ($mapper, $after) {
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

                        $it = Items::fromIterable(
                            httpClientChunks($this->http->stream($response)),
                            [
                                'pointer' => '/Items',
                            ],
                        );

                        $this->logger->info(sprintf('Parsing Successful %s - %s response.', $this->name, $cName));

                        foreach ($it as $entity) {
                            $this->processExport($mapper, $type, $cName, $entity, $after);
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
                    } catch (PathNotFoundException $e) {
                        $this->logger->error(
                            sprintf(
                                'Failed to find media items path in %s - %s - response. Most likely empty section? reported error: \'%s\'.',
                                $this->name,
                                $cName,
                                $e->getMessage()
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                            ],
                        );
                        return;
                    }
                };
            },
            function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'url' => $url
                    ]
                );
            }
        );
    }

    public function cache(): array
    {
        return $this->getLibraries(
            function (string $cName, string $type) {
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

                        $it = Items::fromIterable(
                            httpClientChunks($this->http->stream($response)),
                            [
                                'pointer' => '/Items',
                            ],
                        );

                        $this->logger->info(sprintf('Processing Successful %s - %s response.', $this->name, $cName));

                        foreach ($it as $entity) {
                            $this->processForCache($type, $entity);
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
                    } catch (PathNotFoundException $e) {
                        $this->logger->error(
                            sprintf(
                                'Failed to find media items path in %s - %s - response. Most likely empty section? reported error: \'%s\'.',
                                $this->name,
                                $cName,
                                $e->getMessage()
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                            ],
                        );
                        return;
                    }
                };
            },
            function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'url' => $url
                    ]
                );
            }
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
                        '%s - %s - [%s - (%dx%d) - %s]',
                        $this->name,
                        $library,
                        $item->SeriesName ?? '??',
                        $item->ParentIndexNumber ?? 0,
                        $item->IndexNumber ?? 0,
                        $item->Name ?? ''
                    )
                );
            }

            if (!$this->hasSupportedIds((array)($item->ProviderIds ?? []))) {
                $this->logger->debug(
                    sprintf('Ignoring %s. No supported guid.', $iName),
                    (array)($item->ProviderIds ?? [])
                );
                Data::increment($this->name, $type . '_ignored_no_supported_guid');
                return;
            }

            $guids = $this->getGuids($type, (array)($item->ProviderIds ?? []));

            foreach (Guid::fromArray($guids)->getPointers() as $guid) {
                $this->cacheData[$guid] = $item->Id;
            }

            $date = $item->UserData?->LastPlayedDate ?? $item->DateCreated ?? $item->PremiereDate ?? null;

            if (null === $date) {
                $this->logger->error(sprintf('Ignoring %s. No date is set.', $iName));
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $date = strtotime($date);

            if (null !== $after && $date >= $after->getTimestamp()) {
                $this->logger->debug(sprintf('Ignoring %s. Ignored date is equal or newer than lastSync.', $iName));
                Data::increment($this->name, $type . '_ignored_date_is_equal_or_higher');
                return;
            }

            $isWatched = (int)(bool)($item->UserData?->Played ?? false);

            if (null === ($entity = $mapper->findByIds($guids))) {
                $this->logger->debug(
                    sprintf('Ignoring %s. [State: %s] - Not found in db.', $iName, $isWatched ? 'Played' : 'Unplayed'),
                    (array)($item->ProviderIds ?? [])
                );
                Data::increment($this->name, $type . '_ignored_not_found_in_db');
                return;
            }

            if (false === ($this->options[ServerInterface::OPT_EXPORT_IGNORE_DATE] ?? false)) {
                if ($date >= $entity->updated) {
                    $this->logger->debug(sprintf('Ignoring %s. Date is newer then what in db.', $iName));
                    Data::increment($this->name, $type . '_ignored_date_is_newer');
                    return;
                }
            }


            if ($isWatched === $entity->watched) {
                $this->logger->debug(sprintf('Ignoring %s. State is unchanged.', $iName));
                Data::increment($this->name, $type . '_ignored_state_unchanged');
                return;
            }

            $this->logger->debug(sprintf('Queuing %s.', $iName));

            $mapper->queue(
                $this->http->request(
                    1 === $entity->watched ? 'POST' : 'DELETE',
                    (string)$this->url->withPath(sprintf('/Users/%s/PlayedItems/%s', $this->user, $item->Id)),
                    array_replace_recursive(
                        $this->getHeaders(),
                        [
                            'user_data' => [
                                'state' => 1 === $entity->watched ? 'Watched' : 'Unwatched',
                                'itemName' => $iName,
                            ],
                        ]
                    )
                )
            );
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    protected function processImport(
        ImportInterface $mapper,
        string $type,
        string $library,
        StdClass $item,
        DateTimeInterface|null $after = null
    ): void {
        try {
            Data::increment($this->name, $library . '_total');
            Data::increment($this->name, $type . '_total');

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
                        '%s - %s - [%s - (%dx%d) - %s]',
                        $this->name,
                        $library,
                        $item->SeriesName ?? '??',
                        $item->ParentIndexNumber ?? 0,
                        $item->IndexNumber ?? 0,
                        $item->Name ?? ''
                    )
                );
            }

            if (!$this->hasSupportedIds((array)($item->ProviderIds ?? []))) {
                if (true === Config::get('debug.import')) {
                    $name = $this->name . '.' . ($item->Id ?? 'r' . random_int(1, PHP_INT_MAX)) . '.json';

                    if (!file_exists($name)) {
                        file_put_contents($name, json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                }

                $this->logger->notice(
                    sprintf('Ignoring %s. No valid GUIDs.', $iName),
                    (array)($item->ProviderIds ?? [])
                );

                Data::increment($this->name, $type . '_ignored_no_supported_guid');

                return;
            }

            $guids = $this->getGuids($type, (array)($item->ProviderIds ?? []));

            foreach (Guid::fromArray($guids)->getPointers() as $guid) {
                $this->cacheData[$guid] = $item->Id;
            }

            $date = $item->UserData?->LastPlayedDate ?? $item->DateCreated ?? $item->PremiereDate ?? null;

            if (null === $date) {
                $this->logger->error(sprintf('Ignoring %s. No date is set.', $iName));
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $date = strtotime($date);

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
            }

            $row = [
                'type' => $type,
                'updated' => $date,
                'watched' => (int)(bool)($item->UserData?->Played ?? false),
                'meta' => $meta,
                ...$guids,
            ];

            $mapper->add($this->name, $iName, Container::get(StateInterface::class)::fromArray($row), [
                'after' => $after,
                self::OPT_IMPORT_UNWATCHED => (bool)($this->options[self::OPT_IMPORT_UNWATCHED] ?? false),
            ]);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    protected function processForCache(string $type, StdClass $item): void
    {
        try {
            if (!$this->hasSupportedIds((array)($item->ProviderIds ?? []))) {
                return;
            }
            $guids = $this->getGuids($type, (array)($item->ProviderIds ?? []));

            foreach (Guid::fromArray($guids)->getPointers() as $guid) {
                $this->cacheData[$guid] = $item->Id;
            }
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    protected function getGuids(string $type, array $ids): array
    {
        $guid = [];

        $ids = array_change_key_case($ids, CASE_LOWER);

        foreach ($ids as $key => $value) {
            if (null === (self::GUID_MAPPER[$key] ?? null) || empty($value)) {
                continue;
            }

            if ($key !== 'plex') {
                $value = $type . '/' . $value;
            }

            if ('string' !== Guid::SUPPORTED[self::GUID_MAPPER[$key]]) {
                settype($value, Guid::SUPPORTED[self::GUID_MAPPER[$key]]);
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
}
