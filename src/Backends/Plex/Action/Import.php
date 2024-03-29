<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexActionTrait;
use App\Backends\Plex\PlexClient;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
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

    private string $action = 'plex.import';

    public function __construct(protected iHttp $http, protected iLogger $logger)
    {
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
                    message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] library [{library.title}] request. Error [{error.message} @ {error.file}:{error.line}].',
                    context: [
                        'backend' => $context->backendName,
                        'client' => $context->clientName,
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        ...$logContext,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                        ],
                    ]
                ),
            ),
            action: $this->action
        );
    }

    protected function getLibraries(Context $context, Closure $handle, Closure $error): array
    {
        $segmentSize = (int)ag($context->options, Options::LIBRARY_SEGMENT, 1000);

        try {
            $url = $context->backendUrl->withPath('/library/sections');

            $this->logger->debug('Requesting [{backend}] libraries.', [
                'backend' => $context->backendName,
                'url' => $url
            ]);

            $response = $this->http->request('GET', (string)$url, $context->backendHeaders);

            $payload = $response->getContent(false);

            if ($context->trace) {
                $this->logger->debug('Processing [{backend}] response.', [
                    'backend' => $context->backendName,
                    'url' => (string)$url,
                    'status_code' => $response->getStatusCode(),
                    'response' => $payload,
                    'headers' => $response->getHeaders(false),
                ]);
            }

            if (200 !== $response->getStatusCode()) {
                $logContext = [
                    'backend' => $context->backendName,
                    'status_code' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(false),
                ];

                if ($context->trace) {
                    $logContext['trace'] = $response->getInfo('debug');
                }

                $this->logger->error(
                    'Request for [{backend}] libraries returned with unexpected [{status_code}] status code.',
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
                $this->logger->warning('Request for [{backend}] libraries returned with empty list.', [
                    'backend' => $context->backendName,
                    'body' => $json,
                ]);
                Message::add("{$context->backendName}.has_errors", true);
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error('Request for [{backend}] libraries has failed.', [
                'backend' => $context->backendName,
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'message' => $e->getMessage(),
                    'trace' => $context->trace ? $e->getTrace() : [],
                ],
            ]);
            Message::add("{$context->backendName}.has_errors", true);
            return [];
        } catch (JsonException $e) {
            $this->logger->error('Request for [{backend}] libraries returned with invalid body.', [
                'backend' => $context->backendName,
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage(),
                    'trace' => $context->trace ? $e->getTrace() : [],
                ],
            ]);
            Message::add("{$context->backendName}.has_errors", true);
            return [];
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] request for libraries. Error [{error.message} @ {error.file}:{error.line}].',
                context: [
                    'backend' => $context->backendName,
                    'client' => $context->clientName,
                    'error' => [
                        'kind' => $e::class,
                        'line' => $e->getLine(),
                        'message' => $e->getMessage(),
                        'file' => after($e->getFile(), ROOT_PATH),
                    ],
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $context->trace ? $e->getTrace() : [],
                    ],
                ]
            );
            Message::add("{$context->backendName}.has_errors", true);
            return [];
        }

        if (null !== ($ignoreIds = ag($context->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => (int)trim($v), explode(',', (string)$ignoreIds));
        }

        $requests = $total = [];
        $ignored = $unsupported = 0;

        // -- Get library items count.
        foreach ($listDirs as $section) {
            $key = (int)ag($section, 'key');

            $logContext = [
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

            if (!in_array(ag($logContext, 'library.type'), [PlexClient::TYPE_MOVIE, PlexClient::TYPE_SHOW])) {
                continue;
            }

            if (!in_array(ag($logContext, 'library.agent'), PlexClient::SUPPORTED_AGENTS)) {
                $this->logger->notice(
                    'Ignoring [{backend}] [{library.title}] Unsupported agent type.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                    ]
                );
                continue;
            }

            $isMovieLibrary = PlexClient::TYPE_MOVIE === ag($logContext, 'library.type');

            $url = $context->backendUrl->withPath(
                r('/library/sections/{library_id}/all', ['library_id' => $key])
            )->withQuery(
                http_build_query([
                    'includeGuids' => 1,
                    'type' => $isMovieLibrary ? 1 : 4,
                    'sort' => $isMovieLibrary ? 'addedAt' : 'episode.addedAt',
                    'X-Plex-Container-Start' => 0,
                    'X-Plex-Container-Size' => $segmentSize,
                ])
            );

            $logContext['library']['url'] = $url;

            $this->logger->debug('Requesting [{backend}] [{library.title}] items count.', [
                'backend' => $context->backendName,
                ...$logContext,
            ]);

            try {
                $requests[] = $this->http->request(
                    'HEAD',
                    (string)$url,
                    array_replace_recursive($context->backendHeaders, [
                        'headers' => [
                            'X-Plex-Container-Start' => 0,
                            'X-Plex-Container-Size' => $segmentSize,
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

                    $this->logger->debug('Requesting [{backend}] [{library.title}] tv shows count.', [
                        'backend' => $context->backendName,
                        ...$logContextSub,
                    ]);

                    $requests[] = $this->http->request(
                        'HEAD',
                        (string)$logContextSub['library']['url'],
                        array_replace_recursive($context->backendHeaders, [
                            'headers' => [
                                'X-Plex-Container-Start' => 0,
                                'X-Plex-Container-Size' => $segmentSize,
                            ],
                            'user_data' => [
                                'isShowRequest' => true,
                                ...$logContextSub
                            ],
                        ])
                    );
                }
            } catch (ExceptionInterface $e) {
                $this->logger->error('Request for [{backend}] [{library.title}] items count has failed.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $context->trace ? $e->getTrace() : [],
                    ],
                ]);
                continue;
            } catch (Throwable $e) {
                $this->logger->error(
                    message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] [{library.title}] items count request. Error [{error.message} @ {error.file}:{error.line}].',
                    context: [
                        'backend' => $context->backendName,
                        'client' => $context->clientName,
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        ...$logContext,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                            'trace' => $context->trace ? $e->getTrace() : [],
                        ],
                    ]
                );
                continue;
            }
        }

        // -- Parse libraries items count.
        foreach ($requests as $response) {
            $logContext = ag($response->getInfo('user_data'), []);

            try {
                if (200 !== $response->getStatusCode()) {
                    $this->logger->error(
                        'Request for [{backend}] [{library.title}] items count returned with unexpected [{status_code}] status code.',
                        [
                            'backend' => $context->backendName,
                            'status_code' => $response->getStatusCode(),
                            ...$logContext,
                        ]
                    );
                    continue;
                }

                $totalCount = (int)(ag($response->getHeaders(), 'x-plex-container-total-size')[0] ?? 0);

                if ($totalCount < 1) {
                    $this->logger->warning(
                        'Request for [{backend}] [{library.title}] items count returned with 0 or less.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                            'headers' => $response->getHeaders(),
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
                $this->logger->error('Request for [{backend}] [{library.title}] total items has failed.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $context->trace ? $e->getTrace() : [],
                    ],
                ]);
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
                'library' => [
                    'id' => $key,
                    'title' => ag($section, 'title', '??'),
                    'type' => ag($section, 'type', 'unknown'),
                    'url' => $url,
                ],
            ];

            if (false === array_key_exists('show_' . $key, $total)) {
                $ignored++;
                $this->logger->warning('Ignoring [{backend}] [{library.title}]. No tv shows items count was found.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                ]);
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
                        'Requesting [{backend}] [{library.title}] [{segment.number}/{segment.of}] series external ids.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                        ]
                    );

                    $requests[] = $this->http->request(
                        'GET',
                        (string)$url,
                        array_replace_recursive($context->backendHeaders, [
                            'headers' => [
                                'X-Plex-Container-Size' => $segmentSize,
                                'X-Plex-Container-Start' => $i < 1 ? 0 : ($segmentSize * $i),
                            ],
                            'user_data' => [
                                'ok' => $handle($logContext),
                                'error' => $error($logContext),
                            ]
                        ])
                    );
                } catch (ExceptionInterface $e) {
                    $this->logger->error(
                        'Request for [{backend}] [{library.title}] [{segment.number}/{segment.of}] series external ids has failed.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                                'trace' => $context->trace ? $e->getTrace() : [],
                            ],
                        ]
                    );
                    continue;
                } catch (Throwable $e) {
                    $this->logger->error(
                        message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] [{library.title}] [{segment.number}/{segment.of}] series external ids request. Error [{error.message} @ {error.file}:{error.line}].',
                        context: [
                            'backend' => $context->backendName,
                            'client' => $context->clientName,
                            'error' => [
                                'kind' => $e::class,
                                'line' => $e->getLine(),
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                            ...$logContext,
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                                'trace' => $context->trace ? $e->getTrace() : [],
                            ],
                        ]
                    );
                    continue;
                }
            }
        }

        // -- get paginated movies/episodes.
        foreach ($listDirs as $section) {
            $key = (int)ag($section, 'key');

            $logContext = [
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
                $this->logger->info('Ignoring [{backend}] [{library.title}]. Requested by user config.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                ]);
                continue;
            }

            if (!in_array(ag($logContext, 'library.type'), [PlexClient::TYPE_MOVIE, PlexClient::TYPE_SHOW])) {
                $unsupported++;
                $this->logger->info(
                    'Ignoring [{backend}] [{library.title}]. Library type [{library.type}] is not supported.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                    ]
                );
                continue;
            }

            if (false === array_key_exists($key, $total)) {
                $ignored++;
                $this->logger->warning('Ignoring [{backend}] [{library.title}]. No items count was found.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                ]);
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

                    $url = $context->backendUrl->withPath(
                        r('/library/sections/{library_id}/all', ['library_id' => $key])
                    )
                        ->withQuery(
                            http_build_query(
                                [
                                    'includeGuids' => 1,
                                    'type' => $isMovieLibrary ? 1 : 4,
                                    'sort' => $isMovieLibrary ? 'addedAt' : 'episode.addedAt',
                                    'X-Plex-Container-Size' => $segmentSize,
                                    'X-Plex-Container-Start' => $i < 1 ? 0 : ($segmentSize * $i),
                                ]
                            )
                        );

                    $logContext['library']['url'] = $url;

                    $this->logger->debug(
                        'Requesting [{backend}] [{library.title}] [{segment.number}/{segment.of}] content list.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                        ]
                    );

                    $requests[] = $this->http->request(
                        'GET',
                        (string)$url,
                        array_replace_recursive($context->backendHeaders, [
                            'headers' => [
                                'X-Plex-Container-Size' => $segmentSize,
                                'X-Plex-Container-Start' => $i < 1 ? 0 : ($segmentSize * $i),
                            ],
                            'user_data' => [
                                'ok' => $handle($logContext),
                                'error' => $error($logContext),
                            ]
                        ]),
                    );
                } catch (ExceptionInterface $e) {
                    $this->logger->error(
                        'Request for [{backend}] [{library.title}] [{segment.number}/{segment.of}] content list has failed.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                                'trace' => $context->trace ? $e->getTrace() : [],
                            ],
                        ]
                    );
                    continue;
                } catch (Throwable $e) {
                    $this->logger->error(
                        message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] [{library.title}] [{segment.number}/{segment.of}] content list request. Error [{error.message} @ {error.file}:{error.line}].',
                        context: [
                            'backend' => $context->backendName,
                            'client' => $context->clientName,
                            'error' => [
                                'kind' => $e::class,
                                'line' => $e->getLine(),
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                            ...$logContext,
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                                'trace' => $context->trace ? $e->getTrace() : [],
                            ],
                        ]
                    );
                    continue;
                }
            }
        }

        if (0 === count($requests)) {
            $this->logger->warning('No requests for [{backend}] libraries were queued.', [
                'backend' => $context->backendName,
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
     */
    protected function handle(Context $context, iResponse $response, Closure $callback, array $logContext = []): void
    {
        if (200 !== $response->getStatusCode()) {
            $this->logger->error(
                'Request for [{backend}] [{library.title}] [{segment.number}/{segment.of}] content returned with unexpected [{status_code}] status code.',
                [
                    'backend' => $context->backendName,
                    'status_code' => $response->getStatusCode(),
                    ...$logContext,
                ]
            );
            return;
        }

        $start = makeDate();
        $this->logger->info(
            'Parsing [{backend}] library [{library.title}] [{segment.number}/{segment.of}] response.',
            [
                'backend' => $context->backendName,
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
                if ($entity instanceof DecodingError) {
                    $this->logger->warning(
                        'Failed to decode one item of [{backend}] [{library.title}] [{segment.number}/{segment.of}] content.',
                        [
                            'backend' => $context->backendName,
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
            }
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] parsing of [{library.title}] [{segment.number}/{segment.of}] response. Error [{error.message} @ {error.file}:{error.line}].',
                context: [
                    'backend' => $context->backendName,
                    'client' => $context->clientName,
                    'error' => [
                        'kind' => $e::class,
                        'line' => $e->getLine(),
                        'message' => $e->getMessage(),
                        'file' => after($e->getFile(), ROOT_PATH),
                    ],
                    ...$logContext,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $context->trace ? $e->getTrace() : [],
                    ],
                ]
            );
        }

        $end = makeDate();
        $this->logger->info(
            'Parsing [{backend}] library [{library.title}] [{segment.number}/{segment.of}] response is complete.',
            [
                'backend' => $context->backendName,
                ...$logContext,
                'time' => [
                    'start' => $start,
                    'end' => $end,
                    'duration' => number_format($end->getTimestamp() - $start->getTimestamp()),
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
            $this->logger->debug('Processing [{backend}] {item.type} [{item.title} ({item.year})] payload.', [
                'backend' => $context->backendName,
                ...$logContext,
                'body' => $item,
            ]);
        }

        if (!$guid->has(guids: $guids, context: $logContext)) {
            $message = 'Ignoring [{backend}] [{item.title}]. {item.type} has no valid/supported external ids.';

            if (empty($guids)) {
                $message .= ' Most likely unmatched {item.type}.';
            }

            $this->logger->info($message, [
                'backend' => $context->backendName,
                ...$logContext,
                'data' => [
                    'guids' => !empty($item['Guid']) ? $item['Guid'] : 'None'
                ],
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
                context: ['backend' => $context->backendName, ...$logContext]
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
                $this->logger->debug('Processing [{backend}] payload.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'body' => $item,
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
                            r('Unexpected Content type [{type}] was received.', [
                                'type' => $type
                            ])
                        ),
                    },
                    'type' => ag($item, 'type', 'unknown'),
                ];
            } catch (InvalidArgumentException $e) {
                $this->logger->error($e->getMessage(), [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'body' => $item,
                ]);
                return;
            }

            if (null === ag($item, true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt')) {
                $this->logger->debug('Ignoring [{backend}] {item.type} [{item.title}]. No Date is set on object.', [
                    'backend' => $context->backendName,
                    'date_key' => true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt',
                    ...$logContext,
                    'body' => $item,
                ]);

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_date_is_set");
                return;
            }

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

            if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
                $message = 'Ignoring [{backend}] [{item.title}]. No valid/supported external ids.';

                if (null === ($item['Guid'] ?? null)) {
                    $item['Guid'] = [];
                }

                if (null !== ($itemGuid = ag($item, 'guid')) && false === $guid->isLocal($itemGuid)) {
                    $item['Guid'][] = $itemGuid;
                }

                if (empty($item['Guid'])) {
                    $message .= ' Most likely unmatched {item.type}.';
                }

                $this->logger->info($message, [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'guids' => !empty($item['Guid']) ? $item['Guid'] : 'None'
                ]);

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_supported_guid");
                return;
            }

            $mapper->add(entity: $entity, opts: [
                'after' => ag($opts, 'after', null),
                Options::IMPORT_METADATA_ONLY => true === (bool)ag($context->options, Options::IMPORT_METADATA_ONLY),
            ]);
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] [{library.title}] [{item.title}] import. Error [{error.message} @ {error.file}:{error.line}].',
                context: [
                    'backend' => $context->backendName,
                    'client' => $context->clientName,
                    'error' => [
                        'kind' => $e::class,
                        'line' => $e->getLine(),
                        'message' => $e->getMessage(),
                        'file' => after($e->getFile(), ROOT_PATH),
                    ],
                    ...$logContext,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $context->trace ? $e->getTrace() : [],
                    ],
                ]
            );
        }
    }
}
