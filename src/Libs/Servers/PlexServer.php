<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Backends\Common\Cache;
use App\Backends\Common\Context;
use App\Backends\Plex\Action\GetIdentifier;
use App\Backends\Plex\Action\InspectRequest;
use App\Backends\Plex\Action\ParseWebhook;
use App\Backends\Plex\PlexActionTrait;
use App\Backends\Plex\PlexClient;
use App\Backends\Plex\PlexGuid;
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
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

class PlexServer implements ServerInterface
{
    use PlexActionTrait;

    public const NAME = 'PlexBackend';

    protected UriInterface|null $url = null;
    protected string|null $token = null;
    protected array $options = [];
    protected string $name = '';
    protected array $persist = [];

    protected string|int|null $uuid = null;
    protected string|int|null $user = null;
    protected Context|null $context = null;

    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
        protected Cache $cache,
        protected PlexGuid $guid,
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
        $cloned = clone $this;

        $cloned->name = $name;
        $cloned->url = $url;
        $cloned->token = $token;
        $cloned->user = $userId;
        $cloned->uuid = $uuid;
        $cloned->options = $options;
        $cloned->persist = $persist;

        $cloned->context = new Context(
            clientName:     static::NAME,
            backendName:    $name,
            backendUrl:     $url,
            cache:          $this->cache->withData($cloned::NAME . '_' . $name, $this->options),
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

                    $year = (int)ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
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

        $year = (int)ag($metadata, ['grandParentYear', 'parentYear', 'year'], 0);
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
        }

