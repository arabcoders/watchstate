<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Backends\Jellyfin\Action\InspectRequest;
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

    protected const TYPE_MOVIE = 'Movie';
    protected const TYPE_SHOW = 'Series';
    protected const TYPE_EPISODE = 'Episode';

    protected const TYPE_MAPPER = [
        self::TYPE_SHOW => iFace::TYPE_SHOW,
        self::TYPE_MOVIE => iFace::TYPE_MOVIE,
        self::TYPE_EPISODE => iFace::TYPE_EPISODE,
    ];

    protected const COLLECTION_TYPE_SHOWS = 'tvshows';
    protected const COLLECTION_TYPE_MOVIES = 'movies';

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

    protected const FIELDS = [
        'ProviderIds',
        'DateCreated',
        'OriginalTitle',
        'SeasonUserData',
        'DateLastSaved',
        'PremiereDate',
        'ProductionYear',
        'Path',
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

        $this->logger->debug('Requesting [%(backend)] unique identifier.', [
            'backend' => $this->getName(),
            'url' => $url,
        ]);

        $response = $this->http->request('GET', (string)$url, $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            $this->logger->error(
                'Request for [%(backend)] unique identifier returned with unexpected [%(status_code)] status code.',
                [
                    'backend' => $this->getName(),
                    'status_code' => $response->getStatusCode(),
                ]
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
                    'Request for [%s] users list returned with unexpected [%s] status code.',
                    $this->getName(),
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
        return (new InspectRequest(logger: $this->logger))(request: $request);
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

        $type = strtolower($type);

        try {
            $isPlayed = (bool)ag($json, 'Played');
            $lastPlayedAt = true === $isPlayed ? ag($json, 'LastPlayedDate') : null;

            $fields = [
                iFace::COLUMN_WATCHED => (int)$isPlayed,
                iFace::COLUMN_META_DATA => [
                    $this->getName() => [
                        iFace::COLUMN_WATCHED => true === $isPlayed ? '1' : '0',
                    ]
                ],
                iFace::COLUMN_EXTRA => [
                    $this->getName() => [
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
                        $this->getName() => [
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
                $guids += Guid::makeVirtualGuid($this->getName(), (string)$id);
                $fields[iFace::COLUMN_GUIDS] = $guids;
                $fields[iFace::COLUMN_META_DATA][$this->getName()][iFace::COLUMN_GUIDS] = $fields[iFace::COLUMN_GUIDS];
            }

            $entity = $this->createEntity(
                item: $this->getMetadata(id: $id),
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
        $this->checkConfig(true);

        try {
            $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
                http_build_query(
                    array_replace_recursive(
                        [
                            'searchTerm' => $query,
                            'limit' => $limit,
                            'recursive' => 'true',
                            'fields' => implode(',', self::FIELDS),
                            'enableUserData' => 'true',
                            'enableImages' => 'false',
                            'includeItemTypes' => 'Episode,Movie,Series',
                        ],
                        $opts['query'] ?? []
                    )
                )
            );

            $this->logger->debug('Searching for [%(query)] in [%(backend)].', [
                'backend' => $this->getName(),
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
                            'recursive' => 'false',
                            'fields' => implode(',', self::FIELDS),
                            'enableUserData' => 'true',
                            'enableImages' => 'false',
                            'includeItemTypes' => 'Episode,Movie,Series',
                        ],
                        $opts['query'] ?? []
                    ),
                )
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
                        $this->getName(),
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
        } catch (ExceptionInterface|JsonException $e) {
            throw new RuntimeException(sprintf('%s: %s', $this->getName(), $e->getMessage()), previous: $e);
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
                    'recursive' => 'false',
                    'enableUserData' => 'false',
                    'enableImages' => 'false',
                    'fields' => implode(',', self::FIELDS),
                ]
            )
        );

        $this->logger->debug('Requesting [%(backend)] libraries.', [
            'backend' => $this->getName(),
            'url' => $url
        ]);

        $response = $this->http->request('GET', (string)$url, $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(
                sprintf(
                    'Request for [%s] libraries returned with unexpected [%s] status code.',
                    $this->getName(),
                    $response->getStatusCode(),
                )
            );
        }

        $json = json_decode(
            json:        $response->getContent(),
            associative: true,
            flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        $context = [];
        $found = false;

        foreach (ag($json, 'Items', []) as $section) {
            if ((string)ag($section, 'Id') !== (string)$id) {
                continue;
            }
            $found = true;
            $context = [
                'library' => [
                    'id' => ag($section, 'Id'),
                    'type' => ag($section, 'CollectionType', 'unknown'),
                    'title' => ag($section, 'Name', '??'),
                ],
            ];
            break;
        }

        if (false === $found) {
            throw new RuntimeException(
                sprintf('The response from [%s] does not contain library with id of [%s].', $this->getName(), $id)
            );
        }

        if (true !== in_array(ag($context, 'library.type'), ['tvshows', 'movies'])) {
            throw new RuntimeException(
                sprintf(
                    'The requested [%s] library [%s] is of [%s] type. Which is not supported type.',
                    $this->getName(),
                    ag($context, 'library.title', $id),
                    ag($context, 'library.type')
                )
            );
        }

        $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
            http_build_query(
                [
                    'parentId' => $id,
                    'enableUserData' => 'false',
                    'enableImages' => 'false',
                    'excludeLocationTypes' => 'Virtual',
                    'include' => 'Series,Movie',
                    'fields' => implode(',', self::FIELDS)
                ]
            )
        );

        $context['library']['url'] = (string)$url;

        $this->logger->debug('Requesting [%(backend)] library [%(library.title)] content.', [
            'backend' => $this->getName(),
            ...$context,
        ]);

        $response = $this->http->request('GET', (string)$url, $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(
                sprintf(
                    'Request for [%s] library [%s] content returned with unexpected [%s] status code.',
                    $this->getName(),
                    ag($context, 'library.title', $id),
                    $response->getStatusCode(),
                )
            );
        }

        $handleRequest = $opts['handler'] ?? function (array $item, array $context = []) use ($opts): array {
                $url = $this->url->withPath(sprintf('/Users/%s/items/%s', $this->user, ag($item, 'Id')));
                $possibleTitlesList = ['Name', 'OriginalTitle', 'SortName', 'ForcedSortName'];

                $data = [
                    'backend' => $this->getName(),
                    ...$context,
                ];

                if (true === ag($this->options, Options::DEBUG_TRACE)) {
                    $data['trace'] = $item;
                }

                $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title) (%(item.year))].', $data);

                $metadata = [
                    'id' => ag($item, 'Id'),
                    'type' => ucfirst(ag($item, 'Type', 'unknown')),
                    'url' => [(string)$url],
                    'title' => ag($item, $possibleTitlesList, '??'),
                    'year' => ag($item, 'ProductionYear'),
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
            iterable: httpClientChunks($this->http->stream($response)),
            options:  [
                          'pointer' => '/Items',
                          'decoder' => new ErrorWrappingDecoder(
                              new ExtJsonDecoder(assoc: true, options: JSON_INVALID_UTF8_IGNORE)
                          )
                      ]
        );

        foreach ($it as $entity) {
            if ($entity instanceof DecodingError) {
                $this->logger->warning(
                    'Failed to decode one item of [%(backend)] library [%(library.title)] content.',
                    [
                        'backend' => $this->getName(),
                        ...$context,
                        'error' => [
                            'message' => $entity->getErrorMessage(),
                            'body' => $entity->getMalformedJson(),
                        ],
                    ]
                );
                continue;
            }


            $url = $this->url->withPath(sprintf('/Users/%s/items/%s', $this->user, ag($entity, 'Id')));

            $context['item'] = [
                'id' => ag($entity, 'Id'),
                'title' => ag($entity, ['Name', 'OriginalTitle', 'SortName', 'ForcedSortName'], '??'),
                'year' => ag($entity, 'ProductionYear', '0000'),
                'type' => ag($entity, 'Type'),
                'url' => (string)$url,
            ];

            yield $handleRequest(item: $entity, context: $context);
        }
    }

    public function listLibraries(array $opts = []): array
    {
        $this->checkConfig(true);

        try {
            $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
                http_build_query(
                    [
                        'recursive' => 'false',
                        'fields' => implode(',', self::FIELDS),
                        'enableUserData' => 'true',
                        'enableImages' => 'false',
                    ]
                )
            );

            $this->logger->debug('Requesting [%(backend)] libraries.', [
                'backend' => $this->getName(),
                'url' => $url
            ]);

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                $this->logger->error(
                    'Request for [%(backend)] libraries returned with unexpected [%(status_code)] status code.',
                    [
                        'backend' => $this->getName(),
                        'status_code' => $response->getStatusCode(),
                    ]
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
                $this->logger->warning('Request for [%(backend)] libraries returned empty list.', [
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
            $key = (string)ag($section, 'Id');
            $type = ag($section, 'CollectionType', 'unknown');

            $builder = [
                'id' => $key,
                'title' => ag($section, 'Name', '???'),
                'type' => $type,
                'ignored' => null !== $ignoreIds && in_array($key, $ignoreIds),
                'supported' => in_array($type, [self::COLLECTION_TYPE_MOVIES, self::COLLECTION_TYPE_SHOWS]),
            ];

            if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                $builder['raw'] = $section;
            }

            $list[] = $builder;
        }

        return $list;
    }

    public function push(array $entities, QueueRequests $queue, DateTimeInterface|null $after = null): array
    {
        $this->checkConfig(true);

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

            $metadata = $entity->getMetadata($this->getName());

            $context = [
                'item' => [
                    'id' => $entity->id,
                    'type' => $entity->type,
                    'title' => $entity->getName(),
                ],
            ];

            if (null === ag($metadata, iFace::COLUMN_ID, null)) {
                $this->logger->warning(
                    'Ignoring [%(item.title)] for [%(backend)] no backend metadata was found.',
                    [
                        'backend' => $this->getName(),
                        ...$context,
                    ]
                );
                continue;
            }

            $context['remote']['id'] = ag($metadata, iFace::COLUMN_ID);

            try {
                $url = $this->url->withPath(sprintf('/Users/%s/items', $this->user))->withQuery(
                    http_build_query(
                        [
                            'ids' => ag($metadata, iFace::COLUMN_ID),
                            'fields' => implode(',', self::FIELDS),
                            'enableUserData' => 'true',
                            'enableImages' => 'false',
                        ]
                    )
                );

                $context['remote']['url'] = (string)$url;

                $this->logger->debug('Requesting [%(backend)] %(item.type) [%(item.title)] play state.', [
                    'backend' => $this->getName(),
                    ...$context,
                ]);

                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'id' => $key,
                            'context' => $context,
                        ]
                    ])
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    'Unhandled exception was thrown during requesting of [%(backend)] %(item.type) [%(item.title)].',
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
            }
        }

        $context = null;

        foreach ($requests as $response) {
            $context = ag($response->getInfo('user_data'), 'context', []);

            try {
                if (null === ($id = ag($response->getInfo('user_data'), 'id'))) {
                    $this->logger->error('Unable to get entity object id.', [
                        'backend' => $this->getName(),
                        ...$context,
                    ]);
                    continue;
                }

                $entity = $entities[$id];

                assert($entity instanceof iFace);

                switch ($response->getStatusCode()) {
                    case 200:
                        break;
                    case 404:
                        $this->logger->warning(
                            'Request for [%(backend)] %(item.type) [%(item.title)] returned with 404 (Not Found) status code.',
                            [
                                'backend' => $this->getName(),
                                ...$context
                            ]
                        );
                        continue 2;
                    default:
                        $this->logger->error(
                            'Request for [%(backend)] %(item.type) [%(item.title)] returned with unexpected [%(status_code)] status code.',
                            [
                                'backend' => $this->getName(),
                                'status_code' => $response->getStatusCode(),
                                ...$context
                            ]
                        );
                        continue 2;
                }

                $body = json_decode(
                    json:        $response->getContent(false),
                    associative: true,
                    flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                );

                $json = ag($body, 'Items', [])[0] ?? [];

                if (empty($json)) {
                    $this->logger->error(
                        'Ignoring [%(backend)] %(item.type) [%(item.title)]. responded with empty metadata.',
                        [
                            'backend' => $this->getName(),
                            ...$context,
                            'response' => [
                                'body' => $body,
                            ],
                        ]
                    );
                    continue;
                }

                $isWatched = (int)(bool)ag($json, 'UserData.Played', false);

                if ($entity->watched === $isWatched) {
                    $this->logger->info(
                        'Ignoring [%(backend)] %(item.type) [%(item.title)]. Play state is identical.',
                        [
                            'backend' => $this->getName(),
                            ...$context,
                        ]
                    );
                    continue;
                }

                if (false === (bool)ag($this->options, Options::IGNORE_DATE, false)) {
                    $dateKey = 1 === $isWatched ? 'UserData.LastPlayedDate' : 'DateCreated';
                    $date = ag($json, $dateKey);

                    if (null === $date) {
                        $this->logger->error(
                            'Ignoring [%(backend)] %(item.type) [%(item.title)]. No %(date_key) is set on backend object.',
                            [
                                'backend' => $this->getName(),
                                'date_key' => $dateKey,
                                ...$context,
                                'response' => [
                                    'body' => $body,
                                ],
                            ]
                        );
                        continue;
                    }

                    $date = makeDate($date);

                    $timeExtra = (int)(ag($this->options, Options::EXPORT_ALLOWED_TIME_DIFF, 10));

                    if ($date->getTimestamp() >= ($timeExtra + $entity->updated)) {
                        $this->logger->notice(
                            'Ignoring [%(backend)] %(item.type) [%(item.title)]. Storage date is older than backend date.',
                            [
                                'backend' => $this->getName(),
                                ...$context,
                                'comparison' => [
                                    'storage' => makeDate($entity->updated),
                                    'backend' => $date,
                                    'difference' => $date->getTimestamp() - $entity->updated,
                                    'extra_margin' => [
                                        Options::EXPORT_ALLOWED_TIME_DIFF => $timeExtra,
                                    ],
                                ],
                            ]
                        );
                        continue;
                    }
                }

                $url = $this->url->withPath(sprintf('/Users/%s/PlayedItems/%s', $this->user, ag($json, 'Id')));

                $context['remote']['url'] = $url;

                $this->logger->debug(
                    'Queuing request to change [%(backend)] %(item.type) [%(item.title)] play state to [%(play_state)].',
                    [
                        'backend' => $this->getName(),
                        'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                        ...$context,
                    ]
                );

                if (false === (bool)ag($this->options, Options::DRY_RUN, false)) {
                    $queue->add(
                        $this->http->request(
                            $entity->isWatched() ? 'POST' : 'DELETE',
                            (string)$url,
                            array_replace_recursive($this->getHeaders(), [
                                'user_data' => [
                                    'context' => $context + [
                                            'backend' => $this->getName(),
                                            'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                        ],
                                ],
                            ])
                        )
                    );
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    'Unhandled exception was thrown during handling of [%(backend)] %(item.type) [%(item.title)].',
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
            }
        }

        unset($requests);

        return [];
    }

    public function pull(ImportInterface $mapper, DateTimeInterface|null $after = null): array
    {
        return $this->getLibraries(
            ok: function (array $context = []) use ($after, $mapper) {
                return function (ResponseInterface $response) use ($mapper, $after, $context) {
                    if (200 !== $response->getStatusCode()) {
                        $this->logger->error(
                            'Request for [%(backend)] [%(library.title)] content returned with unexpected [%(status_code)] status code.',
                            [
                                'backend' => $this->getName(),
                                'status_code' => $response->getStatusCode(),
                                ...$context,
                            ]
                        );
                        return;
                    }

                    $start = makeDate();
                    $this->logger->info('Parsing [%(backend)] library [%(library.title)] response.', [
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
                                    'Failed to decode one item of [%(backend)] [%(library.title)] content.',
                                    [
                                        'backend' => $this->getName(),
                                        ...$context,
                                        'error' => [
                                            'message' => $entity->getErrorMessage(),
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
                            'No Items were found in [%(backend)] library [%(library.title)] response.',
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
                        $this->logger->error(
                            'Unhandled exception was thrown in parsing [%(backend)] library [%(library.title)] response.',
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
                    }

                    $end = makeDate();
                    $this->logger->info('Parsing [%(backend)] library [%(library.title)] response is complete.', [
                        'backend' => $this->getName(),
                        ...$context,
                        'time' => [
                            'start' => $start,
                            'end' => $end,
                            'duration' => number_format($end->getTimestamp() - $start->getTimestamp()),
                        ],
                    ]);
                };
            },
            error: function (array $context = []) {
                return fn(Throwable $e) => $this->logger->error(
                    'Unhandled Exception was thrown during [%(backend)] library [%(library.title)] request.',
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

    public function export(ImportInterface $mapper, QueueRequests $queue, DateTimeInterface|null $after = null): array
    {
        return $this->getLibraries(
            ok: function (array $context = []) use ($mapper, $queue, $after) {
                return function (ResponseInterface $response) use ($mapper, $queue, $after, $context) {
                    if (200 !== $response->getStatusCode()) {
                        $this->logger->error(
                            'Request for [%(backend)] [%(library.title)] content responded with unexpected [%(status_code)] status code.',
                            [
                                'backend' => $this->getName(),
                                'status_code' => $response->getStatusCode(),
                                ...$context,
                            ]
                        );
                        return;
                    }

                    $start = makeDate();
                    $this->logger->info('Parsing [%(backend)] library [%(library.title)] response.', [
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
                                    'Failed to decode one item of [%(backend)] [%(library.title)] content.',
                                    [
                                        'backend' => $this->getName(),
                                        ...$context,
                                        'error' => [
                                            'message' => $entity->getErrorMessage(),
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
                                opts:    ['after' => $after],
                            );
                        }
                    } catch (PathNotFoundException $e) {
                        $this->logger->error(
                            'No Items were found in [%(backend)] library [%(library.title)] response.',
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
                        $this->logger->error(
                            'Unhandled exception was thrown in parsing [%(backend)] library [%(library.title)] response.',
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
                    }

                    $end = makeDate();
                    $this->logger->info('Parsing [%(backend)] library [%(library.title)] response is complete.', [
                        'backend' => $this->getName(),
                        ...$context,
                        'time' => [
                            'start' => $start,
                            'end' => $end,
                            'duration' => number_format($end->getTimestamp() - $start->getTimestamp()),
                        ],
                    ]);
                };
            },
            error: function (array $context = []) {
                return fn(Throwable $e) => $this->logger->error(
                    'Unhandled Exception was thrown during [%(backend)] library [%(library.title)] request.',
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
            includeParent: false === count(ag($this->cache, 'shows', [])) > 1,
        );
    }

    public function __destruct()
    {
        if (true === (bool)ag($this->options, Options::DRY_RUN)) {
            return;
        }

        if (!empty($this->cacheKey) && !empty($this->cache) && true === $this->initialized) {
            try {
                $this->cacheIO->set($this->cacheKey, $this->cache, new DateInterval('P3D'));
            } catch (InvalidArgumentException) {
            }
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
                        'recursive' => 'false',
                        'enableUserData' => 'false',
                        'enableImages' => 'false',
                        'fields' => implode(',', self::FIELDS),
                    ]
                )
            );

            $this->logger->debug('Requesting [%(backend)] libraries.', [
                'backend' => $this->getName(),
                'url' => $url
            ]);

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                $this->logger->error(
                    'Request for [%(backend)] libraries returned with unexpected [%(status_code)] status code.',
                    [
                        'backend' => $this->getName(),
                        'status_code' => $response->getStatusCode(),
                    ]
                );
                Data::add($this->getName(), 'no_import_update', true);
                return [];
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            $listDirs = ag($json, 'Items', []);

            if (empty($listDirs)) {
                $this->logger->warning('Request for [%(backend)] libraries returned empty list.', [
                    'backend' => $this->getName(),
                    'context' => [
                        'body' => $json,
                    ]
                ]);
                Data::add($this->getName(), 'no_import_update', true);
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
            Data::add($this->getName(), 'no_import_update', true);
            return [];
        } catch (JsonException $e) {
            $this->logger->error('Request for [%(backend)] libraries returned with invalid body.', [
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage(),
                ],
            ]);
            Data::add($this->getName(), 'no_import_update', true);
            return [];
        }

        if (null !== ($ignoreIds = ag($this->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => (int)trim($v), explode(',', (string)$ignoreIds));
        }

        $promises = [];
        $ignored = $unsupported = 0;

        if (true === $includeParent) {
            foreach ($listDirs as $section) {
                $context = [
                    'library' => [
                        'id' => (string)ag($section, 'Id'),
                        'title' => ag($section, 'Name', '??'),
                        'type' => ag($section, 'CollectionType', 'unknown'),
                    ],
                ];

                if (self::COLLECTION_TYPE_SHOWS !== ag($context, 'library.type')) {
                    continue;
                }

                if (null !== $ignoreIds && in_array(ag($context, 'library.id'), $ignoreIds, true)) {
                    continue;
                }

                $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
                    http_build_query(
                        [
                            'parentId' => ag($context, 'library.id'),
                            'recursive' => 'false',
                            'enableUserData' => 'false',
                            'enableImages' => 'false',
                            'fields' => implode(',', self::FIELDS),
                            'excludeLocationTypes' => 'Virtual',
                        ]
                    )
                );

                $context['library']['url'] = (string)$url;

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
            $context = [
                'library' => [
                    'id' => (string)ag($section, 'Id'),
                    'title' => ag($section, 'Name', '??'),
                    'type' => ag($section, 'CollectionType', 'unknown'),
                ],
            ];

            if (null !== $ignoreIds && true === in_array(ag($context, 'library.id'), $ignoreIds)) {
                $ignored++;
                $this->logger->info('Ignoring [%(backend)] [%(library.title)]. Requested by user config.', [
                    'backend' => $this->getName(),
                    ...$context,
                ]);
                continue;
            }

            if (false === in_array(ag($context, 'library.type'), ['movies', 'tvshows'])) {
                $unsupported++;
                $this->logger->info(
                    'Ignoring [%(backend)] [%(library.title)]. Library type [%(library.type)] is not supported.',
                    [
                        'backend' => $this->getName(),
                        ...$context,
                    ]
                );
                continue;
            }

            $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
                http_build_query(
                    [
                        'parentId' => ag($context, 'library.id'),
                        'recursive' => 'true',
                        'enableUserData' => 'true',
                        'enableImages' => 'false',
                        'includeItemTypes' => 'Movie,Episode',
                        'fields' => implode(',', self::FIELDS),
                        'excludeLocationTypes' => 'Virtual',
                    ]
                )
            );

            $context['library']['url'] = (string)$url;

            $this->logger->debug('Requesting [%(backend)] [%(library.title)] content list.', [
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
                $this->logger->error('Requesting for [%(backend)] [%(library.title)] content list has failed.', [
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
            $this->logger->warning('No requests for [%(backend)] libraries were queued.', [
                'backend' => $this->getName(),
                'context' => [
                    'total' => count($listDirs),
                    'ignored' => $ignored,
                    'unsupported' => $unsupported,
                ],
            ]);
            Data::add($this->getName(), 'no_import_update', true);
            return [];
        }

        return $promises;
    }

    protected function processImport(ImportInterface $mapper, array $item, array $context = [], array $opts = []): void
    {
        try {
            if (self::TYPE_SHOW === ($type = ag($item, 'Type'))) {
                $this->processShow(item: $item, context: $context);
                return;
            }

            $type = self::TYPE_MAPPER[$type];

            Data::increment($this->getName(), $type . '_total');

            $context['item'] = [
                'id' => ag($item, 'Id'),
                'title' => match ($type) {
                    iFace::TYPE_MOVIE => sprintf(
                        '%s (%d)',
                        ag($item, ['Name', 'OriginalTitle'], '??'),
                        ag($item, 'ProductionYear', 0000)
                    ),
                    iFace::TYPE_EPISODE => trim(
                        sprintf(
                            '%s - (%sx%s)',
                            ag($item, 'SeriesName', '??'),
                            str_pad((string)ag($item, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                            str_pad((string)ag($item, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                        )
                    ),
                },
                'type' => ag($item, 'Type'),
            ];

            if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
                $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title)] payload.', [
                    'backend' => $this->getName(),
                    ...$context,
                    'response' => [
                        'body' => $item
                    ],
                ]);
            }

            $isPlayed = true === (bool)ag($item, 'UserData.Played');
            $dateKey = true === $isPlayed ? 'UserData.LastPlayedDate' : 'DateCreated';

            if (null === ag($item, $dateKey)) {
                $this->logger->debug('Ignoring [%(backend)] %(item.type) [%(item.title)]. No Date is set on object.', [
                    'backend' => $this->getName(),
                    'date_key' => $dateKey,
                    ...$context,
                    'response' => [
                        'body' => $item,
                    ],
                ]);

                Data::increment($this->getName(), $type . '_ignored_no_date_is_set');
                return;
            }

            $entity = $this->createEntity(
                item: $item,
                type: $type,
                opts: $opts + [
                          'library' => ag($context, 'library.id'),
                          'override' => [
                              iFace::COLUMN_EXTRA => [
                                  $this->getName() => [
                                      iFace::COLUMN_EXTRA_EVENT => 'task.import',
                                      iFace::COLUMN_EXTRA_DATE => makeDate('now'),
                                  ],
                              ],
                          ]
                      ],
            );

            if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
                if (true === (bool)Config::get('debug.import')) {
                    $name = Config::get('tmpDir') . '/debug/' . $this->getName() . '.' . ag($item, 'Id') . '.json';

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

                $providerIds = (array)ag($item, 'ProviderIds', []);

                $message = 'Ignoring [%(backend)] [%(item.title)]. No valid/supported external ids.';

                if (empty($providerIds)) {
                    $message .= ' Most likely unmatched %(item.type).';
                }

                $this->logger->info($message, [
                    'backend' => $this->getName(),
                    ...$context,
                    'context' => [
                        'guids' => !empty($providerIds) ? $providerIds : 'None'
                    ],
                ]);

                Data::increment($this->getName(), $type . '_ignored_no_supported_guid');
                return;
            }

            $mapper->add(entity: $entity, opts: [
                'after' => ag($opts, 'after'),
                Options::IMPORT_METADATA_ONLY => true === (bool)ag($this->options, Options::IMPORT_METADATA_ONLY),
            ]);
        } catch (Throwable $e) {
            $this->logger->error(
                'Unhandled exception was thrown during handling of [%(backend)] [%(library.title)] [%(item.title)] import.',
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
        }
    }

    protected function processExport(
        ImportInterface $mapper,
        QueueRequests $queue,
        array $item,
        array $context = [],
        array $opts = [],
    ): void {
        try {
            if (self::TYPE_SHOW === ($type = ag($item, 'Type'))) {
                $this->processShow(item: $item, context: $context);
                return;
            }

            $after = ag($opts, 'after');
            $type = self::TYPE_MAPPER[$type];

            Data::increment($this->getName(), $type . '_total');

            $context['item'] = [
                'id' => ag($item, 'Id'),
                'title' => match ($type) {
                    iFace::TYPE_MOVIE => sprintf(
                        '%s (%d)',
                        ag($item, ['Name', 'OriginalTitle'], '??'),
                        ag($item, 'ProductionYear', 0000)
                    ),
                    iFace::TYPE_EPISODE => trim(
                        sprintf(
                            '%s - (%sx%s)',
                            ag($item, 'SeriesName', '??'),
                            str_pad((string)ag($item, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                            str_pad((string)ag($item, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                        )
                    ),
                },
                'type' => $type,
            ];

            if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
                $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title)] payload.', [
                    'backend' => $this->getName(),
                    ...$context,
                    'response' => [
                        'body' => $item
                    ],
                ]);
            }

            $isPlayed = true === (bool)ag($item, 'UserData.Played');
            $dateKey = true === $isPlayed ? 'UserData.LastPlayedDate' : 'DateCreated';

            if (null === ag($item, $dateKey)) {
                $this->logger->debug('Ignoring [%(backend)] %(item.type) [%(item.title)]. No Date is set on object.', [
                    'backend' => $this->getName(),
                    'date_key' => $dateKey,
                    ...$context,
                    'response' => [
                        'body' => $item,
                    ],
                ]);

                Data::increment($this->getName(), $type . '_ignored_no_date_is_set');
                return;
            }

            $rItem = $this->createEntity(
                item: $item,
                type: $type,
                opts: array_replace_recursive($opts, ['library' => ag($context, 'library.id')])
            );

            if (!$rItem->hasGuids() && !$rItem->hasRelativeGuid()) {
                $providerIds = (array)ag($item, 'ProviderIds', []);

                $message = 'Ignoring [%(backend)] [%(item.title)]. No valid/supported external ids.';

                if (empty($providerIds)) {
                    $message .= ' Most likely unmatched %(item.type).';
                }

                $this->logger->info($message, [
                    'backend' => $this->getName(),
                    ...$context,
                    'context' => [
                        'guids' => !empty($providerIds) ? $providerIds : 'None'
                    ],
                ]);

                Data::increment($this->getName(), $type . '_ignored_no_supported_guid');
                return;
            }

            if (false === ag($this->options, Options::IGNORE_DATE, false)) {
                if (true === ($after instanceof DateTimeInterface) && $rItem->updated >= $after->getTimestamp()) {
                    $this->logger->debug(
                        'Ignoring [%(backend)] [%(item.title)]. Backend date is equal or newer than last sync date.',
                        [
                            'backend' => $this->getName(),
                            ...$context,
                            'comparison' => [
                                'lastSync' => makeDate($after),
                                'backend' => makeDate($rItem->updated),
                            ],
                        ]
                    );

                    Data::increment($this->getName(), $type . '_ignored_date_is_equal_or_higher');
                    return;
                }
            }

            if (null === ($entity = $mapper->get($rItem))) {
                $this->logger->warning('Ignoring [%(backend)] [%(item.title)]. %(item.type) Is not imported yet.', [
                    'backend' => $this->getName(),
                    ...$context,
                ]);
                Data::increment($this->getName(), $type . '_ignored_not_found_in_db');
                return;
            }

            if ($rItem->watched === $entity->watched) {
                if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
                    $this->logger->debug(
                        'Ignoring [%(backend)] [%(item.title)]. %(item.type) play state is identical.',
                        [
                            'backend' => $this->getName(),
                            ...$context,
                            'comparison' => [
                                'backend' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                'remote' => $rItem->isWatched() ? 'Played' : 'Unplayed',
                            ],
                        ]
                    );
                }

                Data::increment($this->getName(), $type . '_ignored_state_unchanged');
                return;
            }

            if (false === ag($this->options, Options::IGNORE_DATE, false) && $rItem->updated >= $entity->updated) {
                $this->logger->debug(
                    'Ignoring [%(backend)] [%(item.title)]. Backend date is equal or newer than storage date.',
                    [
                        'backend' => $this->getName(),
                        ...$context,
                        'comparison' => [
                            'storage' => makeDate($entity->updated),
                            'backend' => makeDate($rItem->updated),
                        ],
                    ]
                );

                Data::increment($this->getName(), $type . '_ignored_date_is_newer');
                return;
            }

            $url = $this->url->withPath(sprintf('/Users/%s/PlayedItems/%s', $this->user, ag($item, 'Id')));

            $context['item']['url'] = $url;

            $this->logger->debug(
                'Queuing Request to change [%(backend)] [%(item.title)] play state to [%(play_state)].',
                [
                    'backend' => $this->getName(),
                    'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                    ...$context,
                ]
            );

            if (false === (bool)ag($this->options, Options::DRY_RUN, false)) {
                $queue->add(
                    $this->http->request(
                        $entity->isWatched() ? 'POST' : 'DELETE',
                        (string)$url,
                        array_replace_recursive($this->getHeaders(), [
                            'user_data' => [
                                'context' => $context + [
                                        'backend' => $this->getName(),
                                        'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                    ],
                            ],
                        ])
                    )
                );
            }
        } catch (Throwable $e) {
            $this->logger->error(
                'Unhandled exception was thrown during handling of [%(backend)] [%(library.title)] [%(item.title)] export.',
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
        }
    }

    protected function processShow(array $item, array $context = []): void
    {
        $context['item'] = [
            'id' => ag($item, 'Id'),
            'title' => ag($item, ['Name', 'OriginalTitle'], '??'),
            'year' => ag($item, 'ProductionYear', null),
            'type' => ag($item, 'Type'),
        ];

        if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
            $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title) (%(item.year))] payload.', [
                'backend' => $this->getName(),
                ...$context,
                'response' => [
                    'body' => $item,
                ],
            ]);
        }

        $providersId = (array)ag($item, 'ProviderIds', []);

        if (!$this->hasSupportedIds($providersId)) {
            $message = 'Ignoring [%(backend)] [%(item.title)]. %(item.type) has no valid/supported external ids.';

            if (empty($providersId)) {
                $message .= ' Most likely unmatched %(item.type).';
            }

            $this->logger->info($message, [
                'backend' => $this->getName(),
                ...$context,
                'data' => [
                    'guids' => !empty($providersId) ? $providersId : 'None'
                ],
            ]);

            return;
        }

        $this->cache['shows'][ag($context, 'item.id')] = Guid::fromArray($this->getGuids($providersId))->getAll();
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
        $guids += Guid::makeVirtualGuid($this->getName(), (string)ag($item, 'Id'));

        $builder = [
            iFace::COLUMN_TYPE => $type,
            iFace::COLUMN_UPDATED => makeDate($date)->getTimestamp(),
            iFace::COLUMN_WATCHED => (int)$isPlayed,
            iFace::COLUMN_VIA => $this->getName(),
            iFace::COLUMN_TITLE => ag($item, ['Name', 'OriginalTitle'], '??'),
            iFace::COLUMN_GUIDS => $guids,
            iFace::COLUMN_META_DATA => [
                $this->getName() => [
                    iFace::COLUMN_ID => (string)ag($item, 'Id'),
                    iFace::COLUMN_TYPE => $type,
                    iFace::COLUMN_WATCHED => true === $isPlayed ? '1' : '0',
                    iFace::COLUMN_VIA => $this->getName(),
                    iFace::COLUMN_TITLE => ag($item, ['Name', 'OriginalTitle'], '??'),
                    iFace::COLUMN_GUIDS => array_change_key_case((array)ag($item, 'ProviderIds', []), CASE_LOWER),
                    iFace::COLUMN_META_DATA_ADDED_AT => (string)makeDate(ag($item, 'DateCreated'))->getTimestamp(),
                ],
            ],
            iFace::COLUMN_EXTRA => [],
        ];

        $metadata = &$builder[iFace::COLUMN_META_DATA][$this->getName()];
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
                $builder[iFace::COLUMN_PARENT] = $this->getEpisodeParent($parentId);
                $metadata[iFace::COLUMN_PARENT] = $builder[iFace::COLUMN_PARENT];
            }
        }

        if (null !== ($mediaYear = ag($item, 'ProductionYear')) && !empty($metadata)) {
            $builder[iFace::COLUMN_YEAR] = (int)$mediaYear;
            $metadata[iFace::COLUMN_YEAR] = (string)$mediaYear;
        }

        if (null !== ($mediaPath = ag($item, 'Path')) && !empty($mediaPath)) {
            $metadata[iFace::COLUMN_META_PATH] = (string)$mediaPath;
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

    protected function getEpisodeParent(int|string $id, array $context = []): array
    {
        if (array_key_exists($id, $this->cache['shows'] ?? [])) {
            return $this->cache['shows'][$id];
        }

        try {
            $url = (string)$this->url->withPath(sprintf('/Users/%s/items/' . $id, $this->user))->withQuery(
                http_build_query(
                    [
                        'fields' => implode(',', self::FIELDS),
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
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception was thrown during getEpisodeParent.', [
                'backend' => $this->getName(),
                ...$context,
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'message' => $e->getMessage(),
                ],
            ]);
            return [];
        }
    }
}
