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
use App\Libs\Options;
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
use stdClass;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

class PlexServer implements ServerInterface
{
    public const NAME = 'PlexBackend';

    protected const GUID_MAPPER = [
        'plex' => Guid::GUID_PLEX,
        'imdb' => Guid::GUID_IMDB,
        'tmdb' => Guid::GUID_TMDB,
        'tvdb' => Guid::GUID_TVDB,
        'tvmaze' => Guid::GUID_TVMAZE,
        'tvrage' => Guid::GUID_TVRAGE,
        'anidb' => Guid::GUID_ANIDB,
    ];

    protected const SUPPORTED_LEGACY_AGENTS = [
        'com.plexapp.agents.imdb',
        'com.plexapp.agents.tmdb',
        'com.plexapp.agents.themoviedb',
        'com.plexapp.agents.xbmcnfo',
        'com.plexapp.agents.xbmcnfotv',
        'com.plexapp.agents.thetvdb',
        'com.plexapp.agents.hama',
    ];

    protected const GUID_AGENT_REPLACER = [
        'com.plexapp.agents.themoviedb://' => 'com.plexapp.agents.tmdb://',
        'com.plexapp.agents.xbmcnfo://' => 'com.plexapp.agents.imdb://',
        'com.plexapp.agents.thetvdb://' => 'com.plexapp.agents.tvdb://',
        'com.plexapp.agents.xbmcnfotv://' => 'com.plexapp.agents.tvdb://',
    ];

    protected const WEBHOOK_ALLOWED_TYPES = [
        'movie',
        'episode',
    ];

    protected const WEBHOOK_ALLOWED_EVENTS = [
        'library.new',
        'library.on.deck',
        'media.play',
        'media.stop',
        'media.resume',
        'media.pause',
        'media.scrobble',
    ];

    protected const WEBHOOK_TAINTED_EVENTS = [
        'media.play',
        'media.stop',
        'media.resume',
        'media.pause',
    ];

    protected bool $initialized = false;
    protected UriInterface|null $url = null;
    protected string|null $token = null;
    protected array $options = [];
    protected string $name = '';
    protected array $persist = [];
    protected string $cacheKey = '';
    protected array $cacheData = [];

    protected string $cacheShowKey = '';
    protected array $cacheShow = [];

    protected string|int|null $uuid = null;
    protected string|int|null $user = null;


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
        $cloned = clone $this;

        $cloned->cacheData = [];
        $cloned->cacheShow = [];
        $cloned->name = $name;
        $cloned->url = $url;
        $cloned->token = $token;
        $cloned->user = $userId;
        $cloned->uuid = $uuid;
        $cloned->options = $options;
        $cloned->persist = $persist;
        $cloned->cacheKey = $options['cache_key'] ?? md5(__CLASS__ . '.' . $name . $url);
        $cloned->cacheShowKey = $cloned->cacheKey . '_show';
        $cloned->initialized = true;

        if ($cloned->cache->has($cloned->cacheKey)) {
            $cloned->cacheData = $cloned->cache->get($cloned->cacheKey);
        }

        if ($cloned->cache->has($cloned->cacheShowKey)) {
            $cloned->cacheShow = $cloned->cache->get($cloned->cacheShowKey);
        }

