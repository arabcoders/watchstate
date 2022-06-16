<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Backends\Common\Cache;
use App\Backends\Common\Context;
use App\Backends\Jellyfin\Action\InspectRequest;
use App\Backends\Jellyfin\Action\GetIdentifier;
use App\Backends\Jellyfin\Action\ParseWebhook;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Backends\Jellyfin\JellyfinClient;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use App\Libs\HttpException;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Options;
use App\Libs\QueueRequests;
use Closure;
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
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

class JellyfinServer implements ServerInterface
{
    use JellyfinActionTrait;

    public const NAME = 'JellyfinBackend';

    protected const COLLECTION_TYPE_SHOWS = 'tvshows';
    protected const COLLECTION_TYPE_MOVIES = 'movies';

    public const FIELDS = [
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

    protected string|int|null $uuid = null;

    protected Context|null $context = null;

    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
        protected Cache $cache,
        protected JellyfinGuid $guid,
    ) {
    }

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

        if (null !== ($options['emby'] ?? null)) {
            unset($options['emby']);
        }

        $cloned->options = $options;

        $cloned->context = new Context(
            clientName:     static::NAME,
            backendName:    $name,
            backendUrl:     $url,
            cache:          $this->cache->withData($cloned::NAME . '_' . $name, $options),
            backendId:      $uuid,
            backendToken:   $token,
            backendUser:    $userId,
            backendHeaders: $cloned->getHeaders(),
            trace:          true === ag($options, Options::DEBUG_TRACE),
            options:        $this->options
        );

        $cloned->guid = $this->guid->withContext($cloned->context);

