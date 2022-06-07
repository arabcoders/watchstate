<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use App\Libs\HttpException;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Options;
use App\Libs\QueueRequests;
use Closure;
use DateInterval;
use DateTimeInterface;
use Generator;
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
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

class PlexServer implements ServerInterface
{
    public const NAME = 'PlexBackend';

    protected const GUID_MAPPER = [
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

    protected const GUID_PLEX_LOCAL = [
        'plex://',
        'local://',
        'com.plexapp.agents.none://',
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

    /**
     * Parse hama agent guid.
     */
    private const HAMA_REGEX = '/(?P<source>(anidb|tvdb|tmdb|tsdb|imdb))\d?-(?P<id>[^\[\]]*)/';

    protected bool $initialized = false;
    protected UriInterface|null $url = null;
    protected string|null $token = null;
    protected array $options = [];
    protected string $name = '';
    protected array $persist = [];
    protected string $cacheKey = '';
    protected array $cache = [];

    protected string|int|null $uuid = null;
    protected string|int|null $user = null;

    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
        protected CacheInterface $cacheIO
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

        $cloned->name = $name;
        $cloned->url = $url;
        $cloned->token = $token;
        $cloned->user = $userId;
        $cloned->uuid = $uuid;
        $cloned->options = $options;
        $cloned->persist = $persist;
        $cloned->initialized = true;

        $cloned->cache = [];
        $cloned->cacheKey = $cloned::NAME . '_' . $name;

        if ($cloned->cacheIO->has($cloned->cacheKey)) {
            $cloned->cache = $cloned->cacheIO->get($cloned->cacheKey);
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

        $this->logger->debug('Requesting [%(backend)] unique identifier.', [
            'backend' => $this->getName(),
            'url' => $url,
        ]);

        $response = $this->http->request('GET', (string)$url, $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            $this->logger->error('Request for [%(backend)] unique identifier responded with unexpected status code.', [
                'backend' => $this->getName(),
                'condition' => [
                    'expected' => 200,
                    'received' => $response->getStatusCode(),
                ],
            ]);

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
                    'Request for [%s] users list responded with unexpected [%s] status code.',
                    $this->name,
                    $response->getStatusCode(),
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
                'id' => ag($user, 'admin') && $adminsCount <= 1 ? 1 : ag($user, 'id'),
                'name' => $user['username'] ?? $user['title'] ?? $user['friendlyName'] ?? $user['email'] ?? '??',
                'admin' => (bool)ag($user, 'admin'),
                'guest' => (bool)ag($user, 'guest'),
                'restricted' => (bool)ag($user, 'restricted'),
                'updatedAt' => isset($user['updatedAt']) ? makeDate($user['updatedAt']) : 'Never',
            ];

            if (true === ($opts['tokens'] ?? false)) {
                $data['token'] = $this->getUserToken($user['uuid']);
            }

            if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                $data['raw'] = $user;
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

    public function getName(): string
    {
        return $this->name ?? self::NAME;
    }

    public function processRequest(ServerRequestInterface $request, array $opts = []): ServerRequestInterface
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
                'ITEM_ID' => ag($json, 'Metadata.ratingKey', ''),
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
            $logger?->error('Unhandled exception was thrown in [%(client)] during request processing.', [
                'client' => self::NAME,
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'message' => $e->getMessage(),
                ],
            ]);
        }

        return $request;
    }

    public function parseWebhook(ServerRequestInterface $request): iFace
    {
        if (null === ($json = $request->getParsedBody())) {
            throw new HttpException(sprintf('%s: No payload.', self::NAME), 400);
        }

        $item = ag($json, 'Metadata', []);
        $type = ag($json, 'Metadata.type');
        $event = ag($json, 'event', null);
        $id = ag($item, 'ratingKey');

        if (null === $type || false === in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            throw new HttpException(
                sprintf('%s: Webhook content type [%s] is not supported.', $this->getName(), $type), 200
            );
        }

        if (null === $event || false === in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            throw new HttpException(
                sprintf('%s: Webhook event type [%s] is not supported.', $this->getName(), $event), 200
            );
        }

        if (null === $id) {
            throw new HttpException(sprintf('%s: No item id was found in webhook body.', $this->getName()), 400);
        }

        if (null !== ($ignoreIds = ag($this->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', (string)$ignoreIds));
        }

        if (null !== $ignoreIds && in_array(ag($item, 'librarySectionID', '???'), $ignoreIds)) {
            throw new HttpException(sprintf('%s: library is ignored by user choice.', $this->getName()), 200);
        }

        try {
            $isPlayed = (bool)ag($item, 'viewCount', false);
            $lastPlayedAt = true === $isPlayed ? ag($item, 'lastViewedAt') : null;

            $fields = [
                iFace::COLUMN_WATCHED => (int)$isPlayed,
                iFace::COLUMN_META_DATA => [
                    $this->name => [
                        iFace::COLUMN_WATCHED => true === $isPlayed ? '1' : '0',
                    ]
                ],
                iFace::COLUMN_EXTRA => [
                    $this->name => [
                        iFace::COLUMN_EXTRA_EVENT => $event,
                        iFace::COLUMN_EXTRA_DATE => makeDate('now'),
                    ],
                ],
            ];

            if (true === $isPlayed && null !== $lastPlayedAt) {
                $fields = array_replace_recursive($fields, [
                    iFace::COLUMN_UPDATED => (int)$lastPlayedAt,
                    iFace::COLUMN_META_DATA => [
                        $this->name => [
                            iFace::COLUMN_META_DATA_PLAYED_AT => (string)$lastPlayedAt,
                        ]
                    ],
                ]);
            }

            if (null !== ($guids = $this->getGuids(ag($item, 'Guid', []))) && false === empty($guids)) {
                $guids += Guid::makeVirtualGuid($this->name, (string)$id);
                $fields[iFace::COLUMN_GUIDS] = $guids;
                $fields[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_GUIDS] = $fields[iFace::COLUMN_GUIDS];
            }

            $entity = $this->createEntity(
                item: ag($this->getMetadata(id: $id), 'MediaContainer.Metadata.0', []),
                type: $type,
                opts: ['override' => $fields],
            )->setIsTainted(isTainted: true === in_array($event, self::WEBHOOK_TAINTED_EVENTS));
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception was thrown during [%(backend)] webhook event parsing.', [
                'backend' => $this->getName(),
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'message' => $e->getMessage(),
                ],
                'context' => [
                    'attributes' => $request->getAttributes(),
                    'payload' => $request->getParsedBody(),
                ],
            ]);

            throw new HttpException(
                sprintf('%s: Failed to handle webhook payload check logs.', $this->getName()), 200
            );
        }

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $this->logger->error('Ignoring [%(backend)] [%(title)] webhook event. No valid/supported external ids.', [
                'backend' => $id,
                'title' => $entity->getName(),
                'context' => [
                    'attributes' => $request->getAttributes(),
                    'parsed' => $entity->getAll(),
                    'payload' => $request->getParsedBody(),
                ],
            ]);

