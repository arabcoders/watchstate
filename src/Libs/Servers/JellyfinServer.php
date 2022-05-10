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
    public const NAME = 'JellyfinBackend';

    protected const GUID_MAPPER = [
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
            throw new RuntimeException(self::NAME . ': No token is set.');
        }

        $cloned = clone $this;

        $cloned->cacheData = [];
        $cloned->cacheShow = [];
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

        return $cloned;
    }

    public function getServerUUID(bool $forceRefresh = false): int|string|null
    {
        if (false === $forceRefresh && null !== $this->uuid) {
            return $this->uuid;
        }

        $this->checkConfig(checkUser: false);

        $url = $this->url->withPath('/system/Info');

        $this->logger->debug(sprintf('%s: Requesting server Unique id.', $this->name), ['url' => $url]);

        $response = $this->http->request('GET', (string)$url, $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            $this->logger->error(
                sprintf(
                    '%s: Request to get server unique id responded with unexpected http status code \'%d\'.',
                    $this->name,
                    $response->getStatusCode()
                )
            );
            return null;
        }

        $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->uuid = ag($json, 'Id', null);

        return $this->uuid;
    }

    public function getUsersList(array $opts = []): array
    {
        $this->checkConfig(checkUser: false);

        $url = $this->url->withPath('/Users/');

        $response = $this->http->request('GET', (string)$url, $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(
                sprintf(
                    '%s: Request to get users list responded with unexpected code \'%d\'.',
                    $this->name,
                    $response->getStatusCode()
                )
            );
        }

        $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $list = [];

        foreach ($json ?? [] as $user) {
            $date = ag($user, ['LastActivityDate', 'LastLoginDate'], null);

            $data = [
                'user_id' => ag($user, 'Id'),
                'username' => ag($user, 'Name'),
                'is_admin' => ag($user, 'Policy.IsAdministrator') ? 'Yes' : 'No',
                'is_hidden' => ag($user, 'Policy.IsHidden') ? 'Yes' : 'No',
                'is_disabled' => ag($user, 'Policy.IsDisabled') ? 'Yes' : 'No',
                'updated_at' => null !== $date ? makeDate($date) : 'Never',
            ];

            if (true === ag($opts, 'tokens', false)) {
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

    public static function processRequest(ServerRequestInterface $request, array $opts = []): ServerRequestInterface
    {
        $logger = null;

        try {
            $logger = $opts[LoggerInterface::class] ?? Container::get(LoggerInterface::class);

            $userAgent = ag($request->getServerParams(), 'HTTP_USER_AGENT', '');

            if (false === str_starts_with($userAgent, 'Jellyfin-Server/')) {
                return $request;
            }

            $payload = (string)$request->getBody();

            if (null === ($json = json_decode(json: $payload, associative: true, flags: JSON_INVALID_UTF8_IGNORE))) {
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
        } catch (Throwable $e) {
            $logger?->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
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

        if (null === $type || false === in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            throw new HttpException(sprintf('%s: Not allowed type [%s]', self::NAME, $type), 200);
        }

        $type = strtolower($type);

        if (null === $event || false === in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            throw new HttpException(sprintf('%s: Not allowed event [%s]', self::NAME, $event), 200);
        }

        $isTainted = in_array($event, self::WEBHOOK_TAINTED_EVENTS);

        $providersId = [];

        foreach ($json as $key => $val) {
            if (false === str_starts_with($key, 'Provider_')) {
                continue;
            }
            $providersId[after($key, 'Provider_')] = $val;
        }

        $row = [
            'type' => $type,
            'updated' => strtotime(ag($json, ['UtcTimestamp', 'Timestamp'], 'now')),
            'watched' => (int)(bool)ag($json, ['Played', 'PlayedToCompletion'], 0),
            'via' => $this->name,
            'title' => ag($json, ['Name', 'OriginalTitle'], '??'),
            'year' => ag($json, 'Year', 0000),
            'season' => null,
            'episode' => null,
            'parent' => [],
            'guids' => $this->getGuids($providersId),
            'extra' => [
                'date' => makeDate($item->PremiereDate ?? $item->ProductionYear ?? 'now')->format('Y-m-d'),
                'webhook' => [
                    'event' => $event,
                ],
            ],
        ];

        if (StateInterface::TYPE_EPISODE === $type) {
            $seriesName = ag($json, 'SeriesName');
            $row['title'] = $seriesName ?? '??';
            $row['season'] = ag($json, 'SeasonNumber', 0);
            $row['episode'] = ag($json, 'EpisodeNumber', 0);

            if (null !== ($epTitle = ag($json, ['Name', 'OriginalTitle'], null))) {
                $row['extra']['title'] = $epTitle;
            }

            if (null !== $seriesName) {
                $row['parent'] = $this->getEpisodeParent(ag($json, 'ItemId'), $seriesName . ':' . $row['year']);
            }
        }

        $entity = Container::get(StateInterface::class)::fromArray($row)->setIsTainted($isTainted);

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $message = sprintf('%s: No valid/supported External ids.', self::NAME);

            if (empty($providersId)) {
                $message .= ' Most likely unmatched movie/episode or show.';
            }

            $message .= sprintf(' [%s].', arrayToString(['guids' => !empty($providersId) ? $providersId : 'None']));

            throw new HttpException($message, 400);
        }

        foreach ([...$entity->getRelativePointers(), ...$entity->getPointers()] as $guid) {
            $this->cacheData[$guid] = ag($json, 'Item.ItemId');
        }

        $savePayload = true === Config::get('webhook.debug') || null !== ag($request->getQueryParams(), 'debug');

        if (false === $isTainted && $savePayload) {
            saveWebhookPayload($this->name . '.' . $event, $request, $entity);
        }

        return $entity;
    }

    public function search(string $query, int $limit = 25): array
    {
        $this->checkConfig(true);

        try {
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

            $this->logger->debug(sprintf('%s: Sending search request for \'%s\'.', $this->name, $query), [
                'url' => $url
            ]);

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                throw new RuntimeException(
                    sprintf(
                        '%s: Search request for \'%s\' responded with unexpected http status code \'%d\'.',
                        $this->name,
                        $query,
                        $response->getStatusCode()
                    )
                );
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            return ag($json, 'Items', []);
        } catch (ExceptionInterface|JsonException $e) {
            throw new RuntimeException(get_class($e) . ': ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function listLibraries(): array
    {
        $this->checkConfig(true);

        try {
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

            $this->logger->debug(sprintf('%s: Get list of server libraries.', $this->name), ['url' => $url]);

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                $this->logger->error(
                    sprintf(
                        '%s: library list request responded with unexpected code \'%d\'.',
                        $this->name,
                        $response->getStatusCode()
                    )
                );
                return [];
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            $listDirs = ag($json, 'Items', []);

            if (empty($listDirs)) {
                $this->logger->notice(
                    sprintf(
                        '%s: Responded with empty list of libraries. Possibly the token has no access to the libraries?',
                        $this->name
                    )
                );
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error(
                sprintf('%s: list of libraries request failed. %s', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                ],
            );
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('%s: Failed to decode library list JSON response. %s', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );
            return [];
        }

        if (null !== ($ignoreIds = ag($this->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => (int)trim($v), explode(',', (string)$ignoreIds));
        }

        $list = [];

        foreach ($listDirs as $section) {
            $key = (string)ag($section, 'Id');
            $type = ag($section, 'CollectionType', 'unknown');

            $list[] = [
                'ID' => $key,
                'Title' => ag($section, 'Name', '???'),
                'Type' => $type,
                'Ignored' => null !== $ignoreIds && in_array($key, $ignoreIds) ? 'Yes' : 'No',
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
                    if (200 !== $response->getStatusCode()) {
                        $this->logger->error(
                            sprintf(
                                '%s: Request to \'%s\' responded with unexpected http status code \'%d\'.',
                                $this->name,
                                $cName,
                                $response->getStatusCode()
                            )
                        );
                        return;
                    }

                    try {
                        $this->logger->info(sprintf('%s: Parsing \'%s\' response.', $this->name, $cName));

                        $it = Items::fromIterable(
                            httpClientChunks($this->http->stream($response)),
                            [
                                'pointer' => '/Items',
                                'decoder' => new ErrorWrappingDecoder(
                                    new ExtJsonDecoder(options: JSON_INVALID_UTF8_IGNORE)
                                )
                            ]
                        );

                        foreach ($it as $entity) {
                            if ($entity instanceof DecodingError) {
                                $this->logger->error(
                                    sprintf(
                                        '%s: Failed to decode one of \'%s\' items. %s',
                                        $this->name,
                                        $cName,
                                        $entity->getErrorMessage()
                                    ),
                                    [
                                        'payload' => $entity->getMalformedJson(),
                                    ]
                                );
                                continue;
                            }
                            $this->processImport($mapper, $type, $cName, $entity, $after);
                        }
                    } catch (PathNotFoundException $e) {
                        $this->logger->error(
                            sprintf(
                                '%s: Failed to find items in \'%s\' response. %s',
                                $this->name,
                                $cName,
                                $e->getMessage()
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                            ],
                        );
                    } catch (Throwable $e) {
                        $this->logger->error(
                            sprintf(
                                '%s: Failed to handle \'%s\' response. %s',
                                $this->name,
                                $cName,
                                $e->getMessage(),
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                            ],
                        );
                    }

                    $this->logger->info(sprintf('%s: Parsing \'%s\' response complete.', $this->name, $cName));
                };
            },
            error: function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('%s: Error encountered in \'%s\' request. %s', $this->name, $cName, $e->getMessage()),
                    [
                        'url' => $url,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                );
            },
            includeParent: true
        );
    }

    public function push(array $entities, DateTimeInterface|null $after = null): array
    {
        $this->checkConfig(true);

        $requests = $stateRequests = [];

        foreach ($entities as $entity) {
            if (null === $entity) {
                continue;
            }

            if (false === (ag($this->options, ServerInterface::OPT_EXPORT_IGNORE_DATE, false))) {
                if (null !== $after && $after->getTimestamp() > $entity->updated) {
                    continue;
                }
            }

            $entity->jf_id = null;

            foreach ([...$entity->getRelativePointers(), ...$entity->getPointers()] as $guid) {
                if (null === ($this->cacheData[$guid] ?? null)) {
                    continue;
                }
                $entity->jf_id = $this->cacheData[$guid];
            }

            $iName = $entity->getName();

            if (null === $entity->jf_id) {
                $this->logger->notice(
                    sprintf('%s: Ignoring \'%s\'. Not found in cache.', $this->name, $iName),
                    [
                        'guids' => $entity->hasGuids() ? $entity->getGuids() : 'None',
                        'rGuids' => $entity->hasRelativeGuid() ? $entity->getRelativeGuids() : 'None',
                    ]
                );
                continue;
            }

            try {
                $url = $this->url->withPath(sprintf('/Users/%s/items', $this->user))->withQuery(
                    http_build_query(
                        [
                            'ids' => $entity->jf_id,
                            'Fields' => 'ProviderIds,DateCreated,OriginalTitle,SeasonUserData,DateLastSaved',
                            'enableUserData' => 'true',
                            'enableImages' => 'false',
                        ]
                    )
                );

                $this->logger->debug(sprintf('%s: Requesting \'%s\' state from remote server.', $this->name, $iName), [
                    'url' => $url
                ]);

                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'state' => &$entity,
                        ]
                    ])
                );
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                ]);
            }
        }

        foreach ($requests as $response) {
            try {
                $json = json_decode(
                    json:        $response->getContent(),
                    associative: true,
                    flags:       JSON_THROW_ON_ERROR
                );

                $json = ag($json, 'Items', [])[0] ?? [];

                if (null === ($state = ag($response->getInfo('user_data'), 'state'))) {
                    $this->logger->error(
                        sprintf(
                            '%s: Request failed with code \'%d\'.',
                            $this->name,
                            $response->getStatusCode(),
                        ),
                        $response->getHeaders()
                    );
                    continue;
                }

                assert($state instanceof StateInterface);

                $iName = $state->getName();

                if (empty($json)) {
                    $this->logger->notice(
                        sprintf('%s: Ignoring \'%s\'. Remote server returned empty result.', $this->name, $iName)
                    );
                    continue;
                }

                $isWatched = (int)(bool)ag($json, 'UserData.Played', false);

                if ($state->watched === $isWatched) {
                    $this->logger->debug(sprintf('%s: Ignoring \'%s\'. Play state is identical.', $this->name, $iName));
                    continue;
                }

                if (false === (ag($this->options, ServerInterface::OPT_EXPORT_IGNORE_DATE, false))) {
                    $date = ag($json, ['UserData.LastPlayedDate', 'DateCreated', 'PremiereDate'], null);

                    if (null === $date) {
                        $this->logger->notice(
                            sprintf('%s: Ignoring \'%s\'. Date is not set on remote item.', $this->name, $iName),
                            [
                                'payload' => $json,
                            ]
                        );
                        continue;
                    }

                    $date = strtotime($date);

                    if ($date >= $state->updated) {
                        $this->logger->debug(
                            sprintf(
                                '%s: Ignoring \'%s\'. Remote item date is newer or equal to backend entity.',
                                $this->name,
                                $iName
                            ),
                            [
                                'backend' => makeDate($state->updated),
                                'remote' => makeDate($date),
                            ]
                        );
                        continue;
                    }
                }

                $url = $this->url->withPath(sprintf('/Users/%s/PlayedItems/%s', $this->user, ag($json, 'Id')));

                $this->logger->debug(
                    sprintf('%s: Changing \'%s\' remote state.', $this->name, $iName),
                    [
                        'backend' => $state->isWatched() ? 'Played' : 'Unplayed',
                        'remote' => $isWatched ? 'Played' : 'Unplayed',
                        'method' => $state->isWatched() ? 'POST' : 'DELETE',
                        'url' => (string)$url,
                    ]
                );

                $stateRequests[] = $this->http->request(
                    $state->isWatched() ? 'POST' : 'DELETE',
                    (string)$url,
                    array_replace_recursive(
                        $this->getHeaders(),
                        [
                            'user_data' => [
                                'itemName' => $iName,
                                'server' => $this->name,
                                'state' => $state->isWatched() ? 'Watched' : 'Unwatched',
                            ],
                        ]
                    )
                );
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                ]);
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
                    if (200 !== $response->getStatusCode()) {
                        $this->logger->error(
                            sprintf(
                                '%s: Request for \'%s\' responded with unexpected http status code (%d).',
                                $this->name,
                                $cName,
                                $response->getStatusCode()
                            )
                        );
                        return;
                    }

                    try {
                        $this->logger->info(sprintf('%s: Parsing \'%s\' response.', $this->name, $cName));

                        $it = Items::fromIterable(
                            httpClientChunks($this->http->stream($response)),
                            [
                                'pointer' => '/Items',
                                'decoder' => new ErrorWrappingDecoder(
                                    new ExtJsonDecoder(options: JSON_INVALID_UTF8_IGNORE)
                                )
                            ]
                        );

                        foreach ($it as $entity) {
                            if ($entity instanceof DecodingError) {
                                $this->logger->notice(
                                    sprintf(
                                        '%s: Failed to decode one of \'%s\' items. %s',
                                        $this->name,
                                        $cName,
                                        $entity->getErrorMessage()
                                    ),
                                    [
                                        'payload' => $entity->getMalformedJson(),
                                    ]
                                );
                                continue;
                            }
                            $this->processExport($mapper, $type, $cName, $entity, $after);
                        }
                    } catch (PathNotFoundException $e) {
                        $this->logger->error(
                            sprintf(
                                '%s: Failed to find items in \'%s\' response. %s',
                                $this->name,
                                $cName,
                                $e->getMessage()
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                            ],
                        );
                    } catch (Throwable $e) {
                        $this->logger->error(
                            sprintf(
                                '%s: Failed to handle \'%s\' response. %s',
                                $this->name,
                                $cName,
                                $e->getMessage(),
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                            ],
                        );
                    }

                    $this->logger->info(sprintf('%s: Parsing \'%s\' response complete.', $this->name, $cName));
                };
            },
            error: function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('%s: Error encountered in \'%s\' request. %s', $this->name, $cName, $e->getMessage()),
                    [
                        'url' => $url,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
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
                    if (200 !== $response->getStatusCode()) {
                        $this->logger->error(
                            sprintf(
                                '%s: Request to \'%s\' responded with unexpected http status code \'%d\'.',
                                $this->name,
                                $cName,
                                $response->getStatusCode()
                            )
                        );
                        return;
                    }

                    try {
                        $this->logger->info(sprintf('%s: Parsing \'%s\' response.', $this->name, $cName));

                        $it = Items::fromIterable(
                            httpClientChunks($this->http->stream($response)),
                            [
                                'pointer' => '/Items',
                                'decoder' => new ErrorWrappingDecoder(
                                    new ExtJsonDecoder(options: JSON_INVALID_UTF8_IGNORE)
                                )
                            ]
                        );

                        foreach ($it as $entity) {
                            if ($entity instanceof DecodingError) {
                                $this->logger->debug(
                                    sprintf(
                                        '%s: Failed to decode one of \'%s\' items. %s',
                                        $this->name,
                                        $cName,
                                        $entity->getErrorMessage()
                                    ),
                                    [
                                        'payload' => $entity->getMalformedJson()
                                    ]
                                );
                                continue;
                            }
                            $this->processCache($entity, $type, $cName);
                        }
                    } catch (PathNotFoundException $e) {
                        $this->logger->error(
                            sprintf(
                                '%s: Failed to find items in \'%s\' response. %s',
                                $this->name,
                                $cName,
                                $e->getMessage()
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                            ],
                        );
                    } catch (Throwable $e) {
                        $this->logger->error(
                            sprintf(
                                '%s: Failed to handle \'%s\' response. %s',
                                $this->name,
                                $cName,
                                $e->getMessage(),
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                            ],
                        );
                    }

                    $this->logger->info(sprintf('%s: Parsing \'%s\' response complete.', $this->name, $cName));
                };
            },
            error: function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('%s: Error encountered in \'%s\' request. %s', $this->name, $cName, $e->getMessage()),
                    [
                        'url' => $url,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                );
            },
            includeParent: true
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public function __destruct()
    {
        if (!empty($this->cacheKey) && !empty($this->cacheData) && true === $this->initialized) {
            $this->cache->set($this->cacheKey, $this->cacheData, new DateInterval('P1Y'));
        }

        if (!empty($this->cacheShowKey) && !empty($this->cacheShow) && true === $this->initialized) {
            $this->cache->set($this->cacheShowKey, $this->cacheShow, new DateInterval('P7D'));
        }
    }

    protected function getHeaders(): array
    {
        $opts = [
            'headers' => [
                'Accept' => 'application/json',
                'X-MediaBrowser-Token' => $this->token,
            ],
        ];

        return array_replace_recursive($this->options['client'] ?? [], $opts);
    }

    protected function getLibraries(Closure $ok, Closure $error, bool $includeParent = false): array
    {
        $this->checkConfig(true);

        try {
            $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
                http_build_query(
                    [
                        'Recursive' => 'false',
                        'enableUserData' => 'false',
                        'enableImages' => 'false',
                    ]
                )
            );

            $this->logger->debug(sprintf('%s: Requesting list of server libraries.', $this->name), [
                'url' => (string)$url
            ]);

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                $this->logger->error(
                    sprintf(
                        '%s: Request to get list of server libraries responded with unexpected code \'%d\'.',
                        $this->name,
                        $response->getStatusCode()
                    )
                );
                Data::add($this->name, 'no_import_update', true);
                return [];
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            $listDirs = ag($json, 'Items', []);

            if (empty($listDirs)) {
                $this->logger->notice(
                    sprintf('%s: Request to get list of server libraries responded with empty list.', $this->name)
                );
                Data::add($this->name, 'no_import_update', true);
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error(
                sprintf('%s: Request to get server libraries failed. %s', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                ],
            );
            Data::add($this->name, 'no_import_update', true);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('%s: Unable to decode get server libraries JSON response. %s', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );
            Data::add($this->name, 'no_import_update', true);
            return [];
        }

        if (null !== ($ignoreIds = ag($this->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => (int)trim($v), explode(',', (string)$ignoreIds));
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
                            'ExcludeLocationTypes' => 'Virtual',
                        ]
                    )
                );

                $this->logger->debug(sprintf('%s: Requesting \'%s\' series external ids.', $this->name, $cName), [
                    'url' => $url
                ]);

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
                            '%s: Request for \'%s\' series external ids has failed. %s',
                            $this->name,
                            $cName,
                            $e->getMessage()
                        ),
                        [
                            'url' => $url,
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                        ]
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
                $this->logger->debug(sprintf('%s: Skipping \'%s\' library. Unsupported type.', $this->name, $title), [
                    'id' => $key,
                    'type' => $type,
                ]);
                continue;
            }

            $type = $type === 'movies' ? StateInterface::TYPE_MOVIE : StateInterface::TYPE_EPISODE;
            $cName = sprintf('(%s) - (%s:%s)', $title, $type, $key);

            if (null !== $ignoreIds && true === in_array($key, $ignoreIds)) {
                $ignored++;
                $this->logger->notice(sprintf('%s: Skipping \'%s\'. Ignored by user.', $this->name, $title), [
                    'id' => $key,
                    'type' => $type,
                ]);
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
                        'Fields' => 'ProviderIds,DateCreated,OriginalTitle,SeasonUserData,DateLastSaved,PremiereDate,ProductionYear',
                        'ExcludeLocationTypes' => 'Virtual',
                    ]
                )
            );

            $this->logger->debug(sprintf('%s: Requesting \'%s\' content.', $this->name, $cName), [
                'url' => $url
            ]);

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
                    sprintf('%s: Request for \'%s\' content has failed. %s', $this->name, $cName, $e->getMessage()),
                    [
                        'url' => $url,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                );
                continue;
            }
        }

        if (0 === count($promises)) {
            $this->logger->notice(sprintf('%s: No library requests were made.', $this->name), [
                'total' => count($listDirs),
                'ignored' => $ignored,
                'unsupported' => $unsupported,
            ]);
            Data::add($this->name, 'no_import_update', true);
            return [];
        }

        return $promises;
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
                    '%s - [%s (%d)]',
                    $library,
                    $item->Name ?? $item->OriginalTitle ?? '??',
                    $item->ProductionYear ?? 0000
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - [%s - (%sx%s)]',
                        $library,
                        $item->SeriesName ?? '??',
                        str_pad((string)($item->ParentIndexNumber ?? 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)($item->IndexNumber ?? 0), 3, '0', STR_PAD_LEFT),
                    )
                );
            }

            $date = $item->UserData?->LastPlayedDate ?? $item->DateCreated ?? $item->PremiereDate ?? null;

            if (null === $date) {
                $this->logger->debug(
                    sprintf('%s: Ignoring \'%s\'. Date is not set on remote item.', $this->name, $iName),
                    [
                        'payload' => $item,
                    ]
                );
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $entity = $this->createEntity($item, $type);

            if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
                if (true === Config::get('debug.import')) {
                    $name = Config::get('tmpDir') . '/debug/' . $this->name . '.' . $item->Id . '.json';

                    if (!file_exists($name)) {
                        file_put_contents(
                            $name,
                            json_encode(
                                $item,
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE
                            )
                        );
                    }
                }

                $providerIds = (array)($item->ProviderIds ?? []);

                $message = sprintf('%s: Ignoring \'%s\'. No valid/supported external ids.', $this->name, $iName);

                if (empty($providerIds)) {
                    $message .= ' Most likely unmatched item.';
                }

                $this->logger->info($message, ['guids' => !empty($providerIds) ? $providerIds : 'None']);

                Data::increment($this->name, $type . '_ignored_no_supported_guid');

                return;
            }

            $mapper->add($this->name, $this->name . ' - ' . $iName, $entity, ['after' => $after]);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
        }
    }

    protected function processCache(StdClass $item, string $type, string $library): void
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
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
        }
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
                    '%s - [%s (%d)]',
                    $library,
                    $item->Name ?? $item->OriginalTitle ?? '??',
                    $item->ProductionYear ?? 0000
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - [%s - (%dx%d)]',
                        $library,
                        $item->SeriesName ?? '??',
                        str_pad((string)($item->ParentIndexNumber ?? 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)($item->IndexNumber ?? 0), 3, '0', STR_PAD_LEFT),
                    )
                );
            }

            $date = $item->UserData?->LastPlayedDate ?? $item->DateCreated ?? $item->PremiereDate ?? null;

            if (null === $date) {
                $this->logger->notice(
                    sprintf('%s: Ignoring \'%s\'. Date is not set on remote item.', $this->name, $iName),
                    [
                        'payload' => get_object_vars($item),
                    ]
                );
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $rItem = $this->createEntity($item, $type);

            if (!$rItem->hasGuids() && !$rItem->hasRelativeGuid()) {
                $providerIds = (array)($item->ProviderIds ?? []);

                $message = sprintf('%s: Ignoring \'%s\'. No valid/supported external ids.', $this->name, $iName);

                if (empty($providerIds)) {
                    $message .= ' Most likely unmatched item.';
                }

                $this->logger->debug($message, ['guids' => !empty($providerIds) ? $providerIds : 'None']);
                Data::increment($this->name, $type . '_ignored_no_supported_guid');
                return;
            }

            if (false === ag($this->options, ServerInterface::OPT_EXPORT_IGNORE_DATE, false)) {
                if (null !== $after && $rItem->updated >= $after->getTimestamp()) {
                    $this->logger->debug(
                        sprintf(
                            '%s: Ignoring \'%s\'. Remote item date is equal or newer than last sync date.',
                            $this->name,
                            $iName
                        )
                    );
                    Data::increment($this->name, $type . '_ignored_date_is_equal_or_higher');
                    return;
                }
            }

            if (null === ($entity = $mapper->get($rItem))) {
                $this->logger->debug(
                    sprintf(
                        '%s: Ignoring \'%s\' Not found in backend store. Run state:import to import the item.',
                        $this->name,
                        $iName,
                    ),
                    [
                        'played' => $rItem->isWatched() ? 'Yes' : 'No',
                        'guids' => $rItem->hasGuids() ? $rItem->getGuids() : 'None',
                        'rGuids' => $rItem->hasRelativeGuid() ? $rItem->getRelativeGuids() : 'None',
                    ]
                );
                Data::increment($this->name, $type . '_ignored_not_found_in_db');
                return;
            }

            if ($rItem->watched === $entity->watched) {
                $this->logger->debug(sprintf('%s: Ignoring \'%s\'. Played state is identical.', $this->name, $iName), [
                    'backend' => $entity->isWatched() ? 'Played' : 'Unplayed',
                    'remote' => $rItem->isWatched() ? 'Played' : 'Unplayed',
                ]);
                Data::increment($this->name, $type . '_ignored_state_unchanged');
                return;
            }

            if (false === ag($this->options, ServerInterface::OPT_EXPORT_IGNORE_DATE, false)) {
                if ($rItem->updated >= $entity->updated) {
                    $this->logger->debug(
                        sprintf('%s: Ignoring \'%s\'. Date is newer or equal to backend entity.', $this->name, $iName),
                        [
                            'backend' => makeDate($entity->updated),
                            'remote' => makeDate($rItem->updated),
                        ]
                    );
                    Data::increment($this->name, $type . '_ignored_date_is_newer');
                    return;
                }
            }

            $url = $this->url->withPath(sprintf('/Users/%s/PlayedItems/%s', $this->user, $item->Id));

            $this->logger->info(sprintf('%s: Queuing \'%s\'.', $this->name, $iName), [
                'backend' => $entity->isWatched() ? 'Played' : 'Unplayed',
                'remote' => $rItem->isWatched() ? 'Played' : 'Unplayed',
                'method' => $entity->isWatched() ? 'POST' : 'DELETE',
                'url' => $url,
            ]);

            $mapper->queue(
                $this->http->request(
                    $entity->isWatched() ? 'POST' : 'DELETE',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'itemName' => $iName,
                            'state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                        ],
                    ])
                )
            );
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
        }
    }

    protected function processShow(StdClass $item, string $library): void
    {
        $providersId = (array)($item->ProviderIds ?? []);

        if (!$this->hasSupportedIds($providersId)) {
            $iName = sprintf(
                '%s - [%s (%d)]',
                $library,
                $item->Name ?? $item->OriginalTitle ?? '??',
                $item->ProductionYear ?? 0000
            );

            $message = sprintf('%s: Ignoring \'%s\'. No valid/supported external ids.', $this->name, $iName);

            if (empty($providersId)) {
                $message .= ' Most likely unmatched TV show.';
            }

            $this->logger->info($message, ['guids' => empty($providersId) ? 'None' : $providersId]);

            return;
        }

        $cacheName = ag($item, ['Name', 'OriginalTitle'], '??') . ':' . ag($item, 'ProductionYear', 0000);
        $this->cacheShow[$item->Id] = Guid::fromArray($this->getGuids($providersId))->getAll();
        $this->cacheShow[$cacheName] = &$this->cacheShow[$item->Id];
    }

    protected function getGuids(array $ids): array
    {
        $guid = [];

        $ids = array_change_key_case($ids, CASE_LOWER);

        foreach ($ids as $key => $value) {
            if (null === (self::GUID_MAPPER[$key] ?? null) || empty($value)) {
                continue;
            }

            if (null !== ($guid[self::GUID_MAPPER[$key]] ?? null) && ctype_digit($value)) {
                if ((int)$guid[self::GUID_MAPPER[$key]] > (int)$value) {
                    continue;
                }
            }

            $guid[self::GUID_MAPPER[$key]] = $value;
        }

        ksort($guid);

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

    protected function checkConfig(bool $checkUrl = true, bool $checkToken = true, bool $checkUser = true): void
    {
        if (true === $checkUrl && !($this->url instanceof UriInterface)) {
            throw new RuntimeException(self::NAME . ': No host was set.');
        }

        if (true === $checkToken && null === $this->token) {
            throw new RuntimeException(self::NAME . ': No token was set.');
        }

        if (true === $checkUser && null === $this->user) {
            throw new RuntimeException(self::NAME . ': No User was set.');
        }
    }

    protected function createEntity(stdClass $item, string $type): StateEntity
    {
        $date = strtotime($item->UserData?->LastPlayedDate ?? $item->DateCreated ?? $item->PremiereDate);

        $row = [
            'type' => $type,
            'updated' => $date,
            'watched' => (int)(bool)($item->UserData?->Played ?? false),
            'via' => $this->name,
            'title' => $item->Name ?? $item->OriginalTitle ?? '??',
            'year' => $item->ProductionYear ?? 0000,
            'season' => null,
            'episode' => null,
            'parent' => [],
            'guids' => $this->getGuids((array)($item->ProviderIds ?? [])),
            'extra' => [
                'date' => makeDate($item->PremiereDate ?? $item->ProductionYear ?? 'now')->format('Y-m-d'),
            ],
        ];

        if (StateInterface::TYPE_EPISODE === $type) {
            $row['title'] = $item->SeriesName ?? '??';
            $row['season'] = $item->ParentIndexNumber ?? 0;
            $row['episode'] = $item->IndexNumber ?? 0;

            if (null !== ($item->Name ?? null)) {
                $row['extra']['title'] = $item->Name;
            }

            if (null !== ($item->SeriesId ?? null)) {
                $row['parent'] = $this->showInfo[$item->SeriesId] ?? [];
            }
        }

        $entity = Container::get(StateInterface::class)::fromArray($row);

        foreach ([...$entity->getRelativePointers(), ...$entity->getPointers()] as $guid) {
            $this->cacheData[$guid] = $item->Id;
        }

        return $entity;
    }

    protected function getEpisodeParent(mixed $id, string $cacheName): array
    {
        if (array_key_exists($cacheName, $this->cacheShow)) {
            return $this->cacheShow[$cacheName];
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

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            $providersId = (array)ag($json, 'ProviderIds', []);

            if (!$this->hasSupportedIds($providersId)) {
                $this->cacheShow[$cacheName] = $this->cacheShow[$seriesId] = [];
                return $this->cacheShow[$cacheName];
            }

            $this->cacheShow[$seriesId] = Guid::fromArray($this->getGuids($providersId))->getAll();
            $this->cacheShow[$cacheName] = &$this->cacheShow[$seriesId];

            return $this->cacheShow[$seriesId];
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('%s: Unable to decode \'%s\' JSON response. %s', $this->name, $cacheName, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );
            return [];
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('%s: Failed to handle \'%s\' response. %s', $this->name, $cacheName, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                ]
            );
            return [];
        }
    }
}