        return $cloned;
    }

    public function getServerUUID(bool $forceRefresh = false): int|string|null
    {
        if (false === $forceRefresh && null !== $this->uuid) {
            return $this->uuid;
        }

        $this->checkConfig();

        $url = $this->url->withPath('/');

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

        $json = json_decode(
            json:        $response->getContent(false),
            associative: true,
            flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        $this->uuid = ag($json, 'MediaContainer.machineIdentifier', null);

        return $this->uuid;
    }

    public function getUsersList(array $opts = []): array
    {
        $this->checkConfig(checkUrl: false);

        $url = Container::getNew(UriInterface::class)->withPort(443)->withScheme('https')->withHost('plex.tv')
            ->withPath('/api/v2/home/users/');

        $response = $this->http->request('GET', (string)$url, [
            'headers' => [
                'Accept' => 'application/json',
                'X-Plex-Token' => $this->token,
                'X-Plex-Client-Identifier' => $this->getServerUUID(),
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(
                sprintf(
                    '%s: Request to get users list responded with unexpected code \'%d\'.',
                    $this->name,
                    $response->getStatusCode()
                )
            );
        }

        $json = json_decode(
            json:        $response->getContent(),
            associative: true,
            flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        $list = [];

        $adminsCount = 0;

        $users = ag($json, 'users', []);

        foreach ($users as $user) {
            if (true === (bool)ag($user, 'admin')) {
                $adminsCount++;
            }
        }

        foreach ($users as $user) {
            $data = [
                'user_id' => ag($user, 'admin') && $adminsCount <= 1 ? 1 : ag($user, 'id'),
                'username' => $user['username'] ?? $user['title'] ?? $user['friendlyName'] ?? $user['email'] ?? '??',
                'is_admin' => ag($user, 'admin') ? 'Yes' : 'No',
                'is_guest' => ag($user, 'guest') ? 'Yes' : 'No',
                'is_restricted' => ag($user, 'restricted') ? 'Yes' : 'No',
                'updated_at' => isset($user['updatedAt']) ? makeDate($user['updatedAt']) : 'Never',
            ];

            if (true === ($opts['tokens'] ?? false)) {
                $data['token'] = $this->getUserToken($user['uuid']);
            }

            $list[] = $data;
        }

        unset($json, $users);

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

            if (false === str_starts_with($userAgent, 'PlexMediaServer/')) {
                return $request;
            }

            $payload = ag($request->getParsedBody() ?? [], 'payload', null);

            if (null === ($json = json_decode(json: $payload, associative: true, flags: JSON_INVALID_UTF8_IGNORE))) {
                return $request;
            }

            $request = $request->withParsedBody($json);

            $attributes = [
                'SERVER_ID' => ag($json, 'Server.uuid', ''),
                'SERVER_NAME' => ag($json, 'Server.title', ''),
                'SERVER_VERSION' => afterLast($userAgent, '/'),
                'USER_ID' => ag($json, 'Account.id', ''),
                'USER_NAME' => ag($json, 'Account.title', ''),
                'WH_EVENT' => ag($json, 'event', 'not_set'),
                'WH_TYPE' => ag($json, 'Metadata.type', 'not_set'),
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
            throw new HttpException(sprintf('%s: No payload.', self::NAME), 400);
        }

        $item = ag($json, 'Metadata', []);
        $type = ag($json, 'Metadata.type');
        $event = ag($json, 'event', null);

        if (null === $type || false === in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            throw new HttpException(sprintf('%s: Not allowed type [%s]', self::NAME, $type), 200);
        }

        if (null === $event || false === in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            throw new HttpException(sprintf('%s: Not allowed event [%s]', self::NAME, $event), 200);
        }

        if (null !== ($ignoreIds = ag($this->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', (string)$ignoreIds));
        }

        if (null !== $ignoreIds && in_array(ag($item, 'librarySectionID', '???'), $ignoreIds)) {
            throw new HttpException(
                sprintf(
                    '%s: Library id \'%s\' is ignored by user server config.',
                    self::NAME,
                    ag($item, 'librarySectionID', '???')
                ), 200
            );
        }

        $isTainted = in_array($event, self::WEBHOOK_TAINTED_EVENTS);

        if (null === ag($item, 'Guid', null)) {
            $item['Guid'] = [['id' => ag($item, 'guid')]];
        } else {
            $item['Guid'][] = ['id' => ag($item, 'guid')];
        }

        $row = [
            'type' => $type,
            'updated' => time(),
            'watched' => (int)(bool)ag($item, 'viewCount', false),
            'via' => $this->name,
            'title' => ag($item, ['title', 'originalTitle'], '??'),
            'year' => (int)ag($item, ['grandParentYear', 'parentYear', 'year'], 0000),
            'season' => null,
            'episode' => null,
            'parent' => [],
            'guids' => $this->getGuids(ag($item, 'Guid', [])),
            'extra' => [
                'date' => makeDate(ag($item, 'originallyAvailableAt', 'now'))->format('Y-m-d'),
                'webhook' => [
                    'event' => $event,
                ],
            ],
        ];

        if (StateInterface::TYPE_EPISODE === $type) {
            $row['title'] = ag($item, 'grandparentTitle', '??');
            $row['season'] = ag($item, 'parentIndex', 0);
            $row['episode'] = ag($item, 'index', 0);
            $row['extra']['title'] = ag($item, ['title', 'originalTitle'], '??');

            if (null !== ($parentId = ag($item, ['grandparentRatingKey', 'parentRatingKey'], null))) {
                $row['parent'] = $this->getEpisodeParent($parentId);
            }
        }

        $entity = Container::get(StateInterface::class)::fromArray($row)->setIsTainted($isTainted);

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $message = sprintf('%s: No valid/supported external ids.', self::NAME);

            if (empty($item['Guid'])) {
                $message .= sprintf(' Most likely unmatched %s.', $entity->type);
            }

            $message .= sprintf(' [%s].', arrayToString(['guids' => ag($item, 'Guid', 'None')]));

            throw new HttpException($message, 400);
        }

        foreach ([...$entity->getRelativePointers(), ...$entity->getPointers()] as $guid) {
            $this->cacheData[$guid] = ag($item, 'ratingKey');
        }

        $savePayload = true === Config::get('webhook.debug') || null !== ag($request->getQueryParams(), 'debug');

        if (false !== $isTainted && $savePayload) {
            saveWebhookPayload($this->name, $request, $entity);
        }

        return $entity;
    }

    public function search(string $query, int $limit = 25): array
    {
        $this->checkConfig();

        try {
            $url = $this->url->withPath('/hubs/search')->withQuery(
                http_build_query(
                    [
                        'query' => $query,
                        'limit' => $limit,
                        'includeGuids' => 1,
                        'includeExternalMedia' => 0,
                        'includeCollections' => 0,
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

            $list = [];

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            foreach (ag($json, 'MediaContainer.Hub', []) as $item) {
                $type = ag($item, 'type');

                if ('show' !== $type && 'movie' !== $type) {
                    continue;
                }

                foreach (ag($item, 'Metadata', []) as $subItem) {
                    $list[] = $subItem;
                }
            }

            return $list;
        } catch (ExceptionInterface|JsonException $e) {
            throw new RuntimeException(get_class($e) . ': ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function listLibraries(): array
    {
        $this->checkConfig();

        try {
            $url = $this->url->withPath('/library/sections');

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

            $listDirs = ag($json, 'MediaContainer.Directory', []);

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
            $key = (int)ag($section, 'key');
            $type = ag($section, 'type', 'unknown');

            $list[] = [
                'ID' => $key,
                'Title' => ag($section, 'title', '???'),
                'Type' => $type,
                'Ignored' => null !== $ignoreIds && in_array($key, $ignoreIds) ? 'Yes' : 'No',
                'Supported' => 'movie' !== $type && 'show' !== $type ? 'No' : 'Yes',
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
                                'pointer' => '/MediaContainer/Metadata',
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
        $this->checkConfig();

        $requests = $stateRequests = [];

        foreach ($entities as $key => $entity) {
            if (null === $entity) {
                continue;
            }

            if (false === (ag($this->options, Options::IGNORE_DATE, false))) {
                if (null !== $after && $after->getTimestamp() > $entity->updated) {
                    continue;
                }
            }

            $entity->plex_id = null;

            foreach ([...$entity->getRelativePointers(), ...$entity->getPointers()] as $guid) {
                if (null === ($this->cacheData[$guid] ?? null)) {
                    continue;
                }
                $entity->plex_id = $this->cacheData[$guid];
            }

            $iName = $entity->getName();

            if (null === $entity->plex_id) {
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
                $url = $this->url->withPath('/library/metadata/' . $entity->plex_id)->withQuery(
                    http_build_query(['includeGuids' => 1])
                );

                $this->logger->debug(sprintf('%s: Requesting \'%s\' state from remote server.', $this->name, $iName), [
                    'url' => $url
                ]);

                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'id' => $key,
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
                    flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                );

                $json = ag($json, 'MediaContainer.Metadata', [])[0] ?? [];

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

                $isWatched = (int)(bool)ag($json, 'viewCount', 0);

                if ($state->watched === $isWatched) {
                    $this->logger->debug(sprintf('%s: Ignoring \'%s\'. Play state is identical.', $this->name, $iName));
                    continue;
                }

                if (false === (ag($this->options, Options::IGNORE_DATE, false))) {
                    $date = max(
                        (int)ag($json, 'updatedAt', 0),
                        (int)ag($json, 'lastViewedAt', 0),
                        (int)ag($json, 'addedAt', 0)
                    );

                    if (0 === $date) {
                        $this->logger->notice(
                            sprintf('%s: Ignoring \'%s\'. Date is not set on remote item.', $this->name, $iName),
                            [
                                'payload' => $json,
                            ]
                        );
                        continue;
                    }

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

                $url = $this->url->withPath($state->isWatched() ? '/:/scrobble' : '/:/unscrobble')->withQuery(
                    http_build_query(
                        [
                            'identifier' => 'com.plexapp.plugins.library',
                            'key' => ag($json, 'ratingKey'),
                        ]
                    )
                );

                $this->logger->debug(
                    sprintf('%s: Changing \'%s\' remote state.', $this->name, $iName),
                    [
                        'backend' => $state->isWatched() ? 'Played' : 'Unplayed',
                        'remote' => $isWatched ? 'Played' : 'Unplayed',
                        'url' => (string)$url,
                    ]
                );

                $stateRequests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'itemName' => $iName,
                            'server' => $this->name,
                            'state' => $state->isWatched() ? 'Watched' : 'Unwatched',
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
                                'pointer' => '/MediaContainer/Metadata',
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
            includeParent: false
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
                                'pointer' => '/MediaContainer/Metadata',
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
                                        'payload' => $entity->getMalformedJson(),
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
                'X-Plex-Token' => $this->token,
            ],
        ];

        return array_replace_recursive($this->options['client'] ?? [], $opts);
    }

    protected function getLibraries(Closure $ok, Closure $error, bool $includeParent = false): array
    {
        $this->checkConfig();

        try {
            $url = $this->url->withPath('/library/sections');

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

            $listDirs = ag($json, 'MediaContainer.Directory', []);

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
                $key = (int)ag($section, 'key');
                $title = ag($section, 'title', '???');

                if ('show' !== ag($section, 'type', 'unknown')) {
                    continue;
                }

                $cName = sprintf('(%s) - (%s:%s)', $title, 'show', $key);

                if (null !== $ignoreIds && in_array($key, $ignoreIds)) {
                    continue;
                }

                $url = $this->url->withPath(sprintf('/library/sections/%d/all', $key))->withQuery(
                    http_build_query(
                        [
                            'type' => 2,
                            'sort' => 'addedAt:asc',
                            'includeGuids' => 1,
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
            $key = (int)ag($section, 'key');
            $type = ag($section, 'type', 'unknown');
            $title = ag($section, 'title', '???');

            if ('movie' !== $type && 'show' !== $type) {
                $unsupported++;
                $this->logger->debug(sprintf('%s: Skipping \'%s\' library. Unsupported type.', $this->name, $title), [
                    'id' => $key,
                    'type' => $type,
                ]);
                continue;
            }

            $type = $type === 'movie' ? StateInterface::TYPE_MOVIE : StateInterface::TYPE_EPISODE;

            if (null !== $ignoreIds && true === in_array($key, $ignoreIds)) {
                $ignored++;
                $this->logger->notice(sprintf('%s: Skipping \'%s\'. Ignored by user.', $this->name, $title), [
                    'id' => $key,
                    'type' => $type,
                ]);
                continue;
            }

            $cName = sprintf('(%s) - (%s:%s)', $title, $type, $key);

            $url = $this->url->withPath(sprintf('/library/sections/%d/all', $key))->withQuery(
                http_build_query(
                    [
                        'type' => 'movie' === $type ? 1 : 4,
                        'sort' => 'addedAt:asc',
                        'includeGuids' => 1,
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

            Data::increment($this->name, $library . '_total');
            Data::increment($this->name, $type . '_total');

            if (StateInterface::TYPE_MOVIE === $type) {
                $iName = sprintf(
                    '%s - [%s (%d)]',
                    $library,
                    $item->title ?? $item->originalTitle ?? '??',
                    $item->year ?? 0000
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - [%s - (%sx%s)]',
                        $library,
                        $item->grandparentTitle ?? $item->originalTitle ?? '??',
                        str_pad((string)($item->parentIndex ?? 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)($item->index ?? 0), 3, '0', STR_PAD_LEFT),
                    )
                );
            }

            if (true === (bool)ag($this->options, Options::DEEP_DEBUG)) {
                $this->logger->debug(sprintf('%s: Processing \'%s\' Payload.', $this->name, $iName), [
                    'payload' => (array)$item,
                ]);
            }

            $date = (int)($item->lastViewedAt ?? $item->updatedAt ?? $item->addedAt ?? 0);

            if (0 === $date) {
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
                    $name = Config::get('tmpDir') . '/debug/' . $this->name . '.' . $item->ratingKey . '.json';

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

                $message = sprintf('%s: Ignoring \'%s\'. No valid/supported external ids.', $this->name, $iName);

                if (empty($item->Guid)) {
                    $message .= sprintf(' Most likely unmatched %s.', $entity->type);
                }

                if (null === ($item->Guid ?? null)) {
                    $item->Guid = [['id' => $item->guid]];
                } else {
                    $item->Guid[] = ['id' => $item->guid];
                }

                $this->logger->info($message, ['guids' => !empty($item->Guid) ? $item->Guid : 'None']);

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

            $date = (int)($item->lastViewedAt ?? $item->updatedAt ?? $item->addedAt ?? 0);

            if (0 === $date) {
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
        try {
            Data::increment($this->name, $type . '_total');

            if (StateInterface::TYPE_MOVIE === $type) {
                $iName = sprintf(
                    '%s - [%s (%d)]',
                    $library,
                    $item->title ?? $item->originalTitle ?? '??',
                    $item->year ?? 0000
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - [%s - (%dx%d)]',
                        $library,
                        $item->grandparentTitle ?? $item->originalTitle ?? '??',
                        str_pad((string)($item->parentIndex ?? 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)($item->index ?? 0), 3, '0', STR_PAD_LEFT),
                    )
                );
            }

            $date = $item->lastViewedAt ?? $item->updatedAt ?? $item->addedAt ?? null;

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
                $message = sprintf('%s: Ignoring \'%s\'. No valid/supported external ids.', $this->name, $iName);

                if (empty($item->Guid)) {
                    $message .= sprintf(' Most likely unmatched %s.', $rItem->type);
                }

                if (null === ($item->Guid ?? null)) {
                    $item->Guid = [['id' => $item->guid]];
                } else {
                    $item->Guid[] = ['id' => $item->guid];
                }

                $this->logger->debug($message, ['guids' => !empty($item->Guid) ? $item->Guid : 'None']);

                Data::increment($this->name, $type . '_ignored_no_supported_guid');
                return;
            }

            if (false === ag($this->options, Options::IGNORE_DATE, false)) {
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

            if (false === ag($this->options, Options::IGNORE_DATE, false)) {
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

            $url = $this->url->withPath('/:' . ($entity->isWatched() ? '/scrobble' : '/unscrobble'))->withQuery(
                http_build_query(
                    [
                        'identifier' => 'com.plexapp.plugins.library',
                        'key' => $item->ratingKey,
                    ]
                )
            );

            $this->logger->info(sprintf('%s: Queuing \'%s\'.', $this->name, $iName), [
                'backend' => $entity->isWatched() ? 'Played' : 'Unplayed',
                'remote' => $rItem->isWatched() ? 'Played' : 'Unplayed',
                'url' => $url,
            ]);

            $mapper->queue(
                $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'itemName' => $iName,
                            'state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                        ]
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
        if (null === ($item->Guid ?? null)) {
            $item->Guid = [['id' => $item->guid]];
        } else {
            $item->Guid[] = ['id' => $item->guid];
        }

        $iName = sprintf(
            '%s - [%s (%d)]',
            $library,
            ag($item, ['title', 'originalTitle'], '??'),
            ag($item, 'year', '0000')
        );

        if (true === (bool)ag($this->options, Options::DEEP_DEBUG)) {
            $this->logger->debug(sprintf('%s: Processing \'%s\' Payload.', $this->name, $iName), [
                'payload' => (array)$item,
            ]);
        }

        if (!$this->hasSupportedGuids(guids: $item->Guid)) {
            if (null === ($item->Guid ?? null)) {
                $item->Guid = [['id' => $item->guid]];
            } else {
                $item->Guid[] = ['id' => $item->guid];
            }

            $message = sprintf('%s: Ignoring \'%s\'. No valid/supported external ids.', $this->name, $iName);

            if (empty($item->Guid)) {
                $message .= ' Most likely unmatched TV show.';
            }

            $this->logger->info($message, ['guids' => !empty($item->Guid) ? $item->Guid : 'None']);

            return;
        }

        $this->cacheShow[$item->ratingKey] = Guid::fromArray($this->getGuids($item->Guid))->getAll();
    }

    protected function getGuids(array $guids): array
    {
        $guid = [];

        foreach ($guids as $_id) {
            $val = is_object($_id) ? $_id->id : $_id['id'];

            if (empty($val)) {
                continue;
            }

            if (true === str_starts_with($val, 'com.plexapp.agents.')) {
                // -- DO NOT accept plex relative unique ids, we generate our own.
                if (substr_count($val, '/') >= 3) {
                    if (true === (bool)ag($this->options, Options::DEEP_DEBUG)) {
                        $this->logger->debug(sprintf('%s: Parsing \'%s\' is not supported.', $this->name, $val));
                    }
                    continue;
                }
                $val = $this->parseLegacyAgent($val);
            }

            [$key, $value] = explode('://', $val);
            $key = strtolower($key);

            if (null === (self::GUID_MAPPER[$key] ?? null) || empty($value)) {
                continue;
            }

            if (null !== ($guid[self::GUID_MAPPER[$key]] ?? null) && ctype_digit($val)) {
                if ((int)$guid[self::GUID_MAPPER[$key]] > (int)$val) {
                    continue;
                }
            }

            $guid[self::GUID_MAPPER[$key]] = $value;
        }

        ksort($guid);

        return $guid;
    }

    protected function hasSupportedGuids(array $guids): bool
    {
        foreach ($guids as $_id) {
            $val = is_object($_id) ? $_id->id : $_id['id'];

            if (empty($val)) {
                continue;
            }

            if (true === str_starts_with($val, 'com.plexapp.agents.')) {
                // -- DO NOT accept plex relative unique ids, we generate our own.
                if (substr_count($val, '/') >= 3) {
                    if (true === (bool)ag($this->options, Options::DEEP_DEBUG)) {
                        $this->logger->debug(sprintf('%s: Parsing \'%s\' is not supported.', $this->name, $val));
                    }
                    continue;
                }
                $val = $this->parseLegacyAgent($val);
            }

            [$key, $value] = explode('://', $val);
            $key = strtolower($key);

            if (null !== (self::GUID_MAPPER[$key] ?? null) && !empty($value)) {
                return true;
            }
        }

        return false;
    }

    protected function checkConfig(bool $checkUrl = true, bool $checkToken = true): void
    {
        if (true === $checkUrl && !($this->url instanceof UriInterface)) {
            throw new RuntimeException(self::NAME . ': No host was set.');
        }

        if (true === $checkToken && null === $this->token) {
            throw new RuntimeException(self::NAME . ': No token was set.');
        }
    }

    protected function createEntity(StdClass $item, string $type): StateEntity
    {
        if (null === ($item->Guid ?? null)) {
            $item->Guid = [['id' => $item->guid]];
        } else {
            $item->Guid[] = ['id' => $item->guid];
        }

        $date = (int)($item->lastViewedAt ?? $item->updatedAt ?? $item->addedAt ?? 0);

        $row = [
            'type' => $type,
            'updated' => $date,
            'watched' => (int)(bool)($item->viewCount ?? false),
            'via' => $this->name,
            'title' => $item->title ?? $item->originalTitle ?? '??',
            'year' => (int)($item->grandParentYear ?? $item->parentYear ?? $item->year ?? 0000),
            'season' => null,
            'episode' => null,
            'parent' => [],
            'guids' => $this->getGuids($item->Guid ?? []),
            'extra' => [
                'date' => makeDate($item->originallyAvailableAt ?? 'now')->format('Y-m-d'),
            ],
        ];

        if (StateInterface::TYPE_EPISODE === $type) {
            $row['title'] = $item->grandparentTitle ?? '??';
            $row['season'] = $item->parentIndex ?? 0;
            $row['episode'] = $item->index ?? 0;
            $row['extra']['title'] = $item->title ?? $item->originalTitle ?? '??';

            $parentId = $item->grandparentRatingKey ?? $item->parentRatingKey ?? null;

            if (null !== $parentId) {
                $row['parent'] = $this->getEpisodeParent($parentId);
            }
        }

        $entity = Container::get(StateInterface::class)::fromArray($row);

        foreach ([...$entity->getRelativePointers(), ...$entity->getPointers()] as $guid) {
            $this->cacheData[$guid] = $item->ratingKey;
        }

        return $entity;
    }

    protected function getEpisodeParent(int|string $id): array
    {
        if (array_key_exists($id, $this->cacheShow)) {
            return $this->cacheShow[$id];
        }

        try {
            $response = $this->http->request(
                'GET',
                (string)$this->url->withPath('/library/metadata/' . $id),
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

            $json = ag($json, 'MediaContainer.Metadata')[0] ?? [];

            if (null === ($type = ag($json, 'type')) || 'show' !== $type) {
                return [];
            }

            if (null === ($json['Guid'] ?? null)) {
                $json['Guid'] = [['id' => $json['guid']]];
            } else {
                $json['Guid'][] = ['id' => $json['guid']];
            }

            if (!$this->hasSupportedGuids(guids: $json['Guid'])) {
                $this->cacheShow[$id] = [];
                return $this->cacheShow[$id];
            }

            $this->cacheShow[$id] = Guid::fromArray($this->getGuids($json['Guid']))->getAll();

            return $this->cacheShow[$id];
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('%s: Unable to decode show id \'%s\' JSON response. %s', $this->name, $id, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );
            return [];
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('%s: Failed to handle show id \'%s\' response. %s', $this->name, $id, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                ]
            );
            return [];
        }
    }

    protected function parseLegacyAgent(string $agent): string
    {
        try {
            if (false === in_array(before($agent, '://'), self::SUPPORTED_LEGACY_AGENTS)) {
                return $agent;
            }

            if (true === str_starts_with($agent, 'com.plexapp.agents.hama')) {
                $agentGuid = explode('-', after($agent, '://'));
            } else {
                $agent = str_replace(
                    array_keys(self::GUID_AGENT_REPLACER),
                    array_values(self::GUID_AGENT_REPLACER),
                    $agent
                );
                $agentGuid = explode('://', after($agent, 'agents.'));
            }

            return $agentGuid[0] . '://' . before($agentGuid[1], '?');
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('%s: Error parsing Plex legacy agent identifier. %s', $this->name, $e->getMessage()),
                [
                    'guid' => $agent,
                ]
            );
            return $agent;
        }
    }

    protected function getUserToken(int|string $userId): int|string|null
    {
        try {
            $uuid = $this->getServerUUID();

            $url = Container::getNew(UriInterface::class)->withPort(443)->withScheme('https')->withHost(
                'plex.tv'
            )->withPath(sprintf('/api/v2/home/users/%s/switch', $userId));

            $this->logger->debug(sprintf('%s: Requesting temp token for user id \'%s\'.', $this->name, $userId), [
                'url' => (string)$url
            ]);

            $response = $this->http->request('POST', (string)$url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Plex-Token' => $this->token,
                    'X-Plex-Client-Identifier' => $uuid,
                ],
            ]);

            if (201 !== $response->getStatusCode()) {
                $this->logger->error(
                    sprintf(
                        '%s: Request to get temp token for userid \'%s\' responded with unexpected http status code \'%d\'.',
                        $this->name,
                        $userId,
                        $response->getStatusCode()
                    )
                );

                return null;
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            $tempToken = ag($json, 'authToken', null);

            $url = Container::getNew(UriInterface::class)->withPort(443)->withScheme('https')->withHost('plex.tv')
                ->withPath('/api/v2/resources')->withQuery(
                    http_build_query(
                        [
                            'includeIPv6' => 1,
                            'includeHttps' => 1,
                            'includeRelay' => 1
                        ]
                    )
                );

            $this->logger->debug(
                sprintf('%s: Requesting real server token for user id \'%s\'.', $this->name, $userId),
                [
                    'url' => (string)$url
                ]
            );

            $response = $this->http->request('GET', (string)$url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Plex-Token' => $tempToken,
                    'X-Plex-Client-Identifier' => $uuid,
                ],
            ]);

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            foreach ($json ?? [] as $server) {
                if (ag($server, 'clientIdentifier') !== $uuid) {
                    continue;
                }
                return ag($server, 'accessToken');
            }

            return null;
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
            return null;
        }
    }
}