            throw new HttpException(
                sprintf('%s: Import ignored. No valid/supported external ids.', $this->getName()),
                200
            );
        }

        return $entity;
    }

    public function search(string $query, int $limit = 25, array $opts = []): array
    {
        $this->checkConfig();

        try {
            $url = $this->url->withPath('/hubs/search')->withQuery(
                http_build_query(
                    array_replace_recursive(
                        [
                            'query' => $query,
                            'limit' => $limit,
                            'includeGuids' => 1,
                            'includeExternalMedia' => 0,
                            'includeCollections' => 0,
                        ],
                        $opts['query'] ?? []
                    )
                )
            );

            $this->logger->debug('Searching for [%(query)] in [%(backend)].', [
                'backend' => $this->name,
                'query' => $query,
                'url' => $url
            ]);

            $response = $this->http->request(
                'GET',
                (string)$url,
                array_replace_recursive($this->getHeaders(), $opts['headers'] ?? [])
            );

            if (200 !== $response->getStatusCode()) {
                throw new RuntimeException(
                    sprintf(
                        'Search request for [%s] in [%s] responded with unexpected [%s] status code.',
                        $query,
                        $this->getName(),
                        $response->getStatusCode(),
                    )
                );
            }

            $list = [];

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            foreach (ag($json, 'MediaContainer.Hub', []) as $leaf) {
                $type = ag($leaf, 'type');

                if ('show' !== $type && 'movie' !== $type && 'episode' !== $type) {
                    continue;
                }

                foreach (ag($leaf, 'Metadata', []) as $item) {
                    $watchedAt = ag($item, 'lastViewedAt');
                    $year = (int)ag($item, 'year', 0);

                    if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
                        $year = (int)makeDate($airDate)->format('Y');
                    }

                    $episodeNumber = ('episode' === $type) ? sprintf(
                        '%sx%s - ',
                        str_pad((string)(ag($item, 'parentIndex', 0)), 2, '0', STR_PAD_LEFT),
                        str_pad((string)(ag($item, 'index', 0)), 3, '0', STR_PAD_LEFT),
                    ) : null;

                    $builder = [
                        'id' => (int)ag($item, 'ratingKey'),
                        'type' => ucfirst(ag($item, 'type', '??')),
                        'library' => ag($item, 'librarySectionTitle', '??'),
                        'title' => $episodeNumber . mb_substr(ag($item, ['title', 'originalTitle'], '??'), 0, 50),
                        'year' => $year,
                        'addedAt' => makeDate(ag($item, 'addedAt'))->format('Y-m-d H:i:s T'),
                        'watchedAt' => null !== $watchedAt ? makeDate($watchedAt)->format('Y-m-d H:i:s T') : 'Never',
                    ];

                    if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                        $builder['raw'] = $item;
                    }

                    $list[] = $builder;
                }
            }

            return $list;
        } catch (ExceptionInterface|JsonException $e) {
            throw new RuntimeException(sprintf('%s: %s', $this->getName(), $e->getMessage()), previous: $e);
        }
    }

    public function searchId(string|int $id, array $opts = []): array
    {
        $item = $this->getMetadata($id, $opts);

        $metadata = ag($item, 'MediaContainer.Metadata.0', []);

        $type = ag($metadata, 'type');

        $watchedAt = ag($metadata, 'lastViewedAt');
        $year = (int)ag($metadata, ['year', 'parentYear', 'grandparentYear'], 0);

        if (0 === $year && null !== ($airDate = ag($metadata, 'originallyAvailableAt'))) {
            $year = (int)makeDate($airDate)->format('Y');
        }

        $episodeNumber = ('episode' === $type) ? sprintf(
            '%sx%s - ',
            str_pad((string)(ag($metadata, 'parentIndex', 0)), 2, '0', STR_PAD_LEFT),
            str_pad((string)(ag($metadata, 'index', 0)), 3, '0', STR_PAD_LEFT),
        ) : null;

        $builder = [
            'id' => (int)ag($metadata, 'ratingKey'),
            'type' => ucfirst(ag($metadata, 'type', '??')),
            'library' => ag($metadata, 'librarySectionTitle', '??'),
            'title' => $episodeNumber . mb_substr(ag($metadata, ['title', 'originalTitle'], '??'), 0, 50),
            'year' => $year,
            'addedAt' => makeDate(ag($metadata, 'addedAt'))->format('Y-m-d H:i:s T'),
            'watchedAt' => null !== $watchedAt ? makeDate($watchedAt)->format('Y-m-d H:i:s T') : 'Never',
            'duration' => ag($metadata, 'duration') ? formatDuration(ag($metadata, 'duration')) : 'None',
        ];

        if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
            $builder['raw'] = $item;
            $builder['entity'] = $this->createEntity($metadata, $type);
        }

        return $builder;
    }

    public function getMetadata(string|int $id, array $opts = []): array
    {
        $this->checkConfig();

        try {
            $cacheKey = false === (bool)($opts['nocache'] ?? false) ? $this->getName() . '_' . $id . '_metadata' : null;

            if (null !== $cacheKey && $this->cacheIO->has($cacheKey)) {
                return $this->cacheIO->get(key: $cacheKey);
            }

            $url = $this->url->withPath('/library/metadata/' . $id)->withQuery(
                http_build_query(array_merge_recursive(['includeGuids' => 1], $opts['query'] ?? []))
            );

            $this->logger->debug('Requesting [%(backend)] item [%(id)] metadata.', [
                'backend' => $this->getName(),
                'id' => $id,
                'url' => $url
            ]);

            $response = $this->http->request(
                'GET',
                (string)$url,
                array_replace_recursive(
                    $this->getHeaders(),
                    $opts['headers'] ?? []
                )
            );

            if (200 !== $response->getStatusCode()) {
                throw new RuntimeException(
                    sprintf(
                        'Request for [%s] item [%s] responded with unexpected [%s] status code.',
                        $this->name,
                        $id,
                        $response->getStatusCode(),
                    )
                );
            }

            $item = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            if (null !== $cacheKey) {
                $this->cacheIO->set(key: $cacheKey, value: $item, ttl: new DateInterval('PT5M'));
            }

            return $item;
        } catch (ExceptionInterface|JsonException|InvalidArgumentException $e) {
            throw new RuntimeException(sprintf('%s: %s', $this->getName(), $e->getMessage()), previous: $e);
        }
    }

    /**
     * @throws Throwable
     */
    public function getLibrary(string|int $id, array $opts = []): Generator
    {
        $this->checkConfig();

        $url = $this->url->withPath('/library/sections/');

        $this->logger->debug('Requesting [%(backend)] libraries.', [
            'backend' => $this->name,
            'url' => $url
        ]);

        $response = $this->http->request('GET', (string)$url, $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(
                sprintf(
                    'Request for [%s] libraries responded with unexpected status code. %s',
                    $this->name,
                    arrayToString(
                        [
                            'condition' => [
                                'expected' => 200,
                                'received' => $response->getStatusCode(),
                            ],
                        ]
                    )
                )
            );
        }

        $json = json_decode(
            json:        $response->getContent(),
            associative: true,
            flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        $type = null;
        $found = false;

        foreach (ag($json, 'MediaContainer.Directory', []) as $section) {
            if ((int)ag($section, 'key') !== (int)$id) {
                continue;
            }
            $found = true;
            $type = ag($section, 'type', 'unknown');
            break;
        }

        if (false === $found) {
            throw new RuntimeException(
                sprintf('Response from [%s] does not contain library with id of [%s].', $this->name, $id)
            );
        }

        if ('movie' !== $type && 'show' !== $type) {
            throw new RuntimeException(
                sprintf('The requested [%s] library id [%s] is of unsupported type. [%s]', $this->name, $id, $type)
            );
        }

        $query = [
            'sort' => 'addedAt:asc',
            'includeGuids' => 1,
        ];

        if (iFace::TYPE_MOVIE === $type) {
            $query['type'] = 1;
        }

        $url = $this->url->withPath(sprintf('/library/sections/%d/all', $id))->withQuery(http_build_query($query));

        $this->logger->debug('Requesting [%(backend)] library id [%(id)] content.', [
            'id' => $id,
            'backend' => $this->name,
            'url' => $url
        ]);

        $response = $this->http->request('GET', (string)$url, $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(
                sprintf(
                    'Request for [%s] library id [%s] content responded with unexpected status code. %s',
                    $this->name,
                    $id,
                    arrayToString(
                        [
                            'condition' => [
                                'expected' => 200,
                                'received' => $response->getStatusCode(),
                            ],
                        ]
                    )
                )
            );
        }

        $handleRequest = function (string $type, array $item) use ($opts): array {
            $url = $this->url->withPath(sprintf('/library/metadata/%d', ag($item, 'ratingKey')));

            $possibleTitlesList = ['title', 'originalTitle', 'titleSort'];

            $this->logger->debug('Processing [%(backend)] %(type) [%(title)] response.', [
                'backend' => $this->name,
                'type' => $type,
                'title' => ag($item, $possibleTitlesList, '??'),
                'url' => (string)$url,
            ]);

            $year = ag($item, 'year');

            $metadata = [
                'id' => (int)ag($item, 'ratingKey'),
                'type' => ucfirst($type),
                'url' => (string)$url,
                'title' => ag($item, $possibleTitlesList, '??'),
                'year' => $year,
                'guids' => [],
                'match' => [
                    'titles' => [],
                    'paths' => [],
                ],
            ];

            foreach ($possibleTitlesList as $title) {
                if (null === ($title = ag($item, $title))) {
                    continue;
                }

                $isASCII = mb_detect_encoding($title, 'ASCII', true);
                $title = trim($isASCII ? strtolower($title) : mb_strtolower($title));

                if (true === in_array($title, $metadata['match']['titles'])) {
                    continue;
                }

                $metadata['match']['titles'][] = $title;
            }

            switch ($type) {
                case 'show':
                    foreach (ag($item, 'Location', []) as $path) {
                        $path = ag($path, 'path');
                        $metadata['match']['paths'][] = [
                            'full' => $path,
                            'short' => basename($path),
                        ];
                    }
                    break;
                case 'movie':
                    foreach (ag($item, 'Media', []) as $leaf) {
                        foreach (ag($leaf, 'Part', []) as $path) {
                            $path = ag($path, 'file');
                            $dir = dirname($path);

                            $metadata['match']['paths'][] = [
                                'full' => $path,
                                'short' => basename($path),
                            ];

                            if (false === str_starts_with(basename($path), basename($dir))) {
                                $metadata['match']['paths'][] = [
                                    'full' => $path,
                                    'short' => basename($dir),
                                ];
                            }
                        }
                    }
                    break;
                default:
                    throw new RuntimeException(sprintf('Invalid library item type \'%s\' was given.', $type));
            }

            $itemGuid = ag($item, 'guid');

            if (null !== $itemGuid && false === $this->isLocalPlexId($itemGuid)) {
                $metadata['guids'][] = $itemGuid;
            }

            foreach (ag($item, 'Guid', []) as $guid) {
                $metadata['guids'][] = ag($guid, 'id');
            }

            if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                $metadata['raw'] = $item;
            }

            return $metadata;
        };

        $it = Items::fromIterable(
            iterable: httpClientChunks(stream: $this->http->stream($response)),
            options:  [
                          'pointer' => '/MediaContainer/Metadata',
                          'decoder' => new ErrorWrappingDecoder(
                              innerDecoder: new ExtJsonDecoder(assoc: true, options: JSON_INVALID_UTF8_IGNORE)
                          )
                      ]
        );

        $requests = [];

        foreach ($it as $entity) {
            if ($entity instanceof DecodingError) {
                $this->logger->warning('Failed to decode one item of [%(backend)] library id [%(id)] content.', [
                    'backend' => $this->name,
                    'id' => $id,
                    'context' => [
                        'error' => $entity->getErrorMessage(),
                        'payload' => $entity->getMalformedJson(),
                    ],
                ]);
                continue;
            }

            if (iFace::TYPE_MOVIE === $type) {
                yield $handleRequest($type, $entity);
            } else {
                $url = $this->url->withPath(sprintf('/library/metadata/%d', ag($entity, 'ratingKey')));

                $this->logger->debug('Requesting [%(backend)] %(type) [%(title)] metadata.', [
                    'backend' => $this->getName(),
                    'type' => $type,
                    'title' => ag($entity, 'title', '??'),
                    'url' => $url
                ]);

                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'id' => ag($entity, 'ratingKey'),
                            'title' => ag($entity, 'title'),
                            'type' => $type,
                        ]
                    ])
                );
            }
        }

        if (iFace::TYPE_MOVIE !== $type && empty($requests)) {
            throw new RuntimeException(
                sprintf(
                    'No requests were made it seems [%s] library is [%s] is empty.',
                    $this->getName(),
                    $id
                )
            );
        }

        foreach ($requests as $response) {
            if (200 !== $response->getStatusCode()) {
                $context = $response->getInfo('user_data');

                $this->logger->warning(
                    'Request for [%(backend)] %(type) [%(title)] metadata responded with unexpected status code.',
                    [
                        'backend' => $this->getName(),
                        'type' => ag($context, 'type'),
                        'title' => ag($context, 'title'),
                        'id' => ag($context, 'id'),
                    ]
                );

                continue;
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            yield $handleRequest(
                $response->getInfo('user_data')['type'],
                ag($json, 'MediaContainer.Metadata.0', [])
            );
        }
    }

    public function listLibraries(array $opts = []): array
    {
        $this->checkConfig();

        try {
            $url = $this->url->withPath('/library/sections');

            $this->logger->debug('Requesting [%(backend)] libraries.', [
                'backend' => $this->name,
                'url' => $url
            ]);

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                $this->logger->error('Request for [%(backend)] libraries returned with unexpected status code.', [
                    'backend' => $this->getName(),
                    'condition' => [
                        'expected' => 200,
                        'received' => $response->getStatusCode(),
                    ],
                ]);

                return [];
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            $listDirs = ag($json, 'MediaContainer.Directory', []);

            if (empty($listDirs)) {
                $this->logger->notice('Request for [%(backend)] libraries returned empty body.', [
                    'backend' => $this->getName(),
                    'context' => [
                        'body' => $json,
                    ]
                ]);
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error('Request for [%(backend)] libraries has failed.', [
                'backend' => $this->getName(),
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'message' => $e->getMessage(),
                ],
            ]);
            return [];
        } catch (JsonException $e) {
            $this->logger->error('Request for [%(backend)] libraries returned with invalid body.', [
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage(),
                ],
            ]);
            return [];
        }

        if (null !== ($ignoreIds = ag($this->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => (int)trim($v), explode(',', (string)$ignoreIds));
        }

        $list = [];

        foreach ($listDirs as $section) {
            $key = (int)ag($section, 'key');
            $type = ag($section, 'type', 'unknown');

            $builder = [
                'id' => $key,
                'title' => ag($section, 'title', '???'),
                'type' => $type,
                'ignored' => null !== $ignoreIds && in_array($key, $ignoreIds),
                'supported' => 'movie' === $type || 'show' === $type,
                'agent' => ag($section, 'agent'),
                'scanner' => ag($section, 'scanner'),
            ];

            if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                $builder['raw'] = $section;
            }

            $list[] = $builder;
        }

        return $list;
    }

    public function pull(ImportInterface $mapper, DateTimeInterface|null $after = null): array
    {
        return $this->getLibraries(
            ok: function (array $context = []) use ($after, $mapper) {
                return function (ResponseInterface $response) use ($mapper, $after, $context) {
                    if (200 !== $response->getStatusCode()) {
                        $this->logger->error(
                            'Request for [%(backend)] [%(library.title)] content responded with unexpected status code.',
                            [
                                'backend' => $this->getName(),
                                ...$context,
                                'condition' => [
                                    'expected' => 200,
                                    'received' => $response->getStatusCode(),
                                ],
                            ]
                        );
                        return;
                    }

                    $start = makeDate();
                    $this->logger->info('Parsing [%(backend)] [%(library.title)] response.', [
                        'backend' => $this->getName(),
                        ...$context,
                        'time' => [
                            'start' => $start,
                        ],
                    ]);

                    try {
                        $it = Items::fromIterable(
                            iterable: httpClientChunks($this->http->stream($response)),
                            options:  [
                                          'pointer' => '/MediaContainer/Metadata',
                                          'decoder' => new ErrorWrappingDecoder(
                                              innerDecoder: new ExtJsonDecoder(
                                                                assoc:   true,
                                                                options: JSON_INVALID_UTF8_IGNORE
                                                            )
                                          )
                                      ]
                        );

                        foreach ($it as $entity) {
                            if ($entity instanceof DecodingError) {
                                $this->logger->warning(
                                    'Failed to decode one item of [%(backend)] [%(library.title)] content.',
                                    [
                                        'backend' => $this->getName(),
                                        ...$context,
                                        'context' => [
                                            'error' => $entity->getErrorMessage(),
                                            'body' => $entity->getMalformedJson(),
                                        ],
                                    ]
                                );
                                continue;
                            }

                            $this->processImport(
                                mapper:  $mapper,
                                item:    $entity,
                                context: $context,
                                opts:    ['after' => $after],
                            );
                        }
                    } catch (PathNotFoundException $e) {
                        $this->logger->error(
                            'Failed to find any item in [%(backend)] [%(library.title)] response.',
                            [
                                'backend' => $this->getName(),
                                ...$context,
                                'exception' => [
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'kind' => get_class($e),
                                    'message' => $e->getMessage(),
                                ],
                            ]
                        );
                    } catch (Throwable $e) {
                        $this->logger->error('Failed to handle [%(backend)] [%(library.title)] response.', [
                            'backend' => $this->getName(),
                            ...$context,
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                            ],
                        ]);
                    }

                    $end = makeDate();
                    $this->logger->info('Parsing [%(backend)] [%(library.title)] response is complete.', [
                        'backend' => $this->getName(),
                        ...$context,
                        'time' => [
                            'start' => $start,
                            'end' => $end,
                            'duration' => $end->getTimestamp() - $start->getTimestamp(),
                        ],
                    ]);
                };
            },
            error: function (array $context = []) {
                return fn(Throwable $e) => $this->logger->error(
                    'Unhandled Exception was thrown in [%(backend)] [%(library.title)] request.',
                    [
                        'backend' => $this->getName(),
                        ...$context,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                        ],
                    ]
                );
            },
            includeParent: true
        );
    }

    public function push(array $entities, QueueRequests $queue, DateTimeInterface|null $after = null): array
    {
        $this->checkConfig();

        $requests = [];

        foreach ($entities as $key => $entity) {
            if (true !== ($entity instanceof iFace)) {
                continue;
            }

            if (false === (bool)ag($this->options, Options::IGNORE_DATE, false)) {
                if (null !== $after && $after->getTimestamp() > $entity->updated) {
                    continue;
                }
            }

            $metadata = ag($entity->metadata, $this->name, []);
            $context = [
                'id' => $entity->id,
                'backend' => $this->name,
                'title' => $entity->getName()
            ];

            if (null === ag($metadata, iFace::COLUMN_ID, null)) {
                $this->logger->warning(sprintf('Ignoring %s. No metadata relation map found.', $entity->type), [
                    'context' => $context,
                ]);
                continue;
            }

            $context['backend_id'] = ag($metadata, iFace::COLUMN_ID);

            try {
                $url = $this->url->withPath('/library/metadata/' . ag($metadata, iFace::COLUMN_ID));

                $context['url'] = $url;

                $this->logger->debug('Requesting %(type) state.', [
                    'context' => $context,
                    'type' => $entity->type,
                ]);

                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'id' => $key,
                            'context' => $context,
                            'url' => (string)$url,
                            'suid' => ag($metadata, iFace::COLUMN_ID),
                        ]
                    ])
                );
            } catch (Throwable $e) {
                $this->logger->error(sprintf('%s: %s', self::NAME, $e->getMessage()), [
                    'context' => $context,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                    ],
                ]);
            }
        }

        $context = null;

        foreach ($requests as $response) {
            $context = ag($response->getInfo('user_data'), 'context', ['backend' => $this->name]);

            try {
                if (null === ($id = ag($response->getInfo('user_data'), 'id'))) {
                    $this->logger->error('Unable to get item object.', [
                        'context' => $context,
                    ]);
                    continue;
                }

                $entity = $entities[$id];

                assert($entity instanceof iFace);

                switch ($response->getStatusCode()) {
                    case 200:
                        break;
                    case 404:
                        $this->logger->warning('Request to get item play state returned with (NOT FOUND) status.', [
                            'context' => $context
                        ]);
                        continue 2;
                    default:
                        $this->logger->error('Request to get item play state returned with unexpected status code.', [
                            'context' => $context,
                            'condition' => [
                                'expected' => 200,
                                'given' => $response->getStatusCode(),
                            ]
                        ]);
                        continue 2;
                }

                $body = json_decode(
                    json:        $response->getContent(false),
                    associative: true,
                    flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                );

                $json = ag($body, 'MediaContainer.Metadata', [])[0] ?? [];

                if (empty($json)) {
                    $this->logger->error('Ignoring %(type). Backend responded with unexpected body.', [
                        'type' => $entity->type,
                        'context' => $context,
                        'response' => [
                            'body' => $body,
                        ],
                    ]);
                    continue;
                }

                $isWatched = (int)(bool)ag($json, 'viewCount', 0);

                if ($entity->watched === $isWatched) {
                    $this->logger->info('Ignoring %(type). Play state is identical.', [
                        'type' => $entity->type,
                        'context' => $context,
                    ]);
                    continue;
                }

                if (false === (bool)ag($this->options, Options::IGNORE_DATE, false)) {
                    $date = ag($json, true === (bool)ag($json, 'viewCount', false) ? 'lastViewedAt' : 'addedAt');

                    if (null === $date) {
                        $this->logger->error('Ignoring %(type). No date is set on backend object.', [
                            'type' => $entity->type,
                            'context' => $context,
                            'response' => [
                                'body' => $body,
                            ],
                        ]);
                        continue;
                    }

                    $date = makeDate($date);

                    $timeExtra = (int)(ag($this->options, Options::EXPORT_ALLOWED_TIME_DIFF, 10));

                    if ($date->getTimestamp() >= ($timeExtra + $entity->updated)) {
                        $this->logger->notice('Ignoring %(type). Storage recorded time is older than backend time.', [
                            'type' => $entity->type,
                            'context' => $context,
                            'condition' => [
                                'storage' => makeDate($entity->updated),
                                'backend' => $date,
                                'difference' => $date->getTimestamp() - $entity->updated,
                                'extra_margin' => [
                                    Options::EXPORT_ALLOWED_TIME_DIFF => $timeExtra,
                                ],
                            ],
                        ]);
                        continue;
                    }
                }

                $url = $this->url->withPath($entity->isWatched() ? '/:/scrobble' : '/:/unscrobble')->withQuery(
                    http_build_query(
                        [
                            'identifier' => 'com.plexapp.plugins.library',
                            'key' => ag($json, 'ratingKey'),
                        ]
                    )
                );

                $context['url'] = $url;

                $this->logger->debug('Queuing request to change play state to [%(state)].', [
                    'context' => $context,
                    'state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                ]);

                if (false === (bool)ag($this->options, Options::DRY_RUN)) {
                    $queue->add(
                        $this->http->request(
                            'GET',
                            (string)$url,
                            array_replace_recursive($this->getHeaders(), [
                                'user_data' => [
                                    'itemName' => $entity->getName(),
                                    'server' => $this->name,
                                    'state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                ]
                            ])
                        )
                    );
                }
            } catch (Throwable $e) {
                $this->logger->error(sprintf('%s: %s', self::NAME, $e->getMessage()), [
                    'context' => $context,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                    ],
                ]);
            }
        }

        unset($requests);

        return [];
    }

    public function export(ImportInterface $mapper, QueueRequests $queue, DateTimeInterface|null $after = null): array
    {
        return $this->getLibraries(
            ok: function (array $context = []) use ($mapper, $queue, $after) {
                return function (ResponseInterface $response) use ($mapper, $queue, $after, $context) {
                    if (200 !== $response->getStatusCode()) {
                        $this->logger->error(
                            'Request for [%(backend)] [%(library.title)] content responded with unexpected status code.',
                            [
                                'backend' => $this->getName(),
                                ...$context,
                                'condition' => [
                                    'expected' => 200,
                                    'received' => $response->getStatusCode(),
                                ],
                            ]
                        );
                        return;
                    }

                    $start = makeDate();
                    $this->logger->info('Parsing [%(backend)] [%(library.title)] response.', [
                        'backend' => $this->getName(),
                        ...$context,
                        'time' => [
                            'start' => $start,
                        ],
                    ]);

                    try {
                        $it = Items::fromIterable(
                            iterable: httpClientChunks(stream: $this->http->stream($response)),
                            options:  [
                                          'pointer' => '/MediaContainer/Metadata',
                                          'decoder' => new ErrorWrappingDecoder(
                                              innerDecoder: new ExtJsonDecoder(
                                                                assoc:   true,
                                                                options: JSON_INVALID_UTF8_IGNORE
                                                            )
                                          )
                                      ]
                        );

                        foreach ($it as $entity) {
                            if ($entity instanceof DecodingError) {
                                $this->logger->warning(
                                    'Failed to decode one item of [%(backend)] [%(library.title)] content.',
                                    [
                                        'backend' => $this->getName(),
                                        ...$context,
                                        'context' => [
                                            'error' => $entity->getErrorMessage(),
                                            'body' => $entity->getMalformedJson(),
                                        ],
                                    ]
                                );
                                continue;
                            }

                            $this->processExport(
                                mapper:  $mapper,
                                queue:   $queue,
                                item:    $entity,
                                context: $context,
                                opts:    ['after' => $after]
                            );
                        }
                    } catch (PathNotFoundException $e) {
                        $this->logger->error(
                            'Failed to find any item in [%(backend)] [%(library.title)] response.',
                            [
                                'backend' => $this->getName(),
                                ...$context,
                                'exception' => [
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'kind' => get_class($e),
                                    'message' => $e->getMessage(),
                                ],
                            ]
                        );
                    } catch (Throwable $e) {
                        $this->logger->error('Failed to handle [%(backend)] [%(library.title)] response.', [
                            'backend' => $this->getName(),
                            ...$context,
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                            ],
                        ]);
                    }

                    $end = makeDate();
                    $this->logger->info('Parsing [%(backend)] [%(library.title)] response is complete.', [
                        'backend' => $this->getName(),
                        ...$context,
                        'time' => [
                            'start' => $start,
                            'end' => $end,
                            'duration' => $end->getTimestamp() - $start->getTimestamp(),
                        ],
                    ]);
                };
            },
            error: function (array $context = []) {
                return fn(Throwable $e) => $this->logger->error(
                    'Unhandled Exception was thrown in [%(backend)] [%(library.title)] request.',
                    [
                        'backend' => $this->getName(),
                        ...$context,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                        ],
                    ]
                );
            },
            includeParent: false
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public function __destruct()
    {
        if (true === (bool)ag($this->options, Options::DRY_RUN)) {
            return;
        }

        if (!empty($this->cacheKey) && !empty($this->cache) && true === $this->initialized) {
            $this->cacheIO->set($this->cacheKey, $this->cache, new DateInterval('P3D'));
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
                $this->logger->warning(
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

                if ('show' !== ag($section, 'type', 'unknown')) {
                    continue;
                }

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

                $context = [
                    'library' => [
                        'id' => $key,
                        'title' => ag($section, 'title', '??'),
                        'type' => ag($section, 'type', 'unknown'),
                        'url' => $url,
                    ],
                ];

                $this->logger->debug('Requesting [%(backend)] [%(library.title)] series external ids.', [
                    'backend' => $this->getName(),
                    ...$context,
                ]);

                try {
                    $promises[] = $this->http->request(
                        'GET',
                        (string)$url,
                        array_replace_recursive($this->getHeaders(), [
                            'user_data' => [
                                'ok' => $ok(context: $context),
                                'error' => $error(context: $context),
                            ]
                        ])
                    );
                } catch (ExceptionInterface $e) {
                    $this->logger->error(
                        'Request for [%(backend)] [%(library.title)] series external ids has failed.',
                        [
                            'backend' => $this->getName(),
                            ...$context,
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                            ],
                        ]
                    );
                    continue;
                }
            }
        }

        foreach ($listDirs as $section) {
            $key = (int)ag($section, 'key');
            $type = ag($section, 'type', 'unknown');

            $context = [
                'library' => [
                    'id' => ag($section, 'key'),
                    'title' => ag($section, 'title', '??'),
                    'type' => ag($section, 'type', 'unknown'),
                ],
            ];

            if (null !== $ignoreIds && true === in_array($key, $ignoreIds)) {
                $ignored++;
                $this->logger->info('Ignoring [%(backend)] [%(library.title)] as requested by user choice.', [
                    'backend' => $this->getName(),
                    ...$context,
                ]);
                continue;
            }

            if ('movie' !== $type && 'show' !== $type) {
                $unsupported++;
                $this->logger->info('Ignoring [%(backend)] [%(library.title)] as library type is not supported.', [
                    'backend' => $this->getName(),
                    ...$context,
                ]);
                continue;
            }

            $type = $type === 'movie' ? iFace::TYPE_MOVIE : iFace::TYPE_EPISODE;

            $url = $this->url->withPath(sprintf('/library/sections/%d/all', $key))->withQuery(
                http_build_query(
                    [
                        'type' => 'movie' === $type ? 1 : 4,
                        'sort' => 'addedAt:asc',
                        'includeGuids' => 1,
                    ]
                )
            );

            $context['library']['type'] = $type;
            $context['library']['url'] = $url;

            $this->logger->debug('Requesting [%(backend)] [%(library.title)] content.', [
                'backend' => $this->getName(),
                ...$context,
            ]);

            try {
                $promises[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'ok' => $ok(context: $context),
                            'error' => $error(context: $context),
                        ]
                    ])
                );
            } catch (ExceptionInterface $e) {
                $this->logger->error('Requesting for [%(backend)] [%(library.title)] content has failed.', [
                    'backend' => $this->getName(),
                    ...$context,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                    ],
                ]);
                continue;
            }
        }

        if (0 === count($promises)) {
            $this->logger->warning('No requests for [%(backend)] were queued.', [
                'backend' => $this->getName(),
                'context' => [
                    'total' => count($listDirs),
                    'ignored' => $ignored,
                    'unsupported' => $unsupported,
                ],
            ]);

            Data::add($this->name, 'no_import_update', true);
            return [];
        }

        return $promises;
    }

    protected function processImport(ImportInterface $mapper, array $item, array $context = [], array $opts = []): void
    {
        $after = ag($opts, 'after', null);
        $library = ag($context, 'library.id');
        $type = ag($item, 'type');

        try {
            if ('show' === $type) {
                $this->processShow($item, $context);
                return;
            }

            Data::increment($this->name, $library . '_total');
            Data::increment($this->name, $type . '_total');

            $context['item'] = [
                'id' => ag($item, 'ratingKey'),
                'title' => match ($type) {
                    iFace::TYPE_MOVIE => sprintf(
                        '%s (%s)',
                        ag($item, ['title', 'originalTitle'], '??'),
                        ag($item, 'year', '0000')
                    ),
                    iFace::TYPE_EPISODE => sprintf(
                        '%s - (%sx%s)',
                        ag($item, ['grandparentTitle', 'originalTitle', 'title'], '??'),
                        str_pad((string)ag($item, 'parentIndex', 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)ag($item, 'index', 0), 3, '0', STR_PAD_LEFT),
                    ),
                },
                'type' => ag($item, 'type', 'unknown'),
            ];

            if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
                $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title)]', [
                    'backend' => $this->getName(),
                    ...$context,
                    'payload' => $item,
                ]);
            }

            if (null === ag($item, true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt')) {
                $this->logger->debug('Ignoring [%(backend)] [%(item.title)]. No Date is set on object.', [
                    'backend' => $this->getName(),
                    ...$context,
                    'condition' => [
                        'expectedKey' => true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt',
                    ],
                    'response' => [
                        'body' => $item,
                    ],
                ]);

                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $entity = $this->createEntity(item: $item, type: $type, opts: $opts);

            if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
                if (true === (bool)Config::get('debug.import')) {
                    $name = Config::get('tmpDir') . '/debug/' . $this->name . '.' . $item['ratingKey'] . '.json';

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

                $message = 'Ignoring [%(backend)] [%(item.title)]. No valid/supported external ids.';

                if (null === ($item['Guid'] ?? null)) {
                    $item['Guid'] = [];
                }

                if (null !== ($itemGuid = ag($item, 'guid')) && false === $this->isLocalPlexId($itemGuid)) {
                    $item['Guid'][] = $itemGuid;
                }

                if (empty($item['Guid'])) {
                    $message .= ' Most likely unmatched %(item.type).';
                }

                $this->logger->info($message, [
                    'backend' => $this->getName(),
                    ...$context,
                    'context' => [
                        'guids' => !empty($item['Guid']) ? $item['Guid'] : 'None'
                    ],
                ]);

                Data::increment($this->name, $type . '_ignored_no_supported_guid');
                return;
            }

            $mapper->add(entity: $entity, opts: [
                'after' => $after,
                Options::IMPORT_METADATA_ONLY => true === (bool)ag($this->options, Options::IMPORT_METADATA_ONLY),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to handle [%(backend)] [%(library.title)] [%(item.title)].', [
                'backend' => $this->getName(),
                ...$context,
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'message' => $e->getMessage(),
                ],
            ]);
        }
    }

    protected function processExport(
        ImportInterface $mapper,
        QueueRequests $queue,
        array $item,
        array $context = [],
        array $opts = [],
    ): void {
        $after = ag($opts, 'after', null);
        $library = ag($context, 'library.id');
        $type = ag($item, 'type');

        try {
            Data::increment($this->name, $library . '_total');
            Data::increment($this->name, $type . '_total');

            $context['item'] = [
                'id' => ag($item, 'ratingKey'),
                'title' => match ($type) {
                    iFace::TYPE_MOVIE => sprintf(
                        '%s (%s)',
                        ag($item, ['title', 'originalTitle'], '??'),
                        ag($item, 'year', '0000')
                    ),
                    iFace::TYPE_EPISODE => sprintf(
                        '%s - (%sx%s)',
                        ag($item, ['grandparentTitle', 'originalTitle', 'title'], '??'),
                        str_pad((string)ag($item, 'parentIndex', 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)ag($item, 'index', 0), 3, '0', STR_PAD_LEFT),
                    ),
                },
                'type' => ag($item, 'type', 'unknown'),
            ];

            if (iFace::TYPE_MOVIE === $type) {
                $iName = sprintf(
                    '%s - [%s (%d)]',
                    $library,
                    ag($item, ['title', 'originalTitle'], '??'),
                    ag($item, 'year', 0000)
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - [%s - (%sx%s)]',
                        $library,
                        ag($item, ['grandparentTitle', 'originalTitle'], '??'),
                        str_pad((string)ag($item, 'parentIndex', 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)ag($item, 'index', 0), 3, '0', STR_PAD_LEFT),
                    )
                );
            }

            if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
                $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title)]', [
                    'backend' => $this->getName(),
                    ...$context,
                    'payload' => $item,
                ]);
            }

            if (null === ag($item, true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt')) {
                $this->logger->debug('Ignoring [%(backend)] [%(item.title)]. No Date is set on object.', [
                    'backend' => $this->getName(),
                    ...$context,
                    'condition' => [
                        'expectedKey' => true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt',
                    ],
                    'response' => [
                        'body' => $item,
                    ],
                ]);

                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $rItem = $this->createEntity(item: $item, type: $type, opts: $opts);

            if (!$rItem->hasGuids() && !$rItem->hasRelativeGuid()) {
                $message = 'Ignoring [%(backend)] [%(item.title)]. No valid/supported external ids.';

                if (null === ($item['Guid'] ?? null)) {
                    $item['Guid'] = [];
                }

                if (null !== ($itemGuid = ag($item, 'guid')) && false === $this->isLocalPlexId($itemGuid)) {
                    $item['Guid'][] = $itemGuid;
                }

                if (empty($item['Guid'])) {
                    $message .= ' Most likely unmatched %(item.type).';
                }

                $this->logger->info($message, [
                    'backend' => $this->getName(),
                    ...$context,
                    'context' => [
                        'guids' => !empty($item['Guid']) ? $item['Guid'] : 'None'
                    ],
                ]);

                Data::increment($this->name, $type . '_ignored_no_supported_guid');
                return;
            }

            if (false === ag($this->options, Options::IGNORE_DATE, false)) {
                if (true === ($after instanceof DateTimeInterface) && $rItem->updated >= $after->getTimestamp()) {
                    $this->logger->debug(
                        'Ignoring [%(backend)] [%(item.title)]. Backend date is equal or newer than last sync date.',
                        [
                            'backend' => $this->getName(),
                            ...$context,
                            'content' => [
                                'lastSync' => makeDate($after),
                                'backend' => makeDate($rItem->updated),
                            ],
                        ]
                    );

                    Data::increment($this->name, $type . '_ignored_date_is_equal_or_higher');
                    return;
                }
            }

            if (null === ($entity = $mapper->get($rItem))) {
                $this->logger->warning(
                    'Ignoring [%(backend)] [%(item.title)]. %(item.type) Is not imported.',
                    [
                        'backend' => $this->getName(),
                        ...$context,
                    ]
                );
                Data::increment($this->name, $type . '_ignored_not_found_in_db');
                return;
            }

            if ($rItem->watched === $entity->watched) {
                if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
                    $this->logger->debug(
                        'Ignoring [%(backend)] [%(item.title)]. %(item.type) play state is identical.',
                        [
                            'backend' => $this->getName(),
                            ...$context,
                            'context' => [
                                'backend' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                'remote' => $rItem->isWatched() ? 'Played' : 'Unplayed',
                            ],
                        ]
                    );
                }

                Data::increment($this->name, $type . '_ignored_state_unchanged');
                return;
            }

            if (false === ag($this->options, Options::IGNORE_DATE, false) && $rItem->updated >= $entity->updated) {
                $this->logger->debug(
                    'Ignoring [%(backend)] [%(item.title)]. Backend date is equal or newer than storage date.',
                    [
                        'backend' => $this->getName(),
                        ...$context,
                        'content' => [
                            'storage' => makeDate($entity->updated),
                            'backend' => makeDate($rItem->updated),
                        ],
                    ]
                );

                Data::increment($this->name, $type . '_ignored_date_is_newer');
                return;
            }

            $url = $this->url->withPath('/:' . ($entity->isWatched() ? '/scrobble' : '/unscrobble'))->withQuery(
                http_build_query(
                    [
                        'identifier' => 'com.plexapp.plugins.library',
                        'key' => $item['ratingKey'],
                    ]
                )
            );

            $context['item']['url'] = $url;

            $this->logger->debug(
                'Queuing Request for [%(backend)] to change the play state of [%(item.title)] to [%(play_state)].',
                [
                    'backend' => $this->getName(),
                    'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                    ...$context,
                ]
            );

            if (false === (bool)ag($this->options, Options::DRY_RUN, false)) {
                $queue->add(
                    $this->http->request(
                        'GET',
                        (string)$url,
                        array_replace_recursive($this->getHeaders(), [
                            'user_data' => [
                                'itemName' => $iName,
                                'server' => $this->name,
                                'state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                'context' => $context,
                            ]
                        ])
                    )
                );
            }
        } catch (Throwable $e) {
            $this->logger->error('Failed to handle [%(backend)] [%(library.title)] [%(item.title)].', [
                'backend' => $this->getName(),
                ...$context,
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'message' => $e->getMessage(),
                ],
            ]);
        }
    }

    protected function processShow(array $item, array $context): void
    {
        if (null === ($item['Guid'] ?? null)) {
            $item['Guid'] = [['id' => $item['guid']]];
        } else {
            $item['Guid'][] = ['id' => $item['guid']];
        }

        $context['item'] = [
            'id' => ag($item, 'ratingKey'),
            'title' => ag($item, ['title', 'originalTitle'], '??'),
            'year' => ag($item, 'year', '0000'),
            'type' => ag($item, 'type', 'unknown'),
        ];

        if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
            $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title) (%(item.year))].', [
                'backend' => $this->getName(),
                ...$context,
                'response' => [
                    'body' => $item,
                ],
            ]);
        }

        if (!$this->hasSupportedGuids(guids: $item['Guid'])) {
            if (null === ($item['Guid'] ?? null)) {
                $item['Guid'] = [];
            }

            if (null !== ($item['guid'] ?? null) && false === $this->isLocalPlexId($item['guid'])) {
                $item['Guid'][] = ['id' => $item['guid']];
            }

            $message = 'Ignoring [%(backend)] [%(item.title)]. %(item.type) has no valid/supported external ids.';

            if (empty($item['Guid'] ?? [])) {
                $message .= ' Most likely unmatched %(item.type).';
            }

            $this->logger->info($message, [
                'backend' => $this->getName(),
                ...$context,
                'data' => [
                    'guids' => !empty($item['Guid']) ? $item['Guid'] : 'None'
                ],
            ]);

            return;
        }

        $this->cache['shows'][$context['item']['id']] = Guid::fromArray($this->getGuids($item['Guid']))->getAll();
    }

    protected function parseGuids(array $guids): array
    {
        $guid = [];

        $ids = array_column($guids, 'id');

        foreach ($ids as $val) {
            try {
                if (empty($val)) {
                    continue;
                }

                if (true === str_starts_with($val, 'com.plexapp.agents.')) {
                    // -- DO NOT accept plex relative unique ids, we generate our own.
                    if (substr_count($val, '/') >= 3) {
                        continue;
                    }
                    $val = $this->parseLegacyAgent($val);
                }

                if (false === str_contains($val, '://')) {
                    continue;
                }

                [$key, $value] = explode('://', $val);
                $key = strtolower($key);

                $guid[$key] = $value;
            } catch (Throwable) {
                continue;
            }
        }

        ksort($guid);

        return $guid;
    }

    protected function getGuids(array $guids, array $context = []): array
    {
        $guid = [];

        $ids = array_column($guids, 'id');

        foreach ($ids as $val) {
            try {
                if (empty($val)) {
                    continue;
                }

                if (true === str_starts_with($val, 'com.plexapp.agents.')) {
                    // -- DO NOT accept plex relative unique ids, we generate our own.
                    if (substr_count($val, '/') >= 3) {
                        if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
                            $this->logger->warning('Parsing [%(backend)] [%(agent)] identifier is not supported.', [
                                'backend' => $this->getName(),
                                'agent' => $val,
                                ...$context
                            ]);
                        }
                        continue;
                    }
                    $val = $this->parseLegacyAgent($val);
                }

                if (false === str_contains($val, '://')) {
                    $this->logger->info(
                        'Parsing [%(backend)] [%(agent)] identifier impossible. Probably alternative version of movie?',
                        [
                            'backend' => $this->getName(),
                            'agent' => $val ?? null,
                            ...$context
                        ]
                    );
                    continue;
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
            } catch (Throwable $e) {
                $this->logger->error(
                    'Unhandled exception was thrown in parsing of [%(backend)] [%(agent)] identifier.',
                    [
                        'backend' => $this->getName(),
                        'agent' => $val ?? null,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                        ],
                    ]
                );
                continue;
            }
        }

        ksort($guid);

        return $guid;
    }

    protected function hasSupportedGuids(array $guids, array $context = []): bool
    {
        foreach ($guids as $_id) {
            try {
                $val = is_object($_id) ? $_id->id : $_id['id'];

                if (empty($val)) {
                    continue;
                }

                if (true === str_starts_with($val, 'com.plexapp.agents.')) {
                    // -- DO NOT accept plex relative unique ids, we generate our own.
                    if (substr_count($val, '/') >= 3) {
                        if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
                            $this->logger->warning('Parsing [%(backend)] [%(agent)] identifier is not supported.', [
                                'backend' => $this->getName(),
                                'agent' => $val,
                                ...$context
                            ]);
                        }
                        continue;
                    }
                    $val = $this->parseLegacyAgent($val);
                }

                if (false === str_contains($val, '://')) {
                    continue;
                }

                [$key, $value] = explode('://', $val);
                $key = strtolower($key);

                if (null !== (self::GUID_MAPPER[$key] ?? null) && !empty($value)) {
                    return true;
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    'Unhandled exception was thrown in parsing of [%(backend)] [%(agent)] identifier.',
                    [
                        'backend' => $this->getName(),
                        'agent' => $val ?? null,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                        ],
                    ]
                );
                continue;
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

    protected function createEntity(array $item, string $type, array $opts = []): StateEntity
    {
        // -- Handle watched/updated column in a special way to support mark as unplayed.
        if (null !== ($opts['override'][iFace::COLUMN_WATCHED] ?? null)) {
            $isPlayed = (bool)$opts['override'][iFace::COLUMN_WATCHED];
            $date = $opts['override'][iFace::COLUMN_UPDATED] ?? ag($item, 'addedAt');
        } else {
            $isPlayed = (bool)ag($item, 'viewCount', false);
            $date = ag($item, true === $isPlayed ? 'lastViewedAt' : 'addedAt');
        }

        if (null === $date) {
            throw new RuntimeException('No date was set on object.');
        }

        if (null === ag($item, 'Guid')) {
            $item['Guid'] = [['id' => ag($item, 'guid')]];
        } else {
            $item['Guid'][] = ['id' => ag($item, 'guid')];
        }

        $guids = $this->getGuids(ag($item, 'Guid', []));
        $guids += Guid::makeVirtualGuid($this->name, (string)ag($item, 'ratingKey'));

        $builder = [
            iFace::COLUMN_TYPE => $type,
            iFace::COLUMN_UPDATED => (int)$date,
            iFace::COLUMN_WATCHED => (int)$isPlayed,
            iFace::COLUMN_VIA => $this->name,
            iFace::COLUMN_TITLE => ag($item, ['title', 'originalTitle'], '??'),
            iFace::COLUMN_GUIDS => $guids,
            iFace::COLUMN_META_DATA => [
                $this->name => [
                    iFace::COLUMN_ID => (string)ag($item, 'ratingKey'),
                    iFace::COLUMN_TYPE => $type,
                    iFace::COLUMN_WATCHED => true === $isPlayed ? '1' : '0',
                    iFace::COLUMN_VIA => $this->name,
                    iFace::COLUMN_TITLE => ag($item, ['title', 'originalTitle'], '??'),
                    iFace::COLUMN_GUIDS => $this->parseGuids(ag($item, 'Guid', [])),
                    iFace::COLUMN_META_DATA_ADDED_AT => (string)ag($item, 'addedAt'),
                ],
            ],
            iFace::COLUMN_EXTRA => [],
        ];

        $metadata = &$builder[iFace::COLUMN_META_DATA][$this->name];
        $metadataExtra = &$metadata[iFace::COLUMN_META_DATA_EXTRA];

        if (null !== ($library = ag($item, 'librarySectionID', $opts['library'] ?? null))) {
            $metadata[iFace::COLUMN_META_LIBRARY] = (string)$library;
        }

        if (iFace::TYPE_EPISODE === $type) {
            $builder[iFace::COLUMN_SEASON] = (int)ag($item, 'parentIndex', 0);
            $builder[iFace::COLUMN_EPISODE] = (int)ag($item, 'index', 0);

            $metadata[iFace::COLUMN_META_SHOW] = (string)ag($item, ['grandparentRatingKey', 'parentRatingKey'], '??');

            $metadata[iFace::COLUMN_TITLE] = ag($item, 'grandparentTitle', '??');
            $metadata[iFace::COLUMN_SEASON] = (string)$builder[iFace::COLUMN_SEASON];
            $metadata[iFace::COLUMN_EPISODE] = (string)$builder[iFace::COLUMN_EPISODE];

            $metadataExtra[iFace::COLUMN_META_DATA_EXTRA_TITLE] = $builder[iFace::COLUMN_TITLE];
            $builder[iFace::COLUMN_TITLE] = $metadata[iFace::COLUMN_TITLE];

            if (null !== ($parentId = ag($item, ['grandparentRatingKey', 'parentRatingKey']))) {
                $builder[iFace::COLUMN_PARENT] = $this->getEpisodeParent($parentId);
                $metadata[iFace::COLUMN_PARENT] = $builder[iFace::COLUMN_PARENT];
            }
        }

        if (null !== ($mediaYear = ag($item, ['grandParentYear', 'parentYear', 'year'])) && !empty($mediaYear)) {
            $builder[iFace::COLUMN_YEAR] = $mediaYear;
            $metadata[iFace::COLUMN_YEAR] = $mediaYear;
        }

        if (null !== ($PremieredAt = ag($item, 'originallyAvailableAt'))) {
            $metadataExtra[iFace::COLUMN_META_DATA_EXTRA_DATE] = makeDate($PremieredAt)->format('Y-m-d');
        }

        if (true === $isPlayed) {
            $metadata[iFace::COLUMN_META_DATA_PLAYED_AT] = (string)$date;
        }

        unset($metadata, $metadataExtra);

        if (null !== ($opts['override'] ?? null)) {
            $builder = array_replace_recursive($builder, $opts['override'] ?? []);
        }

        return Container::get(iFace::class)::fromArray($builder);
    }

    protected function getEpisodeParent(int|string $id, array $context = []): array
    {
        if (array_key_exists($id, $this->cache['shows'] ?? [])) {
            return $this->cache['shows'][$id];
        }

        try {
            $json = ag($this->getMetadata($id), 'MediaContainer.Metadata.0', []);

            if (null === ($type = ag($json, 'type')) || 'show' !== $type) {
                return [];
            }

            if (null === ($json['Guid'] ?? null)) {
                $json['Guid'] = [['id' => $json['guid']]];
            } else {
                $json['Guid'][] = ['id' => $json['guid']];
            }

            if (!$this->hasSupportedGuids(guids: $json['Guid'])) {
                $this->cache['shows'][$id] = [];
                return [];
            }

            $this->cache['shows'][$id] = Guid::fromArray($this->getGuids($json['Guid']))->getAll();

            return $this->cache['shows'][$id];
        } catch (RuntimeException $e) {
            $this->logger->error($e->getMessage(), [
                'backend' => $this->getName(),
                ...$context,
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                ],
            ]);
            return [];
        }
    }

    /**
     * Parse legacy plex agents.
     *
     * @param string $agent
     *
     * @return string
     * @see https://github.com/ZeroQI/Hama.bundle/issues/510
     */
    protected function parseLegacyAgent(string $agent): string
    {
        try {
            if (false === in_array(before($agent, '://'), self::SUPPORTED_LEGACY_AGENTS)) {
                return $agent;
            }

            // -- Handle hama plex agent. This is multi source agent.
            if (true === str_starts_with($agent, 'com.plexapp.agents.hama')) {
                $guid = after($agent, '://');

                if (1 !== preg_match(self::HAMA_REGEX, $guid, $matches)) {
                    return $agent;
                }

                if (null === ($source = ag($matches, 'source')) || null === ($sourceId = ag($matches, 'id'))) {
                    return $agent;
                }

                return str_replace('tsdb', 'tmdb', $source) . '://' . before($sourceId, '?');
            }

            $agent = str_replace(
                array_keys(self::GUID_AGENT_REPLACER),
                array_values(self::GUID_AGENT_REPLACER),
                $agent
            );

            $agentGuid = explode('://', after($agent, 'agents.'));

            return $agentGuid[0] . '://' . before($agentGuid[1], '?');
        } catch (Throwable $e) {
            $this->logger->error(
                'Unhandled exception was thrown in parsing of [%(backend)] [%(agent)] identifier.',
                [
                    'backend' => $this->getName(),
                    'agent' => $agent,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                    ],
                ]
            );
            return $agent;
        }
    }

    /**
     * Is Given id a local plex id?
     *
     * @param string $id
     *
     * @return bool
     */
    protected function isLocalPlexId(string $id): bool
    {
        $id = strtolower($id);

        foreach (self::GUID_PLEX_LOCAL as $guid) {
            if (true === str_starts_with($id, $guid)) {
                return true;
            }
        }

        return false;
    }

    protected function getUserToken(int|string $userId): int|string|null
    {
        try {
            $uuid = $this->getServerUUID();

            $url = Container::getNew(UriInterface::class)->withPort(443)->withScheme('https')->withHost(
                'plex.tv'
            )->withPath(sprintf('/api/v2/home/users/%s/switch', $userId));

            $this->logger->debug('Requesting temporary token for [%(backend)] user id [%(user_id)].', [
                'backend' => $this->getName(),
                'user_id' => $userId,
                'url' => (string)$url,
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
                    'Request for [%(backend)] [%(user_id)] temporary token responded with unexpected status code.',
                    [
                        'backend' => $this->getName(),
                        'user_id' => $userId,
                        'condition' => [
                            'expected' => 200,
                            'received' => $response->getStatusCode(),
                        ],
                    ]
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

            $this->logger->debug('Requesting permanent token for [%(backend)] user id [%(user_id)].', [
                'backend' => $this->getName(),
                'user_id' => $userId,
                'url' => (string)$url,
            ]);

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
            $this->logger->error(
                'Unhandled exception was thrown during request for [%(backend)] [%(user_id)] access token.',
                [
                    'backend' => $this->getName(),
                    'user_id' => $userId,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                    ],
                ]
            );

            return null;
        }
    }
}
