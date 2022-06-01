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
    protected array $cache = [];

    protected string|int|null $uuid = null;

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
        if (null === $token) {
            throw new RuntimeException(self::NAME . ': No token is set.');
        }

        $cloned = clone $this;

        $cloned->name = $name;
        $cloned->url = $url;
        $cloned->token = $token;
        $cloned->uuid = $uuid;
        $cloned->user = $userId;
        $cloned->persist = $persist;
        $cloned->isEmby = (bool)($options['emby'] ?? false);
        $cloned->initialized = true;

        $cloned->cache = [];
        $cloned->cacheKey = $cloned::NAME . '_' . $name;

        if ($cloned->cacheIO->has($cloned->cacheKey)) {
            $cloned->cache = $cloned->cacheIO->get($cloned->cacheKey);
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

        $this->logger->debug(sprintf('%s: Requesting server unique id.', $this->name), ['url' => $url]);

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
            json:        $response->getContent(),
            associative: true,
            flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

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

        $json = json_decode(
            json:        $response->getContent(),
            associative: true,
            flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        $list = [];

        foreach ($json ?? [] as $user) {
            $date = ag($user, ['LastActivityDate', 'LastLoginDate'], null);

            $data = [
                'id' => ag($user, 'Id'),
                'name' => ag($user, 'Name'),
                'admin' => (bool)ag($user, 'Policy.IsAdministrator'),
                'Hidden' => (bool)ag($user, 'Policy.IsHidden'),
                'disabled' => (bool)ag($user, 'Policy.IsDisabled'),
                'updatedAt' => null !== $date ? makeDate($date) : 'Never',
            ];

            if (true === ag($opts, 'tokens', false)) {
                $data['token'] = $this->token;
            }

            if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                $data['raw'] = $user;
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

            if (false === str_starts_with($userAgent, 'Jellyfin-Server/')) {
                return $request;
            }

            $payload = (string)$request->getBody();

            if (null === ($json = json_decode(json: $payload, associative: true, flags: JSON_INVALID_UTF8_IGNORE))) {
                return $request;
            }

            $request = $request->withParsedBody($json);

            $attributes = [
                'ITEM_ID' => ag($json, 'ItemId', ''),
                'SERVER_ID' => ag($json, 'ServerId', ''),
                'SERVER_NAME' => ag($json, 'ServerName', ''),
                'SERVER_VERSION' => ag($json, 'ServerVersion', fn() => afterLast($userAgent, '/')),
                'USER_ID' => ag($json, 'UserId', ''),
                'USER_NAME' => ag($json, 'NotificationUsername', ''),
                'WH_EVENT' => ag($json, 'NotificationType', 'not_set'),
                'WH_TYPE' => ag($json, 'ItemType', 'not_set'),
            ];

            foreach ($attributes as $key => $val) {
                $request = $request->withAttribute($key, $val);
            }
        } catch (Throwable $e) {
            $logger?->error(sprintf('%s: %s', self::NAME, $e->getMessage()), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
        }

        return $request;
    }

    public function parseWebhook(ServerRequestInterface $request): iFace
    {
        if (null === ($json = $request->getParsedBody())) {
            throw new HttpException(sprintf('%s: No payload.', self::NAME), 400);
        }

        $event = ag($json, 'NotificationType', 'unknown');
        $type = ag($json, 'ItemType', 'not_found');
        $id = ag($json, 'ItemId');

        if (null === $type || false === in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            throw new HttpException(
                sprintf('%s: Webhook content type is not supported. [%s]', $this->getName(), $type), 200
            );
        }

        if (null === $event || false === in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            throw new HttpException(
                sprintf('%s: Webhook event type is not supported. [%s]', $this->getName(), $event), 200
            );
        }

        if (null === $id) {
            throw new HttpException(sprintf('%s: Webhook payload has no id.', $this->getName()), 400);
        }

        $type = strtolower($type);

        try {
            $isPlayed = (bool)ag($json, 'Played');
            $lastPlayedAt = true === $isPlayed ? ag($json, 'LastPlayedDate') : null;

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
                $lastPlayedAt = makeDate($lastPlayedAt)->getTimestamp();
                $fields = array_replace_recursive($fields, [
                    iFace::COLUMN_UPDATED => $lastPlayedAt,
                    iFace::COLUMN_META_DATA => [
                        $this->name => [
                            iFace::COLUMN_META_DATA_PLAYED_AT => (string)$lastPlayedAt,
                        ]
                    ],
                ]);
            }

            $providersId = [];

            foreach ($json as $key => $val) {
                if (false === str_starts_with($key, 'Provider_')) {
                    continue;
                }
                $providersId[after($key, 'Provider_')] = $val;
            }

            if (null !== ($guids = $this->getGuids($providersId)) && false === empty($guids)) {
                $guids += Guid::makeVirtualGuid($this->name, (string)$id);
                $fields[iFace::COLUMN_GUIDS] = $guids;
                $fields[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_GUIDS] = $fields[iFace::COLUMN_GUIDS];
            }

            $entity = $this->createEntity(
                item: $this->getMetadata(id: $id),
                type: $type,
                opts: ['override' => $fields],
            )->setIsTainted(isTainted: true === in_array($event, self::WEBHOOK_TAINTED_EVENTS));
        } catch (Throwable $e) {
            $this->logger->error(sprintf('%s: %s', self::NAME, $e->getMessage()), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
            throw new HttpException(
                sprintf(
                    '%s: Unable to process item id \'%s\'.',
                    $this->getName(),
                    $id,
                ), 200
            );
        }

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $message = sprintf('%s: No valid/supported external ids.', self::NAME);

            if (empty($providersId)) {
                $message .= sprintf(' Most likely unmatched %s.', $entity->type);
            }

            $message .= sprintf(' [%s].', arrayToString(['guids' => !empty($providersId) ? $providersId : 'None']));

            throw new HttpException($message, 400);
        }

        return $entity;
    }

    public function search(string $query, int $limit = 25, array $opts = []): array
    {
        $this->checkConfig(true);

        try {
            $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
                http_build_query(
                    array_replace_recursive(
                        [
                            'searchTerm' => $query,
                            'Limit' => $limit,
                            'Recursive' => 'true',
                            'Fields' => 'ProviderIds,DateCreated,OriginalTitle,SeasonUserData,DateLastSaved',
                            'enableUserData' => 'true',
                            'enableImages' => 'false',
                            'IncludeItemTypes' => 'Episode,Movie,Series',
                        ],
                        $opts['query'] ?? []
                    )
                )
            );

            $this->logger->debug(sprintf('%s: Sending search request for \'%s\'.', $this->name, $query), [
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

            $list = [];

            foreach (ag($json, 'Items', []) as $item) {
                $watchedAt = ag($item, 'UserData.LastPlayedDate');
                $year = (int)ag($item, 'Year', 0);

                if (0 === $year && null !== ($airDate = ag($item, 'PremiereDate'))) {
                    $year = (int)makeDate($airDate)->format('Y');
                }

                $type = strtolower(ag($item, 'Type'));

                $episodeNumber = ('episode' === $type) ? sprintf(
                    '%sx%s - ',
                    str_pad((string)(ag($item, 'ParentIndexNumber', 0)), 2, '0', STR_PAD_LEFT),
                    str_pad((string)(ag($item, 'IndexNumber', 0)), 3, '0', STR_PAD_LEFT),
                ) : null;

                $builder = [
                    'id' => ag($item, 'Id'),
                    'type' => ucfirst($type),
                    'title' => $episodeNumber . mb_substr(ag($item, ['Name', 'OriginalTitle'], '??'), 0, 50),
                    'year' => $year,
                    'addedAt' => makeDate(ag($item, 'DateCreated', 'now'))->format('Y-m-d H:i:s T'),
                    'watchedAt' => null !== $watchedAt ? makeDate($watchedAt)->format('Y-m-d H:i:s T') : 'Never',
                ];

                if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                    $builder['raw'] = $item;
                }

                $list[] = $builder;
            }

            return $list;
        } catch (ExceptionInterface|JsonException $e) {
            throw new RuntimeException(get_class($e) . ': ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function searchId(string|int $id, array $opts = []): array
    {
        $item = $this->getMetadata($id, $opts);

        $year = (int)ag($item, 'Year', 0);

        if (0 === $year && null !== ($airDate = ag($item, 'PremiereDate'))) {
            $year = (int)makeDate($airDate)->format('Y');
        }

        $type = strtolower(ag($item, 'Type'));

        $episodeNumber = ('episode' === $type) ? sprintf(
            '%sx%s - ',
            str_pad((string)(ag($item, 'ParentIndexNumber', 0)), 2, '0', STR_PAD_LEFT),
            str_pad((string)(ag($item, 'IndexNumber', 0)), 3, '0', STR_PAD_LEFT),
        ) : null;

        $builder = [
            'id' => ag($item, 'Id'),
            'type' => ucfirst($type),
            'title' => $episodeNumber . mb_substr(ag($item, ['Name', 'OriginalTitle'], '??'), 0, 50),
            'year' => $year,
            'addedAt' => makeDate(ag($item, 'DateCreated', 'now'))->format('Y-m-d H:i:s T'),
        ];

        if (null !== ($watchedAt = ag($item, 'UserData.LastPlayedDate'))) {
            $builder['watchedAt'] = makeDate($watchedAt)->format('Y-m-d H:i:s T');
        }

        if (null !== ($endDate = ag($item, 'EndDate'))) {
            $builder['EndedAt'] = makeDate($endDate)->format('Y-m-d H:i:s T');
        }

        if (('movie' === $type || 'series' === $type) && null !== ($premiereDate = ag($item, 'PremiereDate'))) {
            $builder['premieredAt'] = makeDate($premiereDate)->format('Y-m-d H:i:s T');
        }

        if (null !== $watchedAt) {
            $builder['watchedAt'] = makeDate($watchedAt)->format('Y-m-d H:i:s T');
        }

        if (('episode' === $type || 'movie' === $type) && null !== ($duration = ag($item, 'RunTimeTicks'))) {
            $builder['duration'] = formatDuration($duration / 10000);
        }

        if (null !== ($status = ag($item, 'Status'))) {
            $builder['status'] = $status;
        }

        if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
            $builder['raw'] = $item;
        }

        return $builder;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function getMetadata(string|int $id, array $opts = []): array
    {
        $this->checkConfig();

        $cacheKey = false === ((bool)($opts['nocache'] ?? false)) ? $this->getName() . '_' . $id . '_metadata' : null;

        if (null !== $cacheKey && $this->cacheIO->has($cacheKey)) {
            return $this->cacheIO->get(key: $cacheKey);
        }

        try {
            $url = $this->url->withPath(sprintf('/Users/%s/items/' . $id, $this->user))->withQuery(
                http_build_query(
                    array_merge_recursive(
                        [
                            'Recursive' => 'false',
                            'Fields' => 'ProviderIds',
                            'enableUserData' => 'true',
                            'enableImages' => 'false',
                            'IncludeItemTypes' => 'Episode,Movie,Series',
                        ],
                        $opts['query'] ?? []
                    ),
                )
            );

            $this->logger->debug(sprintf('%s: Requesting metadata for #\'%s\'.', $this->name, $id), ['url' => $url]);

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
                        '%s: Request for #\'%s\' metadata responded with unexpected http status code \'%d\'.',
                        $this->name,
                        $id,
                        $response->getStatusCode()
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
        } catch (ExceptionInterface|JsonException $e) {
            throw new RuntimeException(get_class($e) . ': ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @throws Throwable
     */
    public function getLibrary(string|int $id, array $opts = []): Generator
    {
        $this->checkConfig();

        $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
            http_build_query(
                [
                    'Recursive' => 'false',
                    'enableUserData' => 'false',
                    'enableImages' => 'false',
                ]
            )
        );

        $this->logger->debug(sprintf('%s: Requesting list of backend libraries.', $this->name), [
            'url' => $url
        ]);

        $response = $this->http->request('GET', (string)$url, $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(
                sprintf(
                    '%s: Get libraries list request responded with unexpected http status code \'%d\'.',
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

        $type = null;
        $found = false;

        foreach (ag($json, 'Items', []) as $section) {
            if ((string)ag($section, 'Id') !== (string)$id) {
                continue;
            }
            $found = true;
            $type = ag($section, 'CollectionType', 'unknown');
            break;
        }

        if (false === $found) {
            throw new RuntimeException(sprintf('%s: library id \'%s\' not found.', $this->name, $id));
        }

        if ('movies' !== $type && 'tvshows' !== $type) {
            throw new RuntimeException(sprintf('%s: Library id \'%s\' is of unsupported type.', $this->name, $id));
        }

        $type = $type === 'movies' ? iFace::TYPE_MOVIE : 'Show';

        $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
            http_build_query(
                [
                    'parentId' => $id,
                    'enableUserData' => 'false',
                    'enableImages' => 'false',
                    'ExcludeLocationTypes' => 'Virtual',
                    'include' => 'Series,Movie',
                ]
            )
        );

        $this->logger->debug(sprintf('%s: Sending get library content for id \'%s\'.', $this->name, $id), [
            'url' => $url
        ]);

        $response = $this->http->request('GET', (string)$url, $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(
                sprintf(
                    '%s: Request to get library content for id \'%s\' responded with unexpected http status code \'%d\'.',
                    $this->name,
                    $id,
                    $response->getStatusCode()
                )
            );
        }

        $handleRequest = function (string $type, array $item) use ($opts): array {
            $url = $this->url->withPath(sprintf('/Users/%s/items/%s', $this->user, ag($item, 'Id')));

            $this->logger->debug(
                sprintf('%s: Processing %s \'%s\'.', $this->name, $type, ag($item, 'Name')),
                [
                    'url' => (string)$url,
                ]
            );

            $possibleTitlesList = ['Name', 'OriginalTitle', 'SortName', 'ForcedSortName'];

            $year = ag($item, 'ProductionYear', null);

            $metadata = [
                'id' => ag($item, 'Id'),
                'type' => ucfirst($type),
                'url' => [(string)$url],
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

            if (null !== ($path = ag($item, 'Path'))) {
                $metadata['match']['paths'][] = [
                    'full' => $path,
                    'short' => basename($path),
                ];

                if (ag($item, 'Type') === 'Movie') {
                    if (false === str_starts_with(basename($path), basename(dirname($path)))) {
                        $metadata['match']['paths'][] = [
                            'full' => $path,
                            'short' => basename($path),
                        ];
                    }
                }
            }

            if (null !== ($providerIds = ag($item, 'ProviderIds'))) {
                foreach ($providerIds as $key => $val) {
                    $metadata['guids'][] = $key . '://' . $val;
                }
            }

            if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                $metadata['raw'] = $item;
            }

            return $metadata;
        };

        $it = Items::fromIterable(
            httpClientChunks($this->http->stream($response)),
            [
                'pointer' => '/Items',
                'decoder' => new ErrorWrappingDecoder(
                    new ExtJsonDecoder(assoc: true, options: JSON_INVALID_UTF8_IGNORE)
                )
            ]
        );

        $requests = [];

        foreach ($it as $entity) {
            if ($entity instanceof DecodingError) {
                $this->logger->warning(
                    sprintf(
                        '%s: Failed to decode one of library id \'%s\' items. %s',
                        $this->name,
                        $id,
                        $entity->getErrorMessage()
                    ),
                    [
                        'payload' => $entity->getMalformedJson(),
                    ]
                );
                continue;
            }

            $url = $this->url->withPath(sprintf('/Users/%s/items/%s', $this->user, ag($entity, 'Id')));

            $this->logger->debug(sprintf('%s: get %s \'%s\' metadata.', $this->name, $type, ag($entity, 'Name')), [
                'url' => $url
            ]);

            $requests[] = $this->http->request(
                'GET',
                (string)$url,
                array_replace_recursive($this->getHeaders(), [
                    'user_data' => [
                        'id' => ag($entity, 'Id'),
                        'title' => ag($entity, 'Name'),
                        'type' => $type,
                    ]
                ])
            );
        }

        if (empty($requests)) {
            throw new RuntimeException('No requests were made as the library is empty.');
        }

        foreach ($requests as $response) {
            if (200 !== $response->getStatusCode()) {
                $this->logger->error(
                    sprintf(
                        '%s: Get metadata request for id \'%s\' responded with unexpected http status code \'%d\'.',
                        $this->name,
                        $id,
                        $response->getStatusCode()
                    )
                );
                continue;
            }

            yield $handleRequest(
                $response->getInfo('user_data')['type'],
                json_decode(
                    json:        $response->getContent(),
                    associative: true,
                    flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                )
            );
        }
    }

    public function listLibraries(array $opts = []): array
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
                $this->logger->warning(
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

            $builder = [
                'id' => $key,
                'title' => ag($section, 'Name', '???'),
                'type' => $type,
                'ignored' => null !== $ignoreIds && in_array($key, $ignoreIds),
                'supported' => 'movies' === $type || 'tvshows' === $type,
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
            ok: function (string $cName, string $type, string|int $id, UriInterface|string $url) use ($after, $mapper) {
                return function (ResponseInterface $response) use ($mapper, $cName, $type, $after, $id, $url) {
                    if (200 !== $response->getStatusCode()) {
                        $this->logger->error(
                            sprintf(
                                '%s: Request to \'%s\' responded with unexpected http status code \'%d\'.',
                                $this->name,
                                $cName,
                                $response->getStatusCode()
                            ),
                            [
                                'id' => $id,
                                'url' => (string)$url
                            ]
                        );
                        return;
                    }

                    try {
                        $this->logger->info(sprintf('%s: Parsing \'%s\' response.', $this->name, $cName));

                        $it = Items::fromIterable(
                            iterable: httpClientChunks(stream: $this->http->stream($response)),
                            options:  [
                                          'pointer' => '/Items',
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

                            $this->processImport(
                                mapper:  $mapper,
                                type:    $type,
                                library: $cName,
                                item:    $entity,
                                after:   $after,
                                opts:    ['library' => $id],
                            );
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
            error: function (string $cName, string $type, string|int $id, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('%s: Error encountered in \'%s\' request. %s', $this->name, $cName, $e->getMessage()),
                    [
                        'id' => $id,
                        'url' => $url,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                );
            },
            includeParent: true
        );
    }

    public function push(array $entities, QueueRequests $queue, DateTimeInterface|null $after = null): array
    {
        $this->checkConfig(true);

        $requests = [];
        $count = count($entities);

        foreach ($entities as $key => $entity) {
            if (true !== ($entity instanceof StateEntity)) {
                continue;
            }

            if (false === (bool)ag($this->options, Options::IGNORE_DATE, false)) {
                if (null !== $after && $after->getTimestamp() > $entity->updated) {
                    continue;
                }
            }

            $iName = $entity->getName();
            $metadata = ag($entity->metadata, $this->name, []);

            if (null === ag($metadata, iFace::COLUMN_ID, null)) {
                $this->logger->warning(sprintf('%s: Ignoring \'%s\'. No metadata relation map.', $this->name, $iName), [
                    'id' => $entity->id
                ]);
                continue;
            }

            try {
                $url = $this->url->withPath(sprintf('/Users/%s/items', $this->user))->withQuery(
                    http_build_query(
                        [
                            'ids' => ag($metadata, iFace::COLUMN_ID),
                            'Fields' => 'ProviderIds,DateCreated,OriginalTitle,SeasonUserData,DateLastSaved',
                            'enableUserData' => 'true',
                            'enableImages' => 'false',
                        ]
                    )
                );

                if ($count < 20) {
                    $this->logger->debug(sprintf('%s: Requesting \'%s\' state.', $this->name, $iName), [
                        'url' => $url
                    ]);
                }

                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'id' => $key,
                            'suid' => ag($metadata, iFace::COLUMN_ID),
                        ]
                    ])
                );
            } catch (Throwable $e) {
                $this->logger->error(sprintf('%s: %s', $this->name, $e->getMessage()), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                ]);
            }
        }

        foreach ($requests as $response) {
            try {
                if (null === ($id = ag($response->getInfo('user_data'), 'id'))) {
                    $this->logger->error(sprintf('%s: Unable to get item entity state.', $this->name));
                    continue;
                }

                $state = $entities[$id];

                assert($state instanceof iFace);

                switch ($response->getStatusCode()) {
                    case 200:
                        break;
                    case 404:
                        $this->logger->error(
                            sprintf(
                                '%s: Request to get \'%s\' metadata returned \'404 Not Found\' error.',
                                $this->name,
                                $state->getName(),

                            ), [
                                'id' => ag($response->getInfo('user_data'), 'suid', '??'),
                            ]
                        );
                        continue 2;
                    default:
                        $this->logger->error(
                            sprintf(
                                '%s: Request to get \'%s\' metadata responded with unexpected http status code \'%d\'.',
                                $this->name,
                                $state->getName(),
                                $response->getStatusCode()
                            )
                        );
                        continue 2;
                }

                $json = json_decode(
                    json:        $response->getContent(false),
                    associative: true,
                    flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                );

                $json = ag($json, 'Items', [])[0] ?? [];

                if (empty($json)) {
                    $this->logger->error(
                        sprintf('%s: Ignoring \'%s\'. Backend returned empty result.', $this->name, $state->getName())
                    );
                    continue;
                }

                $isWatched = (int)(bool)ag($json, 'UserData.Played', false);

                if ($state->watched === $isWatched) {
                    $this->logger->info(
                        sprintf('%s: Ignoring \'%s\'. Play state is identical.', $this->name, $state->getName())
                    );
                    continue;
                }

                if (false === (bool)ag($this->options, Options::IGNORE_DATE, false)) {
                    $isPlayed = true === (bool)ag($json, 'UserData.Played');
                    $date = ag($json, true === $isPlayed ? 'UserData.LastPlayedDate' : 'DateCreated');

                    if (null === $date) {
                        $this->logger->error(
                            sprintf(
                                '%s: Ignoring \'%s\'. No date is set on backend object.',
                                $this->name,
                                $state->getName()
                            ),
                            $json
                        );
                        continue;
                    }

                    $date = makeDate($date);

                    $timeExtra = (int)(ag($this->options, Options::EXPORT_ALLOWED_TIME_DIFF, 10));

                    if ($date->getTimestamp() >= ($timeExtra + $state->updated)) {
                        $this->logger->notice(
                            sprintf(
                                '%s: Ignoring \'%s\'. Record time is older than backend time.',
                                $this->name,
                                $state->getName()
                            ),
                            [
                                'record' => makeDate($state->updated),
                                'backend' => $date,
                                'time_difference' => $date->getTimestamp() - $state->updated,
                                'extra_margin' => [
                                    Options::EXPORT_ALLOWED_TIME_DIFF => $timeExtra,
                                ],
                            ]
                        );
                        continue;
                    }
                }

                $url = $this->url->withPath(sprintf('/Users/%s/PlayedItems/%s', $this->user, ag($json, 'Id')));

                $this->logger->debug(
                    sprintf(
                        '%s: Changing \'%s\' play state to \'%s\'.',
                        $this->name,
                        $state->getName(),
                        $state->isWatched() ? 'Played' : 'Unplayed',
                    ),
                    [
                        'backend' => $isWatched ? 'Played' : 'Unplayed',
                        'url' => $url,
                    ]
                );

                if (false === (bool)ag($this->options, Options::DRY_RUN, false)) {
                    $queue->add(
                        $this->http->request(
                            $state->isWatched() ? 'POST' : 'DELETE',
                            (string)$url,
                            array_replace_recursive(
                                $this->getHeaders(),
                                [
                                    'user_data' => [
                                        'itemName' => $state->getName(),
                                        'server' => $this->name,
                                        'state' => $state->isWatched() ? 'Played' : 'Unplayed',
                                    ],
                                ]
                            )
                        )
                    );
                }
            } catch (Throwable $e) {
                $this->logger->error(sprintf('%s: %s', $this->name, $e->getMessage()), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                ]);
            }
        }

        unset($requests);

        return [];
    }

    public function export(ImportInterface $mapper, QueueRequests $queue, DateTimeInterface|null $after = null): array
    {
        return $this->getLibraries(
            ok: function (string $cName, string $type, string|int $id, UriInterface|string $url) use (
                $mapper,
                $queue,
                $after
            ) {
                return function (ResponseInterface $response) use ($mapper, $queue, $cName, $type, $after, $id, $url) {
                    if (200 !== $response->getStatusCode()) {
                        $this->logger->error(
                            sprintf(
                                '%s: Request for \'%s\' responded with unexpected http status code (%d).',
                                $this->name,
                                $cName,
                                $response->getStatusCode()
                            ),
                            [
                                'id' => $id,
                                'url' => (string)$url,
                            ]
                        );
                        return;
                    }

                    try {
                        $this->logger->info(sprintf('%s: Parsing \'%s\' response.', $this->name, $cName));

                        $it = Items::fromIterable(
                            iterable: httpClientChunks(stream: $this->http->stream($response)),
                            options:  [
                                          'pointer' => '/Items',
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

                            $this->processExport(
                                mapper:  $mapper,
                                queue:   $queue,
                                type:    $type,
                                library: $cName,
                                item:    $entity,
                                after:   $after,
                                opts:    ['library' => $id],
                            );
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

                    $this->logger->info(sprintf('%s: Parsing \'%s\' response is complete.', $this->name, $cName));
                };
            },
            error: function (string $cName, string $type, string|int $id, UriInterface|string $url) {
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
                'url' => $url
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
                $key = (string)ag($section, 'Id');
                $title = trim((string)ag($section, 'Name', '???'));
                $type = ag($section, 'CollectionType', 'unknown');

                if ('tvshows' !== ag($section, 'CollectionType', 'unknown')) {
                    continue;
                }

                $cName = sprintf('(%s) - (%s:%s)', $title, 'show', $key);

                if (null !== $ignoreIds && in_array($key, $ignoreIds, true)) {
                    $this->logger->info(sprintf('%s: Skipping \'%s\'. Ignored by user config.', $this->name, $title), [
                        'id' => $key,
                        'type' => $type,
                    ]);
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

                $this->logger->debug(sprintf('%s: Requesting \'%s\' tv shows external ids.', $this->name, $cName), [
                    'url' => $url
                ]);

                try {
                    $promises[] = $this->http->request(
                        'GET',
                        (string)$url,
                        array_replace_recursive($this->getHeaders(), [
                            'user_data' => [
                                'ok' => $ok(cName: $cName, type: 'show', id: $key, url: $url),
                                'error' => $error(cName: $cName, type: 'show', id: $key, url: $url),
                            ]
                        ])
                    );
                } catch (ExceptionInterface $e) {
                    $this->logger->error(
                        sprintf(
                            '%s: Request for \'%s\' tv shows external ids has failed. %s',
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
            $title = trim((string)ag($section, 'Name', '???'));
            $type = ag($section, 'CollectionType', 'unknown');

            if ('movies' !== $type && 'tvshows' !== $type) {
                $unsupported++;
                $this->logger->info(sprintf('%s: Skipping \'%s\'. Unsupported type.', $this->name, $title), [
                    'id' => $key,
                    'type' => $type,
                ]);
                continue;
            }

            $type = $type === 'movies' ? iFace::TYPE_MOVIE : iFace::TYPE_EPISODE;
            $cName = sprintf('(%s) - (%s:%s)', $title, $type, $key);

            if (null !== $ignoreIds && true === in_array($key, $ignoreIds)) {
                $ignored++;
                $this->logger->info(sprintf('%s: Skipping \'%s\'. Ignored by user config.', $this->name, $title), [
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

            $this->logger->debug(sprintf('%s: Requesting \'%s\' media items.', $this->name, $cName), [
                'url' => $url
            ]);

            try {
                $promises[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'ok' => $ok(cName: $cName, type: $type, id: $key, url: $url),
                            'error' => $error(cName: $cName, type: $type, id: $key, url: $url),
                        ]
                    ])
                );
            } catch (ExceptionInterface $e) {
                $this->logger->error(
                    sprintf('%s: Request for \'%s\' media items has failed. %s', $this->name, $cName, $e->getMessage()),
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
            $this->logger->warning(sprintf('%s: No library requests were made.', $this->name), [
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
        array $item,
        DateTimeInterface|null $after = null,
        array $opts = [],
    ): void {
        try {
            if ('show' === $type) {
                $this->processShow($item, $library);
                return;
            }

            Data::increment($this->name, $type . '_total');
            Data::increment($this->name, $library . '_total');

            if (iFace::TYPE_MOVIE === $type) {
                $iName = sprintf(
                    '%s - [%s (%d)]',
                    $library,
                    ag($item, ['Name', 'OriginalTitle'], '??'),
                    ag($item, 'ProductionYear', 0000)
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - [%s - (%sx%s)]',
                        $library,
                        ag($item, 'SeriesName', '??'),
                        str_pad((string)ag($item, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)ag($item, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                    )
                );
            }

            if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
                $this->logger->debug(sprintf('%s: Processing \'%s\' Payload.', $this->name, $iName), [
                    'payload' => $item
                ]);
            }

            $isPlayed = true === (bool)ag($item, 'UserData.Played');

            if (null === ag($item, true === $isPlayed ? 'UserData.LastPlayedDate' : 'DateCreated')) {
                $this->logger->warning(
                    sprintf('%s: Ignoring \'%s\'. Date is not set on backend object.', $this->name, $iName),
                    [
                        'payload' => $item,
                    ]
                );
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $entity = $this->createEntity(item: $item, type: $type, opts: $opts);

            if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
                if (true === (bool)Config::get('debug.import')) {
                    $name = Config::get('tmpDir') . '/debug/' . $this->name . '.' . ag($item, 'Id') . '.json';

                    if (!file_exists($name)) {
                        file_put_contents(
                            filename: $name,
                            data:     json_encode(
                                          value: $item,
                                          flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE
                                      )
                        );
                    }
                }

                $providerIds = (array)ag($item, 'ProviderIds', []);

                $message = sprintf('%s: Ignoring \'%s\'. No valid/supported external ids.', $this->name, $iName);

                if (empty($providerIds)) {
                    $message .= sprintf(' Most likely unmatched %s.', $entity->type);
                }

                $context = [
                    'guids' => !empty($providerIds) ? $providerIds : 'None'
                ];

                if (true === (bool)ag($this->options, Options::DEBUG_TRACE, false)) {
                    $context['entity'] = $entity->getAll();
                    $context['payload'] = json_decode(
                        json:        json_encode(value: $item),
                        associative: true,
                        flags:       JSON_INVALID_UTF8_IGNORE
                    );
                }

                $this->logger->info($message, $context);

                Data::increment($this->name, $type . '_ignored_no_supported_guid');

                return;
            }

            $mapper->add(
                bucket: $this->name,
                name:   $this->name . ' - ' . $iName,
                entity: $entity,
                opts:   ['after' => $after]
            );
        } catch (Throwable $e) {
            $this->logger->error(sprintf('%s: %s', $this->name, $e->getMessage()), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
        }
    }

    protected function processExport(
        ImportInterface $mapper,
        QueueRequests $queue,
        string $type,
        string $library,
        array $item,
        DateTimeInterface|null $after = null,
        array $opts = []
    ): void {
        Data::increment($this->name, $type . '_total');

        try {
            if (iFace::TYPE_MOVIE === $type) {
                $iName = sprintf(
                    '%s - [%s (%d)]',
                    $library,
                    ag($item, ['Name', 'OriginalTitle', '??']),
                    ag($item, 'ProductionYear', 0000)
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - [%s - (%sx%s)]',
                        $library,
                        ag($item, ['SeriesName', 'OriginalTitle'], '??'),
                        str_pad((string)ag($item, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)ag($item, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                    )
                );
            }

            $isPlayed = true === (bool)ag($item, 'UserData.Played');

            if (null === ag($item, true === $isPlayed ? 'UserData.LastPlayedDate' : 'DateCreated')) {
                $this->logger->notice(
                    sprintf('%s: Ignoring \'%s\'. Date is not set on backend object.', $this->name, $iName),
                    [
                        'payload' => $item,
                    ]
                );
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $rItem = $this->createEntity(item: $item, type: $type, opts: $opts);

            if (!$rItem->hasGuids() && !$rItem->hasRelativeGuid()) {
                $providerIds = (array)ag($item, 'ProviderIds', []);

                $message = sprintf('%s: Ignoring \'%s\'. No valid/supported external ids.', $this->name, $iName);

                if (empty($providerIds)) {
                    $message .= sprintf(' Most likely unmatched %s.', $rItem->type);
                }

                $this->logger->info($message, ['guids' => !empty($providerIds) ? $providerIds : 'None']);
                Data::increment($this->name, $type . '_ignored_no_supported_guid');
                return;
            }

            if (false === ag($this->options, Options::IGNORE_DATE, false)) {
                if (null !== $after && $rItem->updated >= $after->getTimestamp()) {
                    $this->logger->debug(
                        sprintf(
                            '%s: Ignoring \'%s\'. Backend reported date is equal or newer than last sync date.',
                            $this->name,
                            $iName
                        )
                    );
                    Data::increment($this->name, $type . '_ignored_date_is_equal_or_higher');
                    return;
                }
            }

            if (null === ($entity = $mapper->get($rItem))) {
                $this->logger->info(
                    sprintf('%s: Ignoring \'%s\'. Item is not imported.', $this->name, $iName),
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
                        sprintf(
                            '%s: Ignoring \'%s\'. Record date is newer or equal to backend item.',
                            $this->name,
                            $iName
                        ),
                        [
                            'backend' => makeDate($entity->updated),
                            'remote' => makeDate($rItem->updated),
                        ]
                    );
                    Data::increment($this->name, $type . '_ignored_date_is_newer');
                    return;
                }
            }

            $url = $this->url->withPath(sprintf('/Users/%s/PlayedItems/%s', $this->user, ag($item, 'Id')));

            $this->logger->debug(
                sprintf(
                    '%s: Changing \'%s\' play state to \'%s\'.',
                    $this->name,
                    $iName,
                    $entity->isWatched() ? 'Played' : 'Unplayed',
                ),
                [
                    'backend' => $rItem->isWatched() ? 'Played' : 'Unplayed',
                    'method' => $entity->isWatched() ? 'POST' : 'DELETE',
                    'url' => $url,
                ]
            );

            if (false === (bool)ag($this->options, Options::DRY_RUN, false)) {
                $queue->add(
                    request: $this->http->request(
                               $entity->isWatched() ? 'POST' : 'DELETE',
                               (string)$url,
                               array_replace_recursive($this->getHeaders(), [
                                   'user_data' => [
                                       'itemName' => $iName,
                                       'server' => $this->name,
                                       'state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                   ],
                               ])
                           )
                );
            }
        } catch (Throwable $e) {
            $this->logger->error(sprintf('%s: %s', $this->name, $e->getMessage()), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
        }
    }

    protected function processShow(array $item, string $library): void
    {
        $iName = sprintf(
            '%s - [%s (%d)]',
            $library,
            ag($item, ['Name', 'OriginalTitle'], '??'),
            ag($item, 'ProductionYear', 0000)
        );

        if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
            $this->logger->debug(sprintf('%s: Processing \'%s\' Payload.', $this->name, $iName), [
                'payload' => $item,
            ]);
        }

        $providersId = (array)ag($item, 'ProviderIds', []);

        if (!$this->hasSupportedIds($providersId)) {
            $message = sprintf('%s: Ignoring \'%s\'. No valid/supported external ids.', $this->name, $iName);

            if (empty($providersId)) {
                $message .= ' Most likely unmatched TV show.';
            }

            $this->logger->info($message, ['guids' => empty($providersId) ? 'None' : $providersId]);

            return;
        }

        $this->cache['shows'][ag($item, 'Id')] = Guid::fromArray($this->getGuids($providersId))->getAll();
    }

    protected function getGuids(array $ids): array
    {
        $guid = [];

        foreach (array_change_key_case($ids, CASE_LOWER) as $key => $value) {
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
        foreach (array_change_key_case($ids, CASE_LOWER) as $key => $value) {
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

    protected function createEntity(array $item, string $type, array $opts = []): StateEntity
    {
        // -- Handle watched/updated column in a special way to support mark as unplayed.
        if (null !== ($opts['override'][iFace::COLUMN_WATCHED] ?? null)) {
            $isPlayed = (bool)$opts['override'][iFace::COLUMN_WATCHED];
            $date = $opts['override'][iFace::COLUMN_UPDATED] ?? ag($item, 'DateCreated');
        } else {
            $isPlayed = (bool)ag($item, 'UserData.Played', false);
            $date = ag($item, true === $isPlayed ? ['UserData.LastPlayedDate', 'DateCreated'] : 'DateCreated');
        }

        if (null === $date) {
            throw new RuntimeException('No date was set on object.');
        }

        $guids = $this->getGuids(ag($item, 'ProviderIds', []));
        $guids += Guid::makeVirtualGuid($this->name, (string)ag($item, 'Id'));

        $builder = [
            iFace::COLUMN_TYPE => $type,
            iFace::COLUMN_UPDATED => makeDate($date)->getTimestamp(),
            iFace::COLUMN_WATCHED => (int)$isPlayed,
            iFace::COLUMN_VIA => $this->name,
            iFace::COLUMN_TITLE => ag($item, ['Name', 'OriginalTitle'], '??'),
            iFace::COLUMN_GUIDS => $guids,
            iFace::COLUMN_META_DATA => [
                $this->name => [
                    iFace::COLUMN_ID => (string)ag($item, 'Id'),
                    iFace::COLUMN_TYPE => $type,
                    iFace::COLUMN_WATCHED => true === $isPlayed ? '1' : '0',
                    iFace::COLUMN_VIA => $this->name,
                    iFace::COLUMN_TITLE => ag($item, ['Name', 'OriginalTitle'], '??'),
                    iFace::COLUMN_GUIDS => array_change_key_case((array)ag($item, 'ProviderIds', []), CASE_LOWER),
                    iFace::COLUMN_META_DATA_ADDED_AT => (string)makeDate(ag($item, 'DateCreated'))->getTimestamp(),
                ],
            ],
            iFace::COLUMN_EXTRA => [],
        ];

        $metadata = &$builder[iFace::COLUMN_META_DATA][$this->name];
        $metadataExtra = &$metadata[iFace::COLUMN_META_DATA_EXTRA];

        // -- jellyfin/emby API does not provide library ID.
        if (null !== ($library = $opts['library'] ?? null)) {
            $metadata[iFace::COLUMN_META_LIBRARY] = (string)$library;
        }

        if (iFace::TYPE_EPISODE === $type) {
            $builder[iFace::COLUMN_SEASON] = ag($item, 'ParentIndexNumber', 0);
            $builder[iFace::COLUMN_EPISODE] = ag($item, 'IndexNumber', 0);

            if (null !== ($parentId = ag($item, 'SeriesId'))) {
                $metadata[iFace::COLUMN_META_SHOW] = (string)$parentId;
            }

            $metadata[iFace::COLUMN_TITLE] = ag($item, 'SeriesName', '??');
            $metadata[iFace::COLUMN_SEASON] = (string)$builder[iFace::COLUMN_SEASON];
            $metadata[iFace::COLUMN_EPISODE] = (string)$builder[iFace::COLUMN_EPISODE];

            $metadataExtra[iFace::COLUMN_META_DATA_EXTRA_TITLE] = $builder[iFace::COLUMN_TITLE];
            $builder[iFace::COLUMN_TITLE] = $metadata[iFace::COLUMN_TITLE];

            if (null !== $parentId) {
                $builder[iFace::COLUMN_PARENT] = $this->getEpisodeParent($parentId, '');
                $metadata[iFace::COLUMN_PARENT] = $builder[iFace::COLUMN_PARENT];
            }
        }

        if (null !== ($mediaYear = ag($item, 'ProductionYear')) && !empty($metadata)) {
            $builder[iFace::COLUMN_YEAR] = (int)$mediaYear;
            $metadata[iFace::COLUMN_YEAR] = (string)$mediaYear;
        }

        if (null !== ($PremieredAt = ag($item, 'PremiereDate'))) {
            $metadataExtra[iFace::COLUMN_META_DATA_EXTRA_DATE] = makeDate($PremieredAt)->format('Y-m-d');
        }

        if (true === $isPlayed) {
            $metadata[iFace::COLUMN_META_DATA_PLAYED_AT] = (string)makeDate($date)->getTimestamp();
        }

        unset($metadata, $metadataExtra);

        if (null !== ($opts['override'] ?? null)) {
            $builder = array_replace_recursive($builder, $opts['override'] ?? []);
        }

        return Container::get(iFace::class)::fromArray($builder);
    }

    protected function getEpisodeParent(int|string $id): array
    {
        if (array_key_exists($id, $this->cache['shows'] ?? [])) {
            return $this->cache['shows'][$id];
        }

        try {
            $url = (string)$this->url->withPath(sprintf('/Users/%s/items/' . $id, $this->user))->withQuery(
                http_build_query(
                    [
                        'Fields' => 'ProviderIds'
                    ]
                )
            );

            $response = $this->http->request('GET', $url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                $this->cache['shows'][$id] = [];
                return [];
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            if (null === ($itemType = ag($json, 'Type')) || 'Series' !== $itemType) {
                $this->cache['shows'][$id] = [];
                return [];
            }

            $providersId = (array)ag($json, 'ProviderIds', []);

            if (!$this->hasSupportedIds($providersId)) {
                $this->cache['shows'][$id] = [];
                return [];
            }

            $this->cache['shows'][$id] = Guid::fromArray($this->getGuids($providersId))->getAll();

            return $this->cache['shows'][$id];
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
                'url' => $url ?? null,
            ]);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('%s: Unable to decode \'%s\' JSON response. %s', $this->name, $id, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'url' => $url ?? null,
                ]
            );
            return [];
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('%s: Failed to handle \'%s\' response. %s', $this->name, $id, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'url' => $url ?? null,
                ]
            );
            return [];
        }
    }
}
