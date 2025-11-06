<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Request;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexActionTrait;
use App\Backends\Plex\PlexClient;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Extends\RetryableHttpClient;
use App\Libs\Guid;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use Closure;
use DateTimeInterface as iDate;
use JsonException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
use Throwable;

class Import
{
    use CommonTrait;
    use PlexActionTrait;

    protected string $action = 'plex.import';

    protected RetryableHttpClient $http;

    public function __construct(iHttp $http, protected iLogger $logger)
    {
        $this->http = new RetryableHttpClient(
            $http,
            maxRetries: (int)Config::get('http.default.maxRetries', 3),
            logger: $logger
        );
    }

    /**
     * @param Context $context
     * @param iGuid $guid
     * @param iImport $mapper
     * @param iDate|null $after
     * @param array $opts
     *
     * @return Response
     */
    public function __invoke(
        Context $context,
        iGuid $guid,
        iImport $mapper,
        iDate|null $after = null,
        array $opts = []
    ): Response {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->getLibraries(
                context: $context,
                handle: fn(array $logContext = []) => fn(iResponse $response) => $this->handle(
                    context: $context,
                    response: $response,
                    callback: fn(array $item, array $logContext = []) => $this->process(
                        context: $context,
                        guid: $guid,
                        mapper: $mapper,
                        item: $item,
                        logContext: $logContext,
                        opts: $opts + ['after' => $after],
                    ),
                    logContext: $logContext
                ),
                error: fn(array $logContext = []) => fn(Throwable $e) => $this->logger->error(
                    message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' library '{library.title}' request. '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'action' => $this->action,
                        'backend' => $context->backendName,
                        'client' => $context->clientName,
                        'user' => $context->userContext->name,
                        ...$logContext,
                        ...exception_log($e)
                    ]
                ),
                opts: $opts,
            ),
            action: $this->action
        );
    }

    protected function getLibraries(Context $context, Closure $handle, Closure $error, array $opts = []): array
    {
        $segmentSize = (int)ag($context->options, Options::LIBRARY_SEGMENT, 1000);

        $rContext = [
            'action' => property_exists($this, 'action') ? $this->action : 'import',
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
        ];

        try {
            $url = $context->backendUrl->withPath('/library/sections');
            $rContext['url'] = (string)$url;

            $this->logger->debug("{action}: Requesting '{client}: {user}@{backend}' libraries.", $rContext);

            $response = $this->http->request(Method::GET, (string)$url, $context->getHttpOptions());

            $payload = $response->getContent(false);

            if ($context->trace) {
                $this->logger->debug("{action}: Processing '{client}: {user}@{backend}' libraries response.", [
                    ...$rContext,
                    'status_code' => $response->getStatusCode(),
                    'response' => [
                        'body' => $payload,
                        'headers' => $response->getHeaders(false),
                    ],
                ]);
            }

            if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
                $logContext = [
                    ...$rContext,
                    'status_code' => $response->getStatusCode(),
                    'response' => [
                        'headers' => $response->getHeaders(false),
                    ],
                ];

                if ($context->trace) {
                    $logContext['response']['body'] = $response->getInfo('debug');
                }

                $this->logger->error(
                    "{action}: Request for '{client}: {user}@{backend}' libraries returned with unexpected '{status_code}' status code.",
                    $logContext
                );

                Message::add("{$context->backendName}.has_errors", true);
                return [];
            }

            $json = json_decode(
                json: $payload,
                associative: true,
                flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            unset($payload);

            $listDirs = ag($json, 'MediaContainer.Directory', []);

            if (empty($listDirs)) {
                $this->logger->warning(
                    message: "{action}: Request for '{client}: {user}@{backend}' libraries returned with empty list.",
                    context: [
                        ...$rContext,
                        'response' => [
                            'key' => 'MediaContainer.Directory',
                            'body' => $json,
                        ],
                    ]
                );
                Message::add("{$context->backendName}.has_errors", true);
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error(
                ...lw(
                    message: "{action}: Request for '{client}: {user}@{backend}' libraries has failed. '{error.kind}' with message '{error.message}' at '{error.file}:{error.line}'.",
                    context: [...$rContext, ...exception_log($e)],
                    e: $e
                )
            );
            Message::add("{$context->backendName}.has_errors", true);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                ...lw(
                    message: "{action}: Request for '{client}: {user}@{backend}' libraries returned with invalid body. '{error.kind}' with message '{error.message}' at '{error.file}:{error.line}'.",
                    context: [...$rContext, ...exception_log($e)],
                    e: $e
                )
            );
            Message::add("{$context->backendName}.has_errors", true);
            return [];
        } catch (Throwable $e) {
            $this->logger->error(
                ...lw(
                    message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' request for libraries. {error.message} at '{error.file}:{error.line}'.",
                    context: [...$rContext, ...exception_log($e)],
                    e: $e
                )
            );
            Message::add("{$context->backendName}.has_errors", true);
            return [];
        }

        if (null !== ($ignoreIds = ag($context->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => (int)trim($v), explode(',', (string)$ignoreIds));
        }

        $limitLibraryId = ag($opts, Options::ONLY_LIBRARY_ID, null);

        $requests = $total = [];
        $ignored = $unsupported = 0;

        // -- Get library items count.
        foreach ($listDirs as $section) {
            $key = (int)ag($section, 'key');

            if (null !== $limitLibraryId && $key !== (int)$limitLibraryId) {
                continue;
            }

            $logContext = [
                ...$rContext,
                'library' => [
                    'id' => ag($section, 'key'),
                    'title' => ag($section, 'title', '??'),
                    'type' => ag($section, 'type', 'unknown'),
                    'agent' => ag($section, 'agent', 'unknown'),
                ],
            ];

            if (true === in_array($key, $ignoreIds ?? [])) {
                continue;
            }

            if (false === in_array(ag($logContext, 'library.type'), [PlexClient::TYPE_MOVIE, PlexClient::TYPE_SHOW])) {
                continue;
            }

            if (false === in_array(ag($logContext, 'library.agent'), PlexClient::SUPPORTED_AGENTS)) {
                $this->logger->notice(
                    message: "{action}: Ignoring '{client}: {user}@{backend}' - '{library.title}' Unsupported agent type. '{agent}'.",
                    context: [
                        ...$logContext,
                        'agent' => ag($logContext, 'library.agent', '??'),
                    ]
                );
                continue;
            }

            $isMovieLibrary = PlexClient::TYPE_MOVIE === ag($logContext, 'library.type');

            $url = $context->backendUrl->withPath(r('/library/sections/{library_id}/all', ['library_id' => $key]))
                ->withQuery(
                    http_build_query([
                        'includeGuids' => 1,
                        'type' => $isMovieLibrary ? 1 : 4,
                        'sort' => $isMovieLibrary ? 'addedAt' : 'episode.addedAt',
                        'X-Plex-Container-Start' => 0,
                        'X-Plex-Container-Size' => $segmentSize,
                    ])
                );

            $logContext['library']['url'] = $url;

            $this->logger->debug(
                message: "{action}: Requesting '{client}: {user}@{backend}' - '{library.title}' items count.",
                context: $logContext
            );

            try {
                $requests[] = $this->http->request(
                    method: Method::GET,
                    url: (string)$url,
                    options: array_replace_recursive($context->getHttpOptions(), [
                        'headers' => [
                            'X-Plex-Container-Start' => 0,
                            'X-Plex-Container-Size' => 0,
                        ],
                        'user_data' => $logContext,
                    ])
                );

                // -- parse total tv shows count.
                if (PlexClient::TYPE_SHOW === ag($logContext, 'library.type')) {
                    $logContextSub = $logContext;
                    $logContextSub['library']['url'] = $url->withQuery(
                        http_build_query([
                            'includeGuids' => 1,
                            'type' => 2,
                            'X-Plex-Container-Start' => 0,
                            'X-Plex-Container-Size' => $segmentSize,
                        ])
                    );

                    $this->logger->debug(
                        message: "{action}: Requesting '{client}: {user}@{backend}' - '{library.title}' series count.",
                        context: $logContextSub,
                    );

                    $requests[] = $this->http->request(
                        method: Method::GET,
                        url: (string)$logContextSub['library']['url'],
                        options: array_replace_recursive($context->getHttpOptions(), [
                            'headers' => [
                                'X-Plex-Container-Start' => 0,
                                'X-Plex-Container-Size' => 0,
                            ],
                            'user_data' => [
                                'isShowRequest' => true,
                                ...$logContextSub
                            ],
                        ])
                    );
                }
            } catch (ExceptionInterface $e) {
                $this->logger->error(
                    ...lw(
                        message: "{action}: Request for '{client}: {user}@{backend}' - '{library.title}' items count has failed. '{error.kind}' with message '{error.message}' at '{error.file}:{error.line}'.",
                        context: [...$rContext, ...exception_log($e), ...$logContext],
                        e: $e
                    )
                );
                continue;
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' request for libraries. {error.message} at '{error.file}:{error.line}'.",
                        context: [...$rContext, ...exception_log($e), ...$logContext,],
                        e: $e
                    )
                );
                continue;
            }
        }

        // -- Parse libraries items count.
        foreach ($requests as $response) {
            $logContext = ag($response->getInfo('user_data'), []);

            try {
                if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
                    $this->logger->error(
                        message: "{action}: Request for '{client}: {user}@{backend}' - '{library.title}' items count returned with unexpected '{status_code}' status code.",
                        context: [
                            ...$logContext,
                            'status_code' => $response->getStatusCode(),
                        ]
                    );
                    continue;
                }

                $totalCount = (int)(ag($response->getHeaders(), 'x-plex-container-total-size')[0] ?? 0);

                if ($totalCount < 1) {
                    $this->logger->warning(
                        message: "{action}: Request for '{client}: {user}@{backend}' - '{library.title}' items count returned with 0 or less.",
                        context: [
                            ...$logContext,
                            'response' => [
                                'headers' => $response->getHeaders(),
                            ],
                        ]
                    );
                    continue;
                }

                if (ag_exists($logContext, 'isShowRequest')) {
                    $total['show_' . ag($logContext, 'library.id')] = $totalCount;
                } else {
                    $total[(int)ag($logContext, 'library.id')] = $totalCount;
                }
            } catch (ExceptionInterface $e) {
                $this->logger->error(
                    ...lw(
                        message: "{action}: Request for '{client}: {user}@{backend}' - '{library.title}' total items has failed. '{error.kind}' '{error.message}' at '{error.file}:{error.line}'.",
                        context: [...$logContext, ...exception_log($e)],
                        e: $e
                    )
                );
                continue;
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' request for items count. {error.message} at '{error.file}:{error.line}'.",
                        context: [...$logContext, ...exception_log($e)],
                        e: $e
                    )
                );
                continue;
            }
        }

        $requests = [];

        // -- get paginated tv shows metadata.
        foreach ($listDirs as $section) {
            $key = (int)ag($section, 'key');

            if (PlexClient::TYPE_SHOW !== ag($section, 'type', 'unknown')) {
                continue;
            }

            if (!in_array(ag($section, 'agent'), PlexClient::SUPPORTED_AGENTS)) {
                continue;
            }

            if (true === in_array($key, $ignoreIds ?? [])) {
                continue;
            }

            $logContext = [
                ...$rContext,
                'library' => [
                    'id' => $key,
                    'title' => ag($section, 'title', '??'),
                    'type' => ag($section, 'type', 'unknown'),
                    'url' => $url,
                ],
            ];

            if (false === array_key_exists('show_' . $key, $total)) {
                $ignored++;
                $this->logger->warning(
                    message: "{action}: Ignoring '{client}: {user}@{backend}' - '{library.title}'. No series items count was found.",
                    context: $logContext
                );
                continue;
            }

            $logContext['library']['totalRecords'] = $total['show_' . $key];

            $segmentTotal = (int)$total['show_' . $key];
            $segmented = ceil($segmentTotal / $segmentSize);

            for ($i = 0; $i < $segmented; $i++) {
                try {
                    $logContext['segment'] = [
                        'number' => $i + 1,
                        'of' => $segmented,
                        'size' => $segmentSize,
                    ];

                    $url = $context->backendUrl->withPath(
                        r('/library/sections/{library_id}/all', ['library_id' => $key])
                    )->withQuery(
                        http_build_query([
                            'type' => 2,
                            'includeGuids' => 1,
                            'X-Plex-Container-Size' => $segmentSize,
                            'X-Plex-Container-Start' => $i < 1 ? 0 : ($segmentSize * $i),
                        ])
                    );

                    $logContext['library']['url'] = $url;

                    $this->logger->debug(
                        message: "{action}: Requesting '{client}: {user}@{backend}' - '{library.title} {segment.number}/{segment.of}' series external ids.",
                        context: $logContext,
                    );

                    $requests[] = new Request(
                        method: Method::GET,
                        url: $url,
                        options: array_replace_recursive($context->getHttpOptions(), [
                            'headers' => [
                                'X-Plex-Container-Size' => $segmentSize,
                                'X-Plex-Container-Start' => $i < 1 ? 0 : ($segmentSize * $i),
                            ]
                        ]),
                        success: $handle($logContext),
                        error: $error($logContext),
                        extras: ['logContext' => $logContext, iHttp::class => $this->http]
                    );
                } catch (Throwable $e) {
                    $this->logger->error(
                        ...lw(
                            message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' '{library.title} {segment.number}/{segment.of}' series external ids request. {error.message} at '{error.file}:{error.line}'.",
                            context: [...$logContext, ...exception_log($e)],
                            e: $e
                        )
                    );
                    continue;
                }
            }
        }

        // -- get paginated movies/episodes.
        foreach ($listDirs as $section) {
            $key = (int)ag($section, 'key');

            $logContext = [
                ...$rContext,
                'library' => [
                    'id' => ag($section, 'key'),
                    'title' => ag($section, 'title', '??'),
                    'type' => ag($section, 'type', 'unknown'),
                ],
            ];

            if (!in_array(ag($section, 'agent'), PlexClient::SUPPORTED_AGENTS)) {
                continue;
            }

            if (true === in_array($key, $ignoreIds ?? [])) {
                $ignored++;
                $this->logger->info(
                    message: "{action}: Ignoring '{client}: {user}@{backend}' - '{library.title}'. Requested by user.",
                    context: $logContext,
                );
                continue;
            }

            if (!in_array(ag($logContext, 'library.type'), [PlexClient::TYPE_MOVIE, PlexClient::TYPE_SHOW])) {
                $unsupported++;
                $this->logger->info(
                    message: "{action}: Ignoring '{client}: {user}@{backend}' - '{library.title}'. Library type '{library.type}' is not supported.",
                    context: $logContext,
                );
                continue;
            }

            if (false === array_key_exists($key, $total)) {
                $ignored++;
                $this->logger->warning(
                    message: "{action}: Ignoring '{client}: {user}@{backend}' - '{library.title}'. No items count was found.",
                    context: $logContext,
                );
                continue;
            }

            $logContext['library']['totalRecords'] = $total[$key];

            $segmentTotal = (int)$total[$key];
            $segmented = ceil($segmentTotal / $segmentSize);

            for ($i = 0; $i < $segmented; $i++) {
                try {
                    $logContext['segment'] = [
                        'number' => $i + 1,
                        'of' => $segmented,
                        'size' => $segmentSize,
                    ];

                    $isMovieLibrary = PlexClient::TYPE_MOVIE === ag($logContext, 'library.type');

                    $url = $context->backendUrl->withPath(r('/library/sections/{library_id}/all', [
                        'library_id' => $key
                    ]))->withQuery(
                        http_build_query([
                            'includeGuids' => 1,
                            'type' => $isMovieLibrary ? 1 : 4,
                            'sort' => $isMovieLibrary ? 'addedAt' : 'episode.addedAt',
                            'X-Plex-Container-Size' => $segmentSize,
                            'X-Plex-Container-Start' => $i < 1 ? 0 : ($segmentSize * $i),
                        ])
                    );

                    $logContext['library']['url'] = $url;

                    $this->logger->debug(
                        message: "{action}: Requesting '{client}: {user}@{backend}' - '{library.title} {segment.number}/{segment.of}' content list.",
                        context: $logContext,
                    );

                    $requests[] = new Request(
                        method: Method::GET,
                        url: $url,
                        options: array_replace_recursive($context->getHttpOptions(), [
                            'headers' => [
                                'X-Plex-Container-Size' => $segmentSize,
                                'X-Plex-Container-Start' => $i < 1 ? 0 : ($segmentSize * $i),
                            ]
                        ]),
                        success: $handle($logContext),
                        error: $error($logContext),
                        extras: ['logContext' => $logContext, iHttp::class => $this->http]
                    );
                } catch (Throwable $e) {
                    $this->logger->error(
                        ...lw(
                            message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' - '{library.title} {segment.number}/{segment.of}' content list request. {error.message} at '{error.file}:{error.line}'.",
                            context: [...$logContext, ...exception_log($e)],
                            e: $e
                        )
                    );
                    continue;
                }
            }
        }

        if (0 === count($requests)) {
            $this->logger->warning("{action}: No requests for '{client}: {user}@{backend}' libraries were queued.", [
                ...$rContext,
                'context' => [
                    'total' => count($listDirs),
                    'ignored' => $ignored,
                    'unsupported' => $unsupported,
                ],
            ]);

            Message::add("{$context->backendName}.has_errors", true);
            return [];
        }

        return $requests;
    }

    /**
     * @throws TransportExceptionInterface
     * @noinspection PhpUnusedParameterInspection
     */
    protected function handle(Context $context, iResponse $response, Closure $callback, array $logContext = []): void
    {
        if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
            $this->logger->error(
                message: "{action}: Request for '{client}: {user}@{backend}' - '{library.title} {segment.number}/{segment.of}' content returned with unexpected '{status_code}' status code.",
                context: [
                    ...$logContext,
                    'status_code' => $response->getStatusCode(),
                ]
            );
            return;
        }

        $start = microtime(true);
        $this->logger->info(
            message: "{action}: Parsing '{client}: {user}@{backend}' - '{library.title} {segment.number}/{segment.of}' response.",
            context: [
                ...$logContext,
                'time' => [
                    'start' => $start,
                ],
            ]
        );

        try {
            $it = Items::fromIterable(
                iterable: httpClientChunks($this->http->stream($response)),
                options: [
                    'pointer' => '/MediaContainer/Metadata',
                    'decoder' => new ErrorWrappingDecoder(
                        innerDecoder: new ExtJsonDecoder(
                            assoc: true,
                            options: JSON_INVALID_UTF8_IGNORE
                        )
                    )
                ]
            );

            foreach ($it as $entity) {
                try {
                    if ($entity instanceof DecodingError) {
                        $this->logger->warning(
                            message: "{action}: Failed to decode one item of '{client}: {user}@{backend}' - '{library.title} {segment.number}/{segment.of}' items. {error.message}",
                            context: [
                                ...$logContext,
                                'error' => [
                                    'message' => $entity->getErrorMessage(),
                                    'body' => $entity->getMalformedJson(),
                                ],
                            ]
                        );
                        continue;
                    }
                    $callback(item: $entity, logContext: $logContext);
                } catch (Throwable $e) {
                    $this->logger->error(
                        ...lw(
                            message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' parsing '{library.title} {segment.number}/{segment.of}' item response. {error.message} at '{error.file}:{error.line}'.",
                            context: [...$logContext, ...exception_log($e), 'entity' => $entity],
                            e: $e
                        )
                    );
                }
            }
        } catch (Throwable $e) {
            $this->logger->error(
                ...lw(
                    message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' parsing of '{library.title} {segment.number}/{segment.of}' response. {error.message} at '{error.file}:{error.line}'.",
                    context: [...$logContext, ...exception_log($e)],
                    e: $e
                )
            );
        }

        $end = microtime(true);
        $this->logger->info(
            message: "Parsing '{client}: {user}@{backend}' - '{library.title} {segment.number}/{segment.of}' completed in '{time.duration}s'.",
            context: [
                ...$logContext,
                'time' => [
                    'start' => $start,
                    'end' => $end,
                    'duration' => round($end - $start, 2),
                ],
            ]
        );

        Message::increment('response.size', (int)$response->getInfo('size_download'));
    }

    protected function processShow(Context $context, iGuid $guid, array $item, array $logContext = []): void
    {
        $guids = [];

        if (null !== ($item['Guid'] ?? null)) {
            $guids = $item['Guid'];
        }

        if (null !== ($itemGuid = ag($item, 'guid')) && false === $guid->isLocal($itemGuid)) {
            $guids[] = ['id' => $itemGuid];
        }

        $year = (int)ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
        if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
            $year = (int)makeDate($airDate)->format('Y');
        }

        $logContext['item'] = [
            'id' => ag($item, 'ratingKey'),
            'title' => r('{title} ({year})', [
                'title' => ag($item, ['title', 'originalTitle'], '??'),
                'year' => 0 === $year ? '0000' : $year,
            ]),
            'year' => 0 === $year ? '0000' : $year,
            'type' => ag($item, 'type', 'unknown'),
        ];

        if ($context->trace) {
            $this->logger->debug(
                message: "{action}: Processing '{client}: {user}@{backend}' - '{item.type}: {item.title} ({item.year})' payload.",
                context: [
                    ...$logContext,
                    'response' => [
                        'body' => $item
                    ],
                ]
            );
        }

        if (!$guid->has(guids: $guids, context: $logContext)) {
            $message = "{action}: Ignoring '{client}: {user}@{backend}' - '{item.title}'. {item.type} has no valid/supported external ids.";

            if (empty($guids)) {
                $message .= ' Most likely unmatched {item.type}.';
            }

            $this->logger->info($message, [
                ...$logContext,
                'guids' => !empty($item['Guid']) ? $item['Guid'] : 'None',
            ]);

            return;
        }

        $gContext = ag_set(
            $logContext,
            'item.plex_id',
            str_starts_with($itemGuid ?? 'None', 'plex://') ? ag($item, 'guid') : 'none'
        );

        $context->cache->set(
            PlexClient::TYPE_SHOW . '.' . ag($logContext, 'item.id'),
            Guid::fromArray(
                payload: $guid->get(guids: $guids, context: [...$gContext]),
                context: $logContext
            )->getAll()
        );
    }

    protected function process(
        Context $context,
        iGuid $guid,
        iImport $mapper,
        array $item,
        array $logContext = [],
        array $opts = []
    ): void {
        if (PlexClient::TYPE_SHOW === ($type = ag($item, 'type'))) {
            $this->processShow(context: $context, guid: $guid, item: $item, logContext: $logContext);
            return;
        }

        $mappedType = PlexClient::TYPE_MAPPER[$type] ?? $type;

        try {
            if ($context->trace) {
                $this->logger->debug("{action}: Processing '{client}: {user}@{backend}' response payload.", [
                    ...$logContext,
                    'response' => [
                        'body' => $item,
                    ],
                ]);
            }

            Message::increment("{$context->backendName}.{$mappedType}.total");

            $year = (int)ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
            if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
                $year = (int)makeDate($airDate)->format('Y');
            }

            try {
                $logContext['item'] = [
                    'id' => ag($item, 'ratingKey'),
                    'title' => match ($type) {
                        PlexClient::TYPE_MOVIE => r('{title} ({year})', [
                            'title' => ag($item, ['title', 'originalTitle'], '??'),
                            'year' => 0 === $year ? '0000' : $year,
                        ]),
                        PlexClient::TYPE_EPISODE => r('{title} - ({season}x{episode})', [
                            'title' => ag($item, ['grandparentTitle', 'originalTitle', 'title'], '??'),
                            'season' => str_pad((string)ag($item, 'parentIndex', 0), 2, '0', STR_PAD_LEFT),
                            'episode' => str_pad((string)ag($item, 'index', 0), 3, '0', STR_PAD_LEFT),
                        ]),
                        default => throw new InvalidArgumentException(
                            r(
                                text: "{action}: Unexpected content type '{type}' was received from '{client}: {user}@{backend}'.",
                                context: [...$logContext, 'type' => $type]
                            )
                        ),
                    },
                    'type' => ag($item, 'type', 'unknown'),
                ];
            } catch (InvalidArgumentException $e) {
                $this->logger->error(
                    ...lw(
                        message: "{action}: Failed to parse '{client}: {user}@{backend}' item response. '{error.kind}' with '{error.message}' at '{error.file}:{error.line}' ",
                        context: [
                            ...$logContext,
                            'error' => [
                                'kind' => $e::class,
                                'line' => $e->getLine(),
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                            'response' => [
                                'body' => $item,
                            ],
                        ],
                        e: $e
                    )
                );
                return;
            }

            if (null === ag($item, true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt')) {
                $this->logger->debug(
                    message: "{action}: Ignoring '{client}: {backend}' - '{item.id}: {item.title}'. No date '{date_key}' is set on object. '{body}'",
                    context: [
                        ...$logContext,
                        'date_key' => true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt',
                        'response' => [
                            'body' => $item,
                        ],
                    ]
                );

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_date_is_set");
                return;
            }

            try {
                $entity = $this->createEntity(
                    context: $context,
                    guid: $guid,
                    item: $item,
                    opts: $opts + [
                        iState::COLUMN_META_LIBRARY => ag($logContext, 'library.id'),
                        'override' => [
                            iState::COLUMN_EXTRA => [
                                $context->backendName => [
                                    iState::COLUMN_EXTRA_EVENT => 'task.import',
                                    iState::COLUMN_EXTRA_DATE => makeDate('now'),
                                ],
                            ],
                        ],
                    ]
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "{action}: Exception '{error.kind}' occurred during '{client}: {user}@{backend}' - '{library.title}' - '{item.id}: {item.title}' entity creation. '{error.message}' at '{error.file}:{error.line}'.",
                        context: [
                            ...$logContext,
                            'error' => [
                                'kind' => $e::class,
                                'line' => $e->getLine(),
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                        ],
                        e: $e
                    )
                );
                return;
            }

            if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
                $message = "{action}: Ignoring '{client}: {user}@{backend}' - '{item.title}'. No valid/supported external ids.";

                if (null === ($item['Guid'] ?? null)) {
                    $item['Guid'] = [];
                }

                if (null !== ($itemGuid = ag($item, 'guid')) && false === $guid->isLocal($itemGuid)) {
                    $item['Guid'][] = $itemGuid;
                }

                if (empty($item['Guid'])) {
                    $message .= " Most likely unmatched '{item.type}'.";
                }

                $this->logger->info($message, [
                    ...$logContext,
                    'guids' => !empty($item['Guid']) ? $item['Guid'] : 'None',
                ]);

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_supported_guid");
                return;
            }

            $mapper->add(entity: $entity, opts: [
                'after' => ag($opts, 'after', null),
                Options::IMPORT_METADATA_ONLY => true === (bool)ag($context->options, Options::IMPORT_METADATA_ONLY),
                Options::DISABLE_MARK_UNPLAYED => true === (bool)ag($context->options, Options::DISABLE_MARK_UNPLAYED),
            ]);
        } catch (Throwable $e) {
            $this->logger->error(
                ...lw(
                    message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' - '{library.title}' - '{item.title}' item process. {error.message} at '{error.file}:{error.line}'.",
                    context: [...$logContext, ...exception_log($e)],
                    e: $e
                )
            );
        }
    }
}
