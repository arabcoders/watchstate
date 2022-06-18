<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Backends\Common\Cache;
use App\Backends\Common\Context;
use App\Backends\Plex\Action\GetIdentifier;
use App\Backends\Plex\Action\GetLibrariesList;
use App\Backends\Plex\Action\GetLibrary;
use App\Backends\Plex\Action\GetUsersList;
use App\Backends\Plex\Action\InspectRequest;
use App\Backends\Plex\Action\ParseWebhook;
use App\Backends\Plex\Action\Push;
use App\Backends\Plex\Action\SearchId;
use App\Backends\Plex\Action\SearchQuery;
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
            options:        $cloned->options
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
        $response = Container::get(GetUsersList::class)($this->context, $opts);

        if (false === $response->isSuccessful()) {
            if ($response->hasError()) {
                $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
            }

            throw new RuntimeException(
                ag($response->extra, 'message', fn() => $response->error->format())
            );
        }

        return $response->response;
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

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        return $response->isSuccessful() ? $response->response : $request;
    }

    public function parseWebhook(ServerRequestInterface $request): iFace
    {
        $response = Container::get(ParseWebhook::class)(
            context: $this->context,
            guid:    $this->guid,
            request: $request
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new HttpException(
                ag($response->extra, 'message', fn() => $response->error->format()),
                ag($response->extra, 'http_code', 400),
            );
        }

        return $response->response;
    }

    public function search(string $query, int $limit = 25, array $opts = []): array
    {
        $response = Container::get(SearchQuery::class)(
            context: $this->context,
            query:   $query,
            limit:   $limit,
            opts:    $opts
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }

    public function searchId(string|int $id, array $opts = []): array
    {
        $response = Container::get(SearchId::class)(context: $this->context, id: $id, opts: $opts);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }

    public function getMetadata(string|int $id, array $opts = []): array
    {
        return $this->getItemDetails(context: $this->context, id: $id, opts: $opts);
    }

    public function getLibrary(string|int $id, array $opts = []): array
    {
        $response = Container::get(GetLibrary::class)(context: $this->context, guid: $this->guid, id: $id, opts: $opts);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }

    public function listLibraries(array $opts = []): array
    {
        $response = Container::get(GetLibrariesList::class)(context: $this->context, opts: $opts);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

        return $response->response;
    }

    public function push(array $entities, QueueRequests $queue, DateTimeInterface|null $after = null): array
    {
        $response = Container::get(Push::class)(
            context:  $this->context,
            entities: $entities,
            queue:    $queue,
            after:    $after
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new RuntimeException(ag($response->extra, 'message', fn() => $response->error->format()));
        }

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
}