        return $cloned;
    }

    public function getServerUUID(bool $forceRefresh = false): int|string|null
    {
        if (false === $forceRefresh && null !== $this->uuid) {
            return $this->uuid;
        }

        $response = Container::get(GetIdentifier::class)(context: $this->context);

        $this->uuid = $response->isSuccessful() ? $response->response : null;

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
                    $this->context->backendName,
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
        return $this->name ?? static::NAME;
    }

    public function processRequest(ServerRequestInterface $request, array $opts = []): ServerRequestInterface
    {
        $response = Container::get(InspectRequest::class)(context: $this->context, request: $request);

        return $response->isSuccessful() ? $response->response : $request;
    }

    public function parseWebhook(ServerRequestInterface $request): iFace
    {
        $response = Container::get(ParseWebhook::class)(
            context: $this->context,
            guid:    $this->guid,
            request: $request,
            opts:    $this->options
        );

        if (false === $response->isSuccessful()) {
            if ($response->hasError()) {
                $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
            }

            throw new HttpException(
                ag($response->extra, 'message', fn() => $response->error->format()),
                ag($response->extra, 'http_code', 400),
            );
        }

        return $response->response;
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
                'backend' => $this->context->backendName,
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
                        $this->context->backendName,
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
        return $this->getItemDetails(context: $this->context, id: $id, opts: $opts);
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
            'backend' => $this->context->backendName,
            'url' => $url
        ]);

        $response = $this->http->request('GET', (string)$url, $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(
                sprintf(
                    'Request for [%s] libraries returned with unexpected [%s] status code.',
                    $this->context->backendName,
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
                sprintf(
                    'The response from [%s] does not contain library with id of [%s].',
                    $this->context->backendName,
                    $id
                )
            );
        }

        if (true !== in_array(ag($context, 'library.type'), ['tvshows', 'movies'])) {
            throw new RuntimeException(
                sprintf(
                    'The requested [%s] library [%s] is of [%s] type. Which is not supported type.',
                    $this->context->backendName,
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
            'backend' => $this->context->backendName,
            ...$context,
        ]);

        $response = $this->http->request('GET', (string)$url, $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(
                sprintf(
                    'Request for [%s] library [%s] content returned with unexpected [%s] status code.',
                    $this->context->backendName,
                    ag($context, 'library.title', $id),
                    $response->getStatusCode(),
                )
            );
        }

        $handleRequest = $opts['handler'] ?? function (array $item, array $context = []) use ($opts): array {
                $url = $this->url->withPath(sprintf('/Users/%s/items/%s', $this->user, ag($item, 'Id')));
                $possibleTitlesList = ['Name', 'OriginalTitle', 'SortName', 'ForcedSortName'];

                $data = [
                    'backend' => $this->context->backendName,
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
                        'backend' => $this->context->backendName,
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
                'backend' => $this->context->backendName,
                'url' => $url
            ]);

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                $this->logger->error(
                    'Request for [%(backend)] libraries returned with unexpected [%(status_code)] status code.',
                    [
                        'backend' => $this->context->backendName,
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
                    'backend' => $this->context->backendName,
                    'context' => [
                        'body' => $json,
                    ]
                ]);
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error('Request for [%(backend)] libraries has failed.', [
                'backend' => $this->context->backendName,
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

            $metadata = $entity->getMetadata($this->context->backendName);

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
                        'backend' => $this->context->backendName,
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
                    'backend' => $this->context->backendName,
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
                        'backend' => $this->context->backendName,
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
                        'backend' => $this->context->backendName,
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
                                'backend' => $this->context->backendName,
                                ...$context
                            ]
                        );
                        continue 2;
                    default:
                        $this->logger->error(
                            'Request for [%(backend)] %(item.type) [%(item.title)] returned with unexpected [%(status_code)] status code.',
                            [
                                'backend' => $this->context->backendName,
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
                            'backend' => $this->context->backendName,
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
                            'backend' => $this->context->backendName,
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
                                'backend' => $this->context->backendName,
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
                                'backend' => $this->context->backendName,
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
                        'backend' => $this->context->backendName,
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
                                            'backend' => $this->context->backendName,
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
                        'backend' => $this->context->backendName,
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
                                'backend' => $this->context->backendName,
                                'status_code' => $response->getStatusCode(),
                                ...$context,
                            ]
                        );
                        return;
                    }

                    $start = makeDate();
                    $this->logger->info('Parsing [%(backend)] library [%(library.title)] response.', [
                        'backend' => $this->context->backendName,
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
                                        'backend' => $this->context->backendName,
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
                                'backend' => $this->context->backendName,
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
                                'backend' => $this->context->backendName,
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
                        'backend' => $this->context->backendName,
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
                        'backend' => $this->context->backendName,
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
                                'backend' => $this->context->backendName,
                                'status_code' => $response->getStatusCode(),
                                ...$context,
                            ]
                        );
                        return;
                    }

                    $start = makeDate();
                    $this->logger->info('Parsing [%(backend)] library [%(library.title)] response.', [
                        'backend' => $this->context->backendName,
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
                                        'backend' => $this->context->backendName,
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
                                'backend' => $this->context->backendName,
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
                                'backend' => $this->context->backendName,
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
                        'backend' => $this->context->backendName,
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
                        'backend' => $this->context->backendName,
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
            includeParent: false === count($this->context->cache->get(JellyfinClient::TYPE_SHOW, [])) > 1,
        );
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
                'backend' => $this->context->backendName,
                'url' => $url
            ]);

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                $this->logger->error(
                    'Request for [%(backend)] libraries returned with unexpected [%(status_code)] status code.',
                    [
                        'backend' => $this->context->backendName,
                        'status_code' => $response->getStatusCode(),
                    ]
                );
                Data::add($this->context->backendName, 'no_import_update', true);
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
                    'backend' => $this->context->backendName,
                    'context' => [
                        'body' => $json,
                    ]
                ]);
                Data::add($this->context->backendName, 'no_import_update', true);
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error('Request for [%(backend)] libraries has failed.', [
                'backend' => $this->context->backendName,
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'message' => $e->getMessage(),
                ],
            ]);
            Data::add($this->context->backendName, 'no_import_update', true);
            return [];
        } catch (JsonException $e) {
            $this->logger->error('Request for [%(backend)] libraries returned with invalid body.', [
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage(),
                ],
            ]);
            Data::add($this->context->backendName, 'no_import_update', true);
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
                    'backend' => $this->context->backendName,
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
                            'backend' => $this->context->backendName,
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
                    'backend' => $this->context->backendName,
                    ...$context,
                ]);
                continue;
            }

            if (false === in_array(ag($context, 'library.type'), ['movies', 'tvshows'])) {
                $unsupported++;
                $this->logger->info(
                    'Ignoring [%(backend)] [%(library.title)]. Library type [%(library.type)] is not supported.',
                    [
                        'backend' => $this->context->backendName,
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
                'backend' => $this->context->backendName,
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
                    'backend' => $this->context->backendName,
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
                'backend' => $this->context->backendName,
                'context' => [
                    'total' => count($listDirs),
                    'ignored' => $ignored,
                    'unsupported' => $unsupported,
                ],
            ]);
            Data::add($this->context->backendName, 'no_import_update', true);
            return [];
        }

        return $promises;
    }

    protected function processImport(ImportInterface $mapper, array $item, array $context = [], array $opts = []): void
    {
        try {
            if (JellyfinClient::TYPE_SHOW === ($type = ag($item, 'Type'))) {
                $this->processShow(item: $item, context: $context);
                return;
            }

            $type = $this->typeMapper[$type];

            Data::increment($this->context->backendName, $type . '_total');

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
                    'backend' => $this->context->backendName,
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
                    'backend' => $this->context->backendName,
                    'date_key' => $dateKey,
                    ...$context,
                    'response' => [
                        'body' => $item,
                    ],
                ]);

                Data::increment($this->context->backendName, $type . '_ignored_no_date_is_set');
                return;
            }

            $entity = $this->createEntity(
                context: $this->context,
                guid:    $this->guid,
                item:    $item,
                opts:    $opts + [
                             'library' => ag($context, 'library.id'),
                             'override' => [
                                 iFace::COLUMN_EXTRA => [
                                     $this->context->backendName => [
                                         iFace::COLUMN_EXTRA_EVENT => 'task.import',
                                         iFace::COLUMN_EXTRA_DATE => makeDate('now'),
                                     ],
                                 ],
                             ]
                         ],
            );

            if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
                if (true === (bool)Config::get('debug.import')) {
                    $name = Config::get('tmpDir') . '/debug/' . $this->context->backendName . '.' . ag(
                            $item,
                            'Id'
                        ) . '.json';

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
                    'backend' => $this->context->backendName,
                    ...$context,
                    'context' => [
                        'guids' => !empty($providerIds) ? $providerIds : 'None'
                    ],
                ]);

                Data::increment($this->context->backendName, $type . '_ignored_no_supported_guid');
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
                    'backend' => $this->context->backendName,
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
            if (JellyfinClient::TYPE_SHOW === ($type = ag($item, 'Type'))) {
                $this->processShow(item: $item, context: $context);
                return;
            }

            $after = ag($opts, 'after');
            $type = $this->typeMapper[$type];

            Data::increment($this->context->backendName, $type . '_total');

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
                    'backend' => $this->context->backendName,
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
                    'backend' => $this->context->backendName,
                    'date_key' => $dateKey,
                    ...$context,
                    'response' => [
                        'body' => $item,
                    ],
                ]);

                Data::increment($this->context->backendName, $type . '_ignored_no_date_is_set');
                return;
            }

            $rItem = $this->createEntity(
                context: $this->context,
                guid:    $this->guid,
                item:    $item,
                opts:    array_replace_recursive($opts, ['library' => ag($context, 'library.id')])
            );

            if (!$rItem->hasGuids() && !$rItem->hasRelativeGuid()) {
                $providerIds = (array)ag($item, 'ProviderIds', []);

                $message = 'Ignoring [%(backend)] [%(item.title)]. No valid/supported external ids.';

                if (empty($providerIds)) {
                    $message .= ' Most likely unmatched %(item.type).';
                }

                $this->logger->info($message, [
                    'backend' => $this->context->backendName,
                    ...$context,
                    'context' => [
                        'guids' => !empty($providerIds) ? $providerIds : 'None'
                    ],
                ]);

                Data::increment($this->context->backendName, $type . '_ignored_no_supported_guid');
                return;
            }

            if (false === ag($this->options, Options::IGNORE_DATE, false)) {
                if (true === ($after instanceof DateTimeInterface) && $rItem->updated >= $after->getTimestamp()) {
                    $this->logger->debug(
                        'Ignoring [%(backend)] [%(item.title)]. Backend date is equal or newer than last sync date.',
                        [
                            'backend' => $this->context->backendName,
                            ...$context,
                            'comparison' => [
                                'lastSync' => makeDate($after),
                                'backend' => makeDate($rItem->updated),
                            ],
                        ]
                    );

                    Data::increment($this->context->backendName, $type . '_ignored_date_is_equal_or_higher');
                    return;
                }
            }

            if (null === ($entity = $mapper->get($rItem))) {
                $this->logger->warning('Ignoring [%(backend)] [%(item.title)]. %(item.type) Is not imported yet.', [
                    'backend' => $this->context->backendName,
                    ...$context,
                ]);
                Data::increment($this->context->backendName, $type . '_ignored_not_found_in_db');
                return;
            }

            if ($rItem->watched === $entity->watched) {
                if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
                    $this->logger->debug(
                        'Ignoring [%(backend)] [%(item.title)]. %(item.type) play state is identical.',
                        [
                            'backend' => $this->context->backendName,
                            ...$context,
                            'comparison' => [
                                'backend' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                'remote' => $rItem->isWatched() ? 'Played' : 'Unplayed',
                            ],
                        ]
                    );
                }

                Data::increment($this->context->backendName, $type . '_ignored_state_unchanged');
                return;
            }

            if ($rItem->updated >= $entity->updated && false === ag($this->options, Options::IGNORE_DATE, false)) {
                $this->logger->debug(
                    'Ignoring [%(backend)] [%(item.title)]. Backend date is equal or newer than storage date.',
                    [
                        'backend' => $this->context->backendName,
                        ...$context,
                        'comparison' => [
                            'storage' => makeDate($entity->updated),
                            'backend' => makeDate($rItem->updated),
                        ],
                    ]
                );

                Data::increment($this->context->backendName, $type . '_ignored_date_is_newer');
                return;
            }

            $url = $this->url->withPath(sprintf('/Users/%s/PlayedItems/%s', $this->user, ag($item, 'Id')));

            $context['item']['url'] = $url;

            $this->logger->debug(
                'Queuing Request to change [%(backend)] [%(item.title)] play state to [%(play_state)].',
                [
                    'backend' => $this->context->backendName,
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
                                        'backend' => $this->context->backendName,
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
                    'backend' => $this->context->backendName,
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
            'title' => sprintf(
                '%s (%s)',
                ag($item, ['Name', 'OriginalTitle'], '??'),
                ag($item, 'ProductionYear', '0000')
            ),
            'year' => ag($item, 'ProductionYear', null),
            'type' => ag($item, 'Type'),
        ];

        if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
            $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title) (%(item.year))] payload.', [
                'backend' => $this->context->backendName,
                ...$context,
                'response' => [
                    'body' => $item,
                ],
            ]);
        }

        $providersId = (array)ag($item, 'ProviderIds', []);

        if (!$this->guid->has($providersId)) {
            $message = 'Ignoring [%(backend)] [%(item.title)]. %(item.type) has no valid/supported external ids.';

            if (empty($providersId)) {
                $message .= ' Most likely unmatched %(item.type).';
            }

            $this->logger->info($message, [
                'backend' => $this->context->backendName,
                ...$context,
                'data' => [
                    'guids' => !empty($providersId) ? $providersId : 'None'
                ],
            ]);

            return;
        }

        $this->context->cache->set(
            JellyfinClient::TYPE_SHOW . '.' . ag($context, 'item.id'),
            Guid::fromArray($this->guid->get($providersId), context: [
                'backend' => $this->context->backendName,
                ...$context,
            ])->getAll()
        );
    }

    protected function checkConfig(bool $checkUrl = true, bool $checkToken = true, bool $checkUser = true): void
    {
        if (true === $checkUrl && !($this->url instanceof UriInterface)) {
            throw new RuntimeException(static::NAME . ': No host was set.');
        }

        if (true === $checkToken && null === $this->token) {
            throw new RuntimeException(static::NAME . ': No token was set.');
        }

        if (true === $checkUser && null === $this->user) {
            throw new RuntimeException(static::NAME . ': No User was set.');
        }
    }
}
