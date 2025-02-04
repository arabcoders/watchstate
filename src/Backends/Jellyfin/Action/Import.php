<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Backends\Jellyfin\JellyfinClient as JFC;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use Closure;
use DateTimeInterface as iDate;
use InvalidArgumentException;
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

/**
 * Class Import
 *
 * This class is responsible for importing data from Jellyfin API.
 *
 * This class is very central To processing the backend library data. Any class that needs to process the
 * entire backend data should extend this class and override the process method.
 */
class Import
{
    use CommonTrait;
    use JellyfinActionTrait;

    /**
     * @var string Action name.
     */
    protected string $action = 'jellyfin.import';

    /**
     * Constructor method for the class.
     *
     * @param iHttp $http The HTTP client instance.
     * @param iLogger $logger The logger instance.
     */
    public function __construct(protected iHttp $http, protected iLogger $logger)
    {
    }

    /**
     * Wrap the import process in try response block.
     *
     * @param Context $context Backend context.
     * @param iGuid $guid Guid instance.
     * @param iImport $mapper Mapper instance.
     * @param iDate|null $after Process items after this date.
     * @param array $opts (Optional) Options.
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
                    message: "Exception '{error.kind}' was thrown unhandled during '{client}: {backend}' library '{library.title}' request. '{error.message}' at '{error.file}:{error.line}'.",
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
                opts: $opts
            ),
            action: $this->action,
        );
    }

    /**
     * Retrieves the libraries for a given context.
     *
     * @param Context $context Backend context.
     * @param Closure $handle The closure to handle a successful response.
     * @param Closure $error The closure to handle an error response.
     * @param array $opts (Optional) Options.
     *
     * @return array The array of libraries retrieved from the backend.
     */
    protected function getLibraries(Context $context, Closure $handle, Closure $error, array $opts = []): array
    {
        try {
            $url = $context->backendUrl->withPath(r('/Users/{user_id}/items/', ['user_id' => $context->backendUser]));

            $this->logger->debug("Requesting '{client}: {backend}' libraries.", [
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'url' => $url
            ]);

            $response = $this->http->request('GET', (string)$url, $context->backendHeaders);

            $payload = $response->getContent(false);

            if ($context->trace) {
                $this->logger->debug("Processing '{client}: {backend}' response.", [
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'url' => (string)$url,
                    'status_code' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(false),
                    'response' => $payload,
                ]);
            }

            if (200 !== $response->getStatusCode()) {
                $logContext = [
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'status_code' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(false),
                ];

                if ($context->trace) {
                    $logContext['trace'] = $response->getInfo('debug');
                }

                $this->logger->error(
                    "Request for '{client}: {backend}' libraries returned with unexpected '{status_code}' status code.",
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

            $listDirs = ag($json, 'Items', []);

            if (empty($listDirs)) {
                $this->logger->warning("Request for '{client}: {backend}' libraries returned with empty list.", [
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'body' => $payload,
                ]);
                Message::add("{$context->backendName}.has_errors", true);
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error(
                ...lw(
                    message: "Request for '{client}: {backend}' libraries has failed. '{error.kind}' with message '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                        'error' => [
                            'line' => $e->getLine(),
                            'kind' => $e::class,
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => $e::class,
                            'message' => $e->getMessage(),
                            'trace' => $e->getTrace(),
                        ],
                    ],
                    e: $e
                )
            );
            Message::add("{$context->backendName}.has_errors", true);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                ...lw(
                    message: "Request for '{client}: {backend}' libraries returned with invalid body. '{error.kind}' with message '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                        'error' => [
                            'line' => $e->getLine(),
                            'kind' => $e::class,
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'trace' => $e->getTrace(),
                        ],
                    ],
                    e: $e
                )
            );
            Message::add("{$context->backendName}.has_errors", true);
            return [];
        } catch (Throwable $e) {
            $this->logger->error(
                ...lw(
                    message: "Exception '{error.kind}' was thrown unhandled during '{client}: {backend}' request for libraries. '{error.message}' at '{error.file}:{error.line}'.",
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
                            'trace' => $e->getTrace(),
                        ],
                    ],
                    e: $e
                )
            );
            Message::add("{$context->backendName}.has_errors", true);
            return [];
        }

        if (null !== ($ignoreIds = ag($context->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', (string)$ignoreIds));
        }

        $limitLibraryId = ag($opts, Options::ONLY_LIBRARY_ID, null);

        $requests = $total = [];
        $ignored = $unsupported = 0;

        // -- Get library items count.
        foreach ($listDirs as $section) {
            $libraryId = (string)ag($section, 'Id');

            $logContext = [
                'library' => [
                    'id' => $libraryId,
                    'title' => ag($section, 'Name', '??'),
                    'type' => ag($section, 'CollectionType', 'unknown'),
                ],
            ];

            if (null !== $limitLibraryId && $libraryId !== (string)$limitLibraryId) {
                continue;
            }

            if (true === in_array(ag($logContext, 'library.id'), $ignoreIds ?? [])) {
                continue;
            }

            if (!in_array(ag($logContext, 'library.type'), [JFC::COLLECTION_TYPE_SHOWS, JFC::COLLECTION_TYPE_MOVIES])) {
                continue;
            }

            $url = $context->backendUrl->withPath(
                r('/Users/{user_id}/items/', [
                    'user_id' => $context->backendUser
                ])
            )->withQuery(
                http_build_query([
                    'sortBy' => 'DateCreated',
                    'sortOrder' => 'Ascending',
                    'parentId' => ag($logContext, 'library.id'),
                    'recursive' => 'true',
                    'excludeLocationTypes' => 'Virtual',
                    'includeItemTypes' => implode(',', [JFC::TYPE_MOVIE, JFC::TYPE_EPISODE]),
                    'startIndex' => 0,
                    'limit' => 0,
                ])
            );

            $logContext['library']['url'] = (string)$url;

            $this->logger->debug('Requesting [{backend}] [{library.title}] items count.', [
                'backend' => $context->backendName,
                ...$logContext,
            ]);

            try {
                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($context->backendHeaders, [
                        'user_data' => $logContext
                    ])
                );
            } catch (ExceptionInterface $e) {
                $this->logger->error(
                    ...lw(
                        message: "Request for '{client}: {backend}' '{library.title}' items count failed. '{error.kind}' with message '{error.message}' at '{error.file}:{error.line}'.",
                        context: [
                            'client' => $context->clientName,
                            'backend' => $context->backendName,
                            'error' => [
                                'line' => $e->getLine(),
                                'kind' => $e::class,
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                                'trace' => $e->getTrace(),
                            ],
                            ...$logContext,
                        ],
                        e: $e
                    )
                );
                continue;
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "Exception '{error.kind}' was thrown unhandled during '{client}: {backend}' '{library.title}' items count request. '{error.message}' at '{error.file}:{error.line}'.",
                        context: [
                            'client' => $context->clientName,
                            'backend' => $context->backendName,
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
                                'trace' => $e->getTrace(),
                            ],
                            ...$logContext,
                        ],
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
                if (200 !== $response->getStatusCode()) {
                    $this->logger->error(
                        message: "Request for '{client}: {backend}' '{library.title}' items count returned with unexpected '{status_code}' status code.",
                        context: [
                            'client' => $context->clientName,
                            'backend' => $context->backendName,
                            'status_code' => $response->getStatusCode(),
                            ...$logContext,
                        ]
                    );
                    continue;
                }

                $json = json_decode($response->getContent(), true);

                $totalCount = (int)(ag($json, 'TotalRecordCount', 0));

                if ($totalCount < 1) {
                    $this->logger->warning(
                        message: "Request for '{client}: {backend}' library '{library.title}' items count returned with 0 or less.",
                        context: [
                            'client' => $context->clientName,
                            'backend' => $context->backendName,
                            ...$logContext,
                            'headers' => $response->getHeaders(),
                        ]
                    );
                    continue;
                }

                $total[ag($logContext, 'library.id')] = $totalCount;
            } catch (ExceptionInterface $e) {
                $this->logger->error(
                    ...lw(
                    message: "Request for '{client}: {backend}' '{library.title}' total items has failed. '{error.kind}' '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'client' => $context->backendName,
                        'backend' => $context->backendName,
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
                            'trace' => $e->getTrace(),
                        ],
                        ...$logContext,
                    ],
                    e: $e
                ),
                );
                continue;
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "Exception '{error.kind}' was thrown unhandled during '{client}: {backend}' requests for items count. '{error.message}' at '{error.file}:{error.line}'.",
                        context: [
                            'client' => $context->clientName,
                            'backend' => $context->backendName,
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
                                'trace' => $e->getTrace(),
                            ],
                            ...$logContext,
                        ],
                        e: $e
                    )
                );
                continue;
            }
        }

        $requests = [];

        // -- Episodes Parent external ids.
        foreach ($listDirs as $section) {
            $logContext = [
                'library' => [
                    'id' => (string)ag($section, 'Id'),
                    'title' => ag($section, 'Name', '??'),
                    'type' => ag($section, 'CollectionType', 'unknown'),
                ],
                'segment' => [
                    'number' => 1,
                    'of' => 1,
                ],
            ];

            if (JFC::COLLECTION_TYPE_SHOWS !== ag($logContext, 'library.type')) {
                continue;
            }

            if (true === in_array(ag($logContext, 'library.id'), $ignoreIds ?? [])) {
                continue;
            }

            if (true === array_key_exists(ag($logContext, 'library.id'), $total)) {
                $logContext['library']['totalRecords'] = $total[ag($logContext, 'library.id')];
            }

            $url = $context->backendUrl->withPath(r('/Users/{user_id}/items/', [
                'user_id' => $context->backendUser
            ]))->withQuery(
                http_build_query([
                    'parentId' => ag($logContext, 'library.id'),
                    'recursive' => 'false',
                    'enableUserData' => 'false',
                    'enableImages' => 'false',
                    'fields' => implode(',', JFC::EXTRA_FIELDS),
                    'excludeLocationTypes' => 'Virtual',
                ])
            );

            $logContext['library']['url'] = (string)$url;

            $this->logger->debug("Requesting '{client}: {backend}' '{library.title}' series external ids.", [
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    ...$logContext
                ]
            );

            try {
                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($context->backendHeaders, [
                        'user_data' => [
                            'ok' => $handle($logContext),
                            'error' => $error($logContext),
                        ]
                    ])
                );
            } catch (ExceptionInterface $e) {
                $this->logger->error(
                    ...lw(
                        message: "Request for '{client}: {backend}' '{library.title}' series external ids has failed. '{error.kind}' with message '{error.message}' at '{error.file}:{error.line}'.",
                        context: [
                            'client' => $context->clientName,
                            'backend' => $context->backendName,
                            'error' => [
                                'line' => $e->getLine(),
                                'kind' => $e::class,
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                                'trace' => $e->getTrace(),
                            ],
                            ...$logContext,
                        ],
                        e: $e
                    )
                );
                continue;
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                    message: "Exception '{error.kind}' was thrown unhandled during '{client}: {backend}' '{library.title}' series external ids request. '{error.message}' at '{error.file}:{error.line}'.",
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
                            'trace' => $e->getTrace(),
                        ],
                        ...$logContext,
                    ],
                    e: $e
                ),
                );
                continue;
            }
        }

        // -- get paginated movies/episodes.
        foreach ($listDirs as $section) {
            $logContext = [
                'library' => [
                    'id' => (string)ag($section, 'Id'),
                    'title' => ag($section, 'Name', '??'),
                    'type' => ag($section, 'CollectionType', 'unknown'),
                ],
            ];

            if (true === in_array(ag($logContext, 'library.id'), $ignoreIds ?? [])) {
                $ignored++;
                $this->logger->info(
                    "Ignoring '{client}: {backend}' library '{library.title}'. Requested by user.",
                    [
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                        ...$logContext,
                    ]
                );
                continue;
            }

            if (!in_array(ag($logContext, 'library.type'), [JFC::COLLECTION_TYPE_SHOWS, JFC::COLLECTION_TYPE_MOVIES])) {
                $unsupported++;
                $this->logger->info(
                    "Ignoring '{client}: {backend}' '{library.title}'. Library type '{library.type}' is not supported.",
                    [
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                        ...$logContext,
                    ]
                );
                continue;
            }

            if (false === array_key_exists(ag($logContext, 'library.id'), $total)) {
                $ignored++;
                $this->logger->warning("Ignoring '{client}: {backend}' '{library.title}'. No items count was found.", [
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    ...$logContext,
                ]);
                continue;
            }

            $logContext['library']['totalRecords'] = $total[ag($logContext, 'library.id')];

            $segmentTotal = (int)$total[ag($logContext, 'library.id')];
            $segmentSize = (int)ag($context->options, Options::LIBRARY_SEGMENT, 1000);
            $segmented = ceil($segmentTotal / $segmentSize);

            for ($i = 0; $i < $segmented; $i++) {
                try {
                    $logContext['segment'] = [
                        'number' => $i + 1,
                        'of' => $segmented,
                        'size' => $segmentSize,
                    ];

                    $url = $context->backendUrl->withPath(r('/Users/{user_id}/items/', [
                        'user_id' => $context->backendUser
                    ]))->withQuery(
                        http_build_query([
                            'sortBy' => 'DateCreated',
                            'sortOrder' => 'Ascending',
                            'parentId' => ag($logContext, 'library.id'),
                            'recursive' => 'true',
                            'enableUserData' => 'true',
                            'enableImages' => 'false',
                            'excludeLocationTypes' => 'Virtual',
                            'fields' => implode(',', JFC::EXTRA_FIELDS),
                            'includeItemTypes' => implode(',', [JFC::TYPE_MOVIE, JFC::TYPE_EPISODE]),
                            'limit' => $segmentSize,
                            'startIndex' => $i < 1 ? 0 : ($segmentSize * $i),
                        ])
                    );

                    $logContext['library']['url'] = (string)$url;

                    $this->logger->debug(
                        "Requesting '{client}: {backend}' '{library.title} {segment.number}/{segment.of}' content list.",
                        [
                            'client' => $context->clientName,
                            'backend' => $context->backendName,
                            ...$logContext,
                        ]
                    );

                    $requests[] = $this->http->request(
                        'GET',
                        (string)$url,
                        array_replace_recursive($context->backendHeaders, [
                            'user_data' => [
                                'ok' => $handle($logContext),
                                'error' => $error($logContext),
                            ]
                        ])
                    );
                } catch (ExceptionInterface $e) {
                    $this->logger->error(
                        ...lw(
                            message: "Request for '{client}: {backend}' '{library.title} {segment.number}/{segment.of}' content list has failed. {error.kind}' with message '{error.message}' at '{error.file}:{error.line}'.",
                            context: [
                                'client' => $context->clientName,
                                'backend' => $context->backendName,
                                'error' => [
                                    'line' => $e->getLine(),
                                    'kind' => $e::class,
                                    'message' => $e->getMessage(),
                                    'file' => after($e->getFile(), ROOT_PATH),
                                ],
                                ...$logContext,
                                'exception' => [
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'kind' => get_class($e),
                                    'message' => $e->getMessage(),
                                    'trace' => $e->getTrace(),
                                ],
                            ],
                            e: $e
                        )
                    );
                    continue;
                } catch (Throwable $e) {
                    $this->logger->error(
                        ...lw(
                            message: "Exception '{error.kind}' was thrown unhandled during '{client}: {backend}' '{library.title} {segment.number}/{segment.of}' content list request. '{error.message}' at '{error.file}:{error.line}'.",
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
                                    'trace' => $e->getTrace(),
                                ],
                            ],
                            e: $e
                        )
                    );
                    continue;
                }
            }
        }

        if (0 === count($requests)) {
            $this->logger->warning("No requests for '{client}: {backend}' libraries were queued.", [
                'client' => $context->clientName,
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
     * Method to handle the library response.
     *
     * @param Context $context Backend context.
     * @param iResponse $response The response object.
     * @param Closure $callback The callback function to be executed for each item.
     * @param array $logContext (optional) logging context.
     *
     * @throws TransportExceptionInterface When the transport fails.
     */
    protected function handle(Context $context, iResponse $response, Closure $callback, array $logContext = []): void
    {
        if (200 !== $response->getStatusCode()) {
            $this->logger->error(
                "Request for '{client}: {backend}' '{library.title} {segment.number}/{segment.of}' content returned with unexpected '{status_code}' status code.",
                [
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'status_code' => $response->getStatusCode(),
                    ...$logContext,
                ]
            );
            return;
        }

        $start = microtime(true);
        $this->logger->info(
            "Parsing '{client}: {backend}' library '{library.title} {segment.number}/{segment.of}' response.",
            [
                'client' => $context->clientName,
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
                    'pointer' => '/Items',
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
                            "Failed to decode one item of '{client}: {backend}' '{library.title} {segment.number}/{segment.of}' content.",
                            [
                                'client' => $context->clientName,
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

                    // -- Handle multi episode entries.
                    $indexNumber = ag($entity, 'IndexNumber');
                    $indexNumberEnd = ag($entity, 'IndexNumberEnd');
                    if (null !== $indexNumber && null !== $indexNumberEnd && $indexNumberEnd > $indexNumber) {
                        $episodeRangeLimit = (int)ag($context->options, Options::MAX_EPISODE_RANGE, 5);
                        $range = range(ag($entity, 'IndexNumber'), $indexNumberEnd);
                        if (count($range) > $episodeRangeLimit) {
                            $this->logger->warning(
                                "Ignoring '{client}: {backend}' '{library.title} {segment.number}/{segment.of}' {item.type} '{item.id}: {item.title}' episode range, and treating it as single episode. Backend says it covers '{item.indexNumber}-{item.indexNumberEnd}' '{item.rangeCount}' The limit is '{rangeLimit}' per record.",
                                [
                                    'client' => $context->clientName,
                                    'backend' => $context->backendName,
                                    'rangeLimit' => $episodeRangeLimit,
                                    'item' => [
                                        'id' => ag($entity, 'Id'),
                                        'title' => ag($entity, ['Name', 'OriginalTitle'], '??'),
                                        'type' => ag($entity, 'Type'),
                                        'rangeCount' => count($range),
                                        'indexNumber' => ag($entity, 'IndexNumber'),
                                        'indexNumberEnd' => $indexNumberEnd,
                                    ],
                                    ...$logContext,
                                ]
                            );
                            $callback(item: $entity, logContext: $logContext);
                        } else {
                            foreach ($range as $i) {
                                $this->logger->debug(
                                    "Making virtual episode '{backend}' '{library.title}] [{segment.number}/{segment.of}' {item.type} '{item.id}: {item.title}' '{item.indexNumber} => {item.i} of {item.indexNumberEnd}'.",
                                    [
                                        'backend' => $context->backendName,
                                        ...$logContext,
                                        'item' => [
                                            'id' => ag($entity, 'Id'),
                                            'title' => ag($entity, ['Name', 'OriginalTitle'], '??'),
                                            'type' => ag($entity, 'Type'),
                                            'i' => $i,
                                            'indexNumber' => $indexNumber,
                                            'indexNumberEnd' => $indexNumberEnd,
                                        ],
                                    ]
                                );
                                $entity['IndexNumber'] = $i;
                                $callback(item: $entity, logContext: $logContext);
                            }
                        }
                    } else {
                        $callback(item: $entity, logContext: $logContext);
                    }
                } catch (Throwable $e) {
                    $this->logger->error(
                        ...lw(
                            message: "Exception '{error.kind}' was thrown unhandled during '{client}: {backend}' parsing '{library.title} {segment.number}/{segment.of}' item response. '{error.message}' at '{error.file}:{error.line}'.",
                            context: [
                                'backend' => $context->backendName,
                                'client' => $context->clientName,
                                'error' => [
                                    'kind' => $e::class,
                                    'line' => $e->getLine(),
                                    'message' => $e->getMessage(),
                                    'file' => after($e->getFile(), ROOT_PATH),
                                ],
                                'entity' => $entity,
                                'exception' => [
                                    'kind' => $e::class,
                                    'line' => $e->getLine(),
                                    'trace' => $e->getTrace(),
                                    'message' => $e->getMessage(),
                                    'file' => after($e->getFile(), ROOT_PATH),
                                ],
                                ...$logContext,
                            ],
                            e: $e
                        )
                    );
                }
            }
        } catch (Throwable $e) {
            $this->logger->error(
                ...lw(
                    message: "Exception '{error.kind}' was thrown unhandled during '{client}: {backend}' parsing of '{library.title} {segment.number}/{segment.of}' response. '{error.message}' at '{error.file}:{error.line}'.",
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
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                            'trace' => $e->getTrace(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        ...$logContext,
                    ],
                    e: $e
                )
            );
        }

        $end = microtime(true);
        $this->logger->info(
            "Parsing '{client}: {backend}' library '{library.title} {segment.number}/{segment.of}' completed in '{time.duration}'s.",
            [
                'client' => $context->clientName,
                'backend' => $context->backendName,
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

    /**
     * Process TV Show details.
     *
     * @param Context $context Backend context.
     * @param iGuid $guid The GUID object.
     * @param array $item The item array.
     * @param array $logContext (optional) log context.
     */
    protected function processShow(Context $context, iGuid $guid, array $item, array $logContext = []): void
    {
        $logContext['item'] = [
            'id' => ag($item, 'Id'),
            'title' => r('{title} ({year})', [
                'title' => ag($item, ['Name', 'OriginalTitle'], '??'),
                'year' => ag($item, 'ProductionYear', '0000'),
            ]),
            'year' => ag($item, 'ProductionYear', null),
            'type' => ag($item, 'Type'),
        ];

        if ($context->trace) {
            $this->logger->debug("Processing '{client}: {backend}' {item.type} '{item.title} ({item.year})' payload.", [
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'body' => $item,
                ...$logContext,
            ]);
        }

        $providersId = (array)ag($item, 'ProviderIds', []);

        if (false === $guid->has(guids: $providersId, context: $logContext)) {
            $message = "Ignoring '{client}: {backend}' '{item.title}'. {item.type} has no valid/supported external ids.";

            if (empty($providersId)) {
                $message .= ' Most likely unmatched {item.type}.';
            }

            $this->logger->info($message, [
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'guids' => !empty($providersId) ? $providersId : 'None',
                ...$logContext,
            ]);

            return;
        }

        $context->cache->set(
            JFC::TYPE_SHOW . '.' . ag($logContext, 'item.id'),
            Guid::fromArray(
                payload: $guid->get(guids: $providersId, context: $logContext),
                context: [
                    'backend' => $context->backendName,
                    ...$logContext,
                ]
            )->getAll()
        );
    }

    /**
     * Process each item.
     *
     * @param Context $context Backend context.
     * @param iGuid $guid GUID Parser.
     * @param iImport $mapper Mapper instance.
     * @param array $item The input item array.
     * @param array $logContext (Optional) log context.
     * @param array $opts (Optional) options.
     */
    protected function process(
        Context $context,
        iGuid $guid,
        iImport $mapper,
        array $item,
        array $logContext = [],
        array $opts = []
    ): void {
        if (JFC::TYPE_SHOW === ($type = ag($item, 'Type'))) {
            $this->processShow(context: $context, guid: $guid, item: $item, logContext: $logContext);
            return;
        }

        $mappedType = JFC::TYPE_MAPPER[$type] ?? $type;

        try {
            if ($context->trace) {
                $this->logger->debug("Processing '{client}: {backend}' response payload.", [
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    ...$logContext,
                    'body' => $item,
                ]);
            }

            Message::increment("{$context->backendName}.{$mappedType}.total");

            try {
                $logContext['item'] = [
                    'id' => ag($item, 'Id'),
                    'title' => match ($type) {
                        JFC::TYPE_MOVIE => r('{title} ({year})', [
                            'title' => ag($item, ['Name', 'OriginalTitle'], '??'),
                            'year' => ag($item, 'ProductionYear', 0000),
                        ]),
                        JFC::TYPE_EPISODE => r('{title} - ({season}x{episode})', [
                            'title' => ag($item, 'SeriesName', '??'),
                            'season' => str_pad((string)ag($item, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                            'episode' => str_pad((string)ag($item, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                        ]),
                        default => throw new InvalidArgumentException(
                            r('Unexpected Content type [{type}] was received.', [
                                'type' => $type
                            ])
                        ),
                    },
                    'type' => ag($item, 'Type'),
                ];
            } catch (InvalidArgumentException $e) {
                $this->logger->error(
                    ...lw(
                        message: "Failed to parse '{client}: {backend}' item response. '{error.kind}' with '{error.message}' at '{error.file}:{error.line}' ",
                        context: [
                            'client' => $context->clientName,
                            'backend' => $context->backendName,
                            'error' => [
                                'kind' => $e::class,
                                'line' => $e->getLine(),
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                            'body' => $item,
                            ...$logContext,
                        ],
                        e: $e
                    )
                );
                return;
            }

            $isPlayed = true === (bool)ag($item, 'UserData.Played');
            $dateKey = true === $isPlayed ? 'UserData.LastPlayedDate' : 'DateCreated';

            /**
             * this code is workaround for bug in emby sometimes marking items as played
             * without adding UserData.LastPlayedDate data field to userData. thus, it will
             * trigger the No Date is set check.
             */
            if (true === $isPlayed && false === ag_exists($item, 'UserData.LastPlayedDate')) {
                if ($context->trace) {
                    $this->logger->debug(
                        "The {item.type} '{item.id}: {item.title}' at '{client}: {backend}' is marked as played without LastPlayedDate field.",
                        [
                            'client' => $context->clientName,
                            'backend' => $context->backendName,
                            'body' => $item,
                            ...$logContext,
                        ]
                    );
                }
                $dateKey = 'DateCreated';
            }

            if (null === ag($item, $dateKey)) {
                $this->logger->debug(
                    message: "Ignoring '{client}: {backend}' {item.type} '{item.id}: {item.title}'. No Date '{date_key}' is set on object. '{body}'",
                    context: [
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                        'date_key' => $dateKey,
                        'body' => arrayToString($item),
                        ...$logContext,
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
                    opts: array_replace_recursive($opts, [
                        iState::COLUMN_META_LIBRARY => ag($logContext, 'library.id'),
                        'override' => [
                            iState::COLUMN_EXTRA => [
                                $context->backendName => [
                                    iState::COLUMN_EXTRA_EVENT => 'task.import',
                                    iState::COLUMN_EXTRA_DATE => makeDate('now'),
                                ],
                            ],
                        ]
                    ]),
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "Exception '{error.kind}' occurred during '{client}: {backend}' '{library.title}' '{item.id}: {item.title}' entity creation. '{error.message}' at '{error.file}:{error.line}'.",
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
                        ],
                        e: $e
                    )
                );

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_date_is_set");
                return;
            }

            if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
                $providerIds = (array)ag($item, 'ProviderIds', []);
                $message = "Ignoring '{client}: {backend}' '{item.title}'. No valid/supported external ids.";

                if (empty($providerIds)) {
                    $message .= " Most likely unmatched '{item.type}'.";
                }

                $this->logger->info($message, [
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'guids' => !empty($providerIds) ? $providerIds : 'None',
                    ...$logContext,
                ]);

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_supported_guid");
                return;
            }

            $opts = [
                'after' => ag($opts, 'after', null),
                Options::IMPORT_METADATA_ONLY => true === (bool)ag($context->options, Options::IMPORT_METADATA_ONLY),
            ];

            $mapper->add(entity: $entity, opts: $opts);
        } catch (Throwable $e) {
            $this->logger->error(
                ...lw(
                    message: "Exception '{error.kind}' was thrown unhandled during '{client}: {backend}' '{library.title}' '{item.title}' {action}. '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'action' => property_exists($this, 'action') ? $this->action : 'import',
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
                            'trace' => $e->getTrace(),
                        ],
                        ...$logContext,
                    ],
                    e: $e
                )
            );
        }
    }
}