        return $builder;
    }

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

        $url = $this->url->withPath('/library/sections/');

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

        foreach (ag($json, 'MediaContainer.Directory', []) as $section) {
            if ((int)ag($section, 'key') !== (int)$id) {
                continue;
            }
            $found = true;
            $context = [
                'library' => [
                    'id' => ag($section, 'key'),
                    'type' => ag($section, 'type', 'unknown'),
                    'title' => ag($section, 'title', '??'),
                ],
            ];
            break;
        }

        if (false === $found) {
            throw new RuntimeException(
                sprintf('The response from [%s] does not contain library with id of [%s].', $this->getName(), $id)
            );
        }

        if (true !== in_array(ag($context, 'library.type'), [iFace::TYPE_MOVIE, 'show'])) {
            throw new RuntimeException(
                sprintf(
                    'The requested [%s] library [%s] is of [%s] type. Which is not supported type.',
                    $this->getName(),
                    ag($context, 'library.title', $id),
                    ag($context, 'library.type')
                )
            );
        }

        $query = [
            'sort' => 'addedAt:asc',
            'includeGuids' => 1,
        ];

        if (iFace::TYPE_MOVIE === ag($context, 'library.type')) {
            $query['type'] = 1;
        }

        $url = $this->url->withPath(sprintf('/library/sections/%d/all', $id))->withQuery(http_build_query($query));

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
                $url = $this->url->withPath(sprintf('/library/metadata/%d', ag($item, 'ratingKey')));
                $possibleTitlesList = ['title', 'originalTitle', 'titleSort'];

                $data = [
                    'backend' => $this->getName(),
                    ...$context,
                ];

                $year = (int)ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
                if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
                    $year = (int)makeDate($airDate)->format('Y');
                }

                if (true === ag($this->options, Options::DEBUG_TRACE)) {
                    $data['trace'] = $item;
                }

                $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title) (%(item.year))].', $data);

                $metadata = [
                    'id' => (int)ag($item, 'ratingKey'),
                    'type' => ucfirst(ag($item, 'type', 'unknown')),
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

                switch (ag($item, 'type')) {
                    case 'show':
                        foreach (ag($item, 'Location', []) as $path) {
                            $path = ag($path, 'path');
                            $metadata['match']['paths'][] = [
                                'full' => $path,
                                'short' => basename($path),
                            ];
                        }
                        break;
                    case iFace::TYPE_MOVIE:
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
                        throw new RuntimeException(
                            sprintf(
                                'While parsing [%s] library [%s] items, we encountered unexpected item [%s] type.',
                                $this->getName(),
                                ag($context, 'library.title', '??'),
                                ag($item, 'type')
                            )
                        );
                }

                $itemGuid = ag($item, 'guid');

                if (null !== $itemGuid && false === $this->guid->isLocal($itemGuid)) {
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
                $this->logger->warning(
                    'Failed to decode one item of [%(backend)] library id [%(library.title)] content.',
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

            $year = (int)ag($entity, ['grandParentYear', 'parentYear', 'year'], 0);
            if (0 === $year && null !== ($airDate = ag($entity, 'originallyAvailableAt'))) {
                $year = (int)makeDate($airDate)->format('Y');
            }

            $context['item'] = [
                'id' => ag($entity, 'ratingKey'),
                'title' => ag($entity, ['title', 'originalTitle'], '??'),
                'year' => $year,
                'type' => ag($entity, 'type'),
                'url' => (string)$url,
            ];

            if (iFace::TYPE_MOVIE === ag($context, 'item.type')) {
                yield $handleRequest(item: $entity, context: $context);
            } else {
                $url = $this->url->withPath(sprintf('/library/metadata/%d', ag($entity, 'ratingKey')));

                $this->logger->debug('Requesting [%(backend)] %(item.type) [%(item.title) (%(item.year))] metadata.', [
                    'backend' => $this->getName(),
                    ...$context,
                ]);

                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'context' => $context
                        ]
                    ])
                );
            }
        }

        if (empty($requests) && iFace::TYPE_MOVIE !== ag($context, 'library.type')) {
            throw new RuntimeException(
                sprintf(
                    'No requests were made [%s] library [%s] is empty.',
                    $this->getName(),
                    ag($context, 'library.title', $id)
                )
            );
        }

        if (!empty($requests)) {
            $this->logger->info(
                'Requesting [%(total)] items metadata from [%(backend)] library [%(library.title)].',
                [
                    'backend' => $this->getName(),
                    'total' => number_format(count($requests)),
                    'library' => ag($context, 'library', []),
                ]
            );
        }

        foreach ($requests as $response) {
            $requestContext = ag($response->getInfo('user_data'), 'context', []);

            if (200 !== $response->getStatusCode()) {
                $this->logger->warning(
                    'Request for [%(backend)] %(item.type) [%(item.title)] metadata returned with unexpected [%(status_code)] status code.',
                    [
                        'backend' => $this->getName(),
                        'status_code' => $response->getStatusCode(),
                        ...$requestContext
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
                item:    ag($json, 'MediaContainer.Metadata.0', []),
                context: $requestContext
            );
        }
    }

    public function listLibraries(array $opts = []): array
    {
        $this->checkConfig();

        try {
            $url = $this->url->withPath('/library/sections');

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

            $listDirs = ag($json, 'MediaContainer.Directory', []);

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

            $metadata = $entity->getMetadata($this->getName());

            $context = [
                'item' => [
                    'id' => $entity->id,
                    'type' => $entity->type,
                    'title' => $entity->getName(),
                ],
            ];

            if (null === ag($metadata, iFace::COLUMN_ID)) {
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
                $url = $this->url->withPath('/library/metadata/' . ag($metadata, iFace::COLUMN_ID));

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

                $json = ag($body, 'MediaContainer.Metadata.0', []);

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

                $isWatched = 0 === (int)ag($json, 'viewCount', 0) ? 0 : 1;

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
                    $dateKey = 1 === $isWatched ? 'lastViewedAt' : 'addedAt';
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

                    if ($date->getTimestamp() >= ($entity->updated + $timeExtra)) {
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

                $url = $this->url->withPath($entity->isWatched() ? '/:/scrobble' : '/:/unscrobble')->withQuery(
                    http_build_query(
                        [
                            'identifier' => 'com.plexapp.plugins.library',
                            'key' => ag($json, 'ratingKey'),
                        ]
                    )
                );

                $context['remote']['url'] = $url;

                $this->logger->debug(
                    'Queuing request to change [%(backend)] %(item.type) [%(item.title)] play state to [%(play_state)].',
                    [
                        'backend' => $this->getName(),
                        'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                        ...$context,
                    ]
                );

                if (false === (bool)ag($this->options, Options::DRY_RUN)) {
                    $queue->add(
                        $this->http->request(
                            'GET',
                            (string)$url,
                            array_replace_recursive($this->getHeaders(), [
                                'user_data' => [
                                    'context' => $context + [
                                            'backend' => $this->getName(),
                                            'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                        ],
                                ]
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
                                opts:    ['after' => $after]
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
            includeParent: false === count($this->context->cache->get(PlexClient::TYPE_SHOW, [])) > 1,
        );
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

            $listDirs = ag($json, 'MediaContainer.Directory', []);

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
                $this->logger->info('Ignoring [%(backend)] [%(library.title)]. Requested by user config.', [
                    'backend' => $this->getName(),
                    ...$context,
                ]);
                continue;
            }

            if ('movie' !== $type && 'show' !== $type) {
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
        $after = ag($opts, 'after', null);
        $library = ag($context, 'library.id');
        $type = ag($item, 'type');

        try {
            if ('show' === $type) {
                $this->processShow($item, $context);
                return;
            }

            Data::increment($this->getName(), $library . '_total');
            Data::increment($this->getName(), $type . '_total');

            $year = (int)ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
            if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
                $year = (int)makeDate($airDate)->format('Y');
            }

            $context['item'] = [
                'id' => ag($item, 'ratingKey'),
                'title' => match ($type) {
                    iFace::TYPE_MOVIE => sprintf(
                        '%s (%s)',
                        ag($item, ['title', 'originalTitle'], '??'),
                        0 === $year ? '0000' : $year,
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
                $this->logger->debug('Ignoring [%(backend)] %(item.type) [%(item.title)]. No Date is set on object.', [
                    'backend' => $this->getName(),
                    'date_key' => true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt',
                    ...$context,
                    'response' => [
                        'body' => $item,
                    ],
                ]);

                Data::increment($this->getName(), $type . '_ignored_no_date_is_set');
                return;
            }

            $entity = $this->createEntity(
                context: $this->context,
                guid:    $this->guid,
                item:    $item,
                opts:    $opts + [
                             'override' => [
                                 iFace::COLUMN_EXTRA => [
                                     $this->getName() => [
                                         iFace::COLUMN_EXTRA_EVENT => 'task.import',
                                         iFace::COLUMN_EXTRA_DATE => makeDate('now'),
                                     ],
                                 ],
                             ],
                         ]
            );

            if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
                if (true === (bool)Config::get('debug.import')) {
                    $name = Config::get('tmpDir') . '/debug/' . $this->getName() . '.' . $item['ratingKey'] . '.json';

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

                if (null !== ($itemGuid = ag($item, 'guid')) && false === $this->guid->isLocal($itemGuid)) {
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

                Data::increment($this->getName(), $type . '_ignored_no_supported_guid');
                return;
            }

            $mapper->add(entity: $entity, opts: [
                'after' => $after,
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
        $after = ag($opts, 'after', null);
        $library = ag($context, 'library.id');
        $type = ag($item, 'type');

        try {
            if ('show' === $type) {
                $this->processShow($item, $context);
                return;
            }

            Data::increment($this->getName(), $library . '_total');
            Data::increment($this->getName(), $type . '_total');

            $year = (int)ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
            if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
                $year = (int)makeDate($airDate)->format('Y');
            }

            $context['item'] = [
                'id' => ag($item, 'ratingKey'),
                'title' => match ($type) {
                    iFace::TYPE_MOVIE => sprintf(
                        '%s (%s)',
                        ag($item, ['title', 'originalTitle'], '??'),
                        0 === $year ? '0000' : $year,
                    ),
                    iFace::TYPE_EPISODE => sprintf(
                        '%s - (%sx%s)',
                        ag($item, ['grandparentTitle', 'originalTitle', 'title'], '??'),
                        str_pad((string)ag($item, 'parentIndex', 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)ag($item, 'index', 0), 3, '0', STR_PAD_LEFT),
                    ),
                },
                'type' => $type,
            ];

            if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
                $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title)] payload.', [
                    'backend' => $this->getName(),
                    ...$context,
                    'payload' => $item,
                ]);
            }

            if (null === ag($item, true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt')) {
                $this->logger->debug('Ignoring [%(backend)] [%(item.title)]. No Date is set on object.', [
                    'backend' => $this->getName(),
                    'date_key' => true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt',
                    ...$context,
                    'response' => [
                        'body' => $item,
                    ],
                ]);

                Data::increment($this->getName(), $type . '_ignored_no_date_is_set');
                return;
            }

            $rItem = $this->createEntity(
                context: $this->context,
                guid:    $this->guid,
                item:    $item,
                opts:    $opts
            );

            if (!$rItem->hasGuids() && !$rItem->hasRelativeGuid()) {
                $message = 'Ignoring [%(backend)] [%(item.title)]. No valid/supported external ids.';

                if (null === ($item['Guid'] ?? null)) {
                    $item['Guid'] = [];
                }

                if (null !== ($itemGuid = ag($item, 'guid')) && false === $this->guid->isLocal($itemGuid)) {
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

            if ($rItem->updated >= $entity->updated && false === ag($this->options, Options::IGNORE_DATE, false)) {
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
                        'GET',
                        (string)$url,
                        array_replace_recursive($this->getHeaders(), [
                            'user_data' => [
                                'context' => $context + [
                                        'backend' => $this->getName(),
                                        'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                    ],
                            ]
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

    protected function processShow(array $item, array $context): void
    {
        if (null === ($item['Guid'] ?? null)) {
            $item['Guid'] = [['id' => $item['guid']]];
        } else {
            $item['Guid'][] = ['id' => $item['guid']];
        }

        $year = (int)ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
        if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
            $year = (int)makeDate($airDate)->format('Y');
        }

        $context['item'] = [
            'id' => ag($item, 'ratingKey'),
            'title' => sprintf(
                '%s (%s)',
                ag($item, ['title', 'originalTitle'], '??'),
                0 === $year ? '0000' : $year,
            ),
            'year' => 0 === $year ? '0000' : $year,
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

        if (!$this->guid->has(guids: $item['Guid'])) {
            if (null === ($item['Guid'] ?? null)) {
                $item['Guid'] = [];
            }

            if (null !== ($item['guid'] ?? null) && false === $this->guid->isLocal($item['guid'])) {
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

        $gContext = ag_set(
            $context,
            'item.plex_id',
            str_starts_with(ag($item, 'guid', ''), 'plex://') ? ag($item, 'guid') : 'none'
        );

        $this->context->cache->set(
            PlexClient::TYPE_SHOW . '.' . ag($context, 'item.id'),
            Guid::fromArray(
                payload: $this->guid->get($item['Guid'], context: [...$gContext]),
                context: ['backend' => $this->getName(), ...$context,]
            )->getAll()
        );
    }

    protected function checkConfig(bool $checkUrl = true, bool $checkToken = true): void
    {
        if (true === $checkUrl && !($this->url instanceof UriInterface)) {
            throw new RuntimeException(static::NAME . ': No host was set.');
        }

        if (true === $checkToken && null === $this->token) {
            throw new RuntimeException(static::NAME . ': No token was set.');
        }
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
                    'Request for [%(backend)] [%(user_id)] temporary token responded with unexpected [%(status_code)] status code.',
                    [
                        'backend' => $this->getName(),
                        'user_id' => $userId,
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
