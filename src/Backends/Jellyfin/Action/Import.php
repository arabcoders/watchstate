<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Request;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Backends\Jellyfin\JellyfinClient as JFC;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Extends\RetryableHttpClient;
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
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as iException;
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

    protected RetryableHttpClient $http;

    /**
     * Constructor method for the class.
     *
     * @param iHttp $http The HTTP client instance.
     * @param iLogger $logger The logger instance.
     */
    public function __construct(
        iHttp $http,
        protected iLogger $logger,
    ) {
        $this->http = new RetryableHttpClient(
            $http,
            maxRetries: (int) Config::get('http.default.maxRetries', 3),
            logger: $logger,
        );
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
        ?iDate $after = null,
        array $opts = [],
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
                        opts: $opts + [Options::AFTER => $after],
                    ),
                    logContext: $logContext,
                ),
                error: fn(array $logContext = []) => fn(Throwable $e) => $this->logger->error(
                    message: "Library request failed for '{user}@{backend}'.",
                    context: [
                        'event_name' => 'backend.client.request_failed',
                        'subsystem' => 'backend.import',
                        'operation' => 'request_library',
                        'outcome' => 'failed',
                        'action' => property_exists($this, 'action') ? $this->action : 'import',
                        'backend' => $context->backendName,
                        'client' => $context->clientName,
                        'user' => $context->userContext->name,
                        ...$logContext,
                        ...exception_log($e),
                    ],
                ),
                opts: $opts,
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
        $rContext = [
            'action' => property_exists($this, 'action') ? $this->action : 'import',
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
        ];

        $types = [JFC::COLLECTION_TYPE_MOVIES, JFC::COLLECTION_TYPE_SHOWS, JFC::COLLECTION_TYPE_MIXED];

        try {
            $url = $context->backendUrl->withPath(r('/Users/{user_id}/items/', ['user_id' => $context->backendUser]));
            $rContext['url'] = (string) $url;

            $this->logger->debug("Requesting libraries from '{user}@{backend}' via {client}.", [
                ...$rContext,
                'event_name' => 'backend.request.started',
                'subsystem' => 'backend.import',
                'operation' => 'request_libraries',
                'outcome' => 'started',
            ]);

            $response = $this->http->request(Method::GET, (string) $url, $context->getHttpOptions());

            $payload = $response->getContent(false);

            if ($context->trace) {
                $json = json_decode($payload, true);
                $this->logger->debug("Received libraries response from '{user}@{backend}'.", [
                    ...$rContext,
                    'event_name' => 'backend.response.received',
                    'subsystem' => 'backend.import',
                    'operation' => 'request_libraries',
                    'outcome' => 'received',
                    'response' => [
                        'headers' => $response->getHeaders(false),
                        'status_code' => $response->getStatusCode(),
                        'body' => false === $json ? $payload : $json,
                    ],
                ]);
            }

            if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
                $logContext = [
                    ...$rContext,
                    'event_name' => 'backend.response.failed',
                    'subsystem' => 'backend.import',
                    'operation' => 'request_libraries',
                    'outcome' => 'failed',
                    'reason' => 'unexpected_status',
                    'status_code' => $response->getStatusCode(),
                    'response' => [
                        'headers' => $response->getHeaders(false),
                    ],
                ];

                if ($context->trace) {
                    $logContext['response']['body'] = $response->getInfo('debug');
                }

                $this->logger->error(
                    message: "Libraries request to '{user}@{backend}' returned status {status_code}.",
                    context: $logContext,
                );

                Message::add("{$context->backendName}.has_errors", true);
                return [];
            }

            $json = json_decode(
                json: $payload,
                associative: true,
                flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
            );

            $listDirs = ag($json, 'Items', []);

            if (empty($listDirs)) {
                $this->logger->warning(
                    message: "Libraries response from '{user}@{backend}' was empty.",
                    context: [
                        ...$rContext,
                        'event_name' => 'backend.response.completed',
                        'subsystem' => 'backend.import',
                        'operation' => 'request_libraries',
                        'outcome' => 'completed',
                        'reason' => 'empty_list',
                        'response' => ['body' => $payload],
                    ],
                );
                Message::add("{$context->backendName}.has_errors", true);
                return [];
            }
        } catch (iException $e) {
            $this->logger->error(
                ...lw(
                    message: "Libraries request to '{user}@{backend}' failed.",
                    context: [
                        ...$rContext,
                        'event_name' => 'backend.client.request_failed',
                        'subsystem' => 'backend.import',
                        'operation' => 'request_libraries',
                        'outcome' => 'failed',
                        ...exception_log($e),
                    ],
                    e: $e,
                ),
            );
            Message::add("{$context->backendName}.has_errors", true);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                ...lw(
                    message: "Libraries response from '{user}@{backend}' could not be parsed.",
                    context: [
                        ...$rContext,
                        'event_name' => 'backend.response.failed',
                        'subsystem' => 'backend.import',
                        'operation' => 'request_libraries',
                        'outcome' => 'failed',
                        ...exception_log($e),
                    ],
                    e: $e,
                ),
            );
            Message::add("{$context->backendName}.has_errors", true);
            return [];
        } catch (Throwable $e) {
            $this->logger->error(
                ...lw(
                    message: "Loading libraries from '{user}@{backend}' failed.",
                    context: [
                        ...$rContext,
                        'event_name' => 'backend.operation.failed',
                        'subsystem' => 'backend.import',
                        'operation' => 'load_libraries',
                        'outcome' => 'failed',
                        ...exception_log($e),
                    ],
                    e: $e,
                ),
            );
            Message::add("{$context->backendName}.has_errors", true);
            return [];
        }

        if (null !== ($ignoreIds = ag($context->options, 'ignore', null))) {
            $ignoreIds = array_map(trim(...), explode(',', (string) $ignoreIds));
        }

        $limitLibraryId = ag($opts, Options::ONLY_LIBRARY_ID, null);

        $selectLibraryList = null;
        $inverseLibrarySelect = true === (bool) ag($context->options, Options::LIBRARY_INVERSE, false);
        if (null !== ($selectLibraryIds = ag($context->options, Options::LIBRARY_SELECT, null))) {
            $selectLibraryList = array_map(static fn($value) => (string) $value, $selectLibraryIds);
        }

        $requests = $total = [];
        $ignored = $unsupported = 0;

        // -- Get library items count.
        foreach ($listDirs as $section) {
            $libraryId = (string) ag($section, 'Id');

            $logContext = [
                ...$rContext,
                'library' => [
                    'id' => $libraryId,
                    'title' => ag($section, 'Name', '??'),
                    'type' => ag($section, ['CollectionType', 'Type'], 'unknown'),
                ],
            ];

            if ($limitLibraryId && $libraryId !== (string) $limitLibraryId) {
                continue;
            }

            if (true === in_array($libraryId, $ignoreIds ?? [], true)) {
                $ignored++;
                $this->logger->info(
                    message: "Ignoring library '{library.title}' from '{user}@{backend}': excluded by selection.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.import',
                        'operation' => 'filter_library',
                        'outcome' => 'ignored',
                        'reason' => 'selected_excluded',
                        ...$logContext,
                    ],
                );
                continue;
            }

            if ($selectLibraryList && $inverseLibrarySelect === in_array($libraryId, $selectLibraryList, true)) {
                $ignored++;
                $this->logger->info(
                    message: "Ignoring library '{library.title}' from '{user}@{backend}': excluded by selection.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.import',
                        'operation' => 'filter_library',
                        'outcome' => 'ignored',
                        'reason' => 'selected_excluded',
                        ...$logContext,
                    ],
                );
                continue;
            }

            if (!in_array(ag($logContext, 'library.type'), $types, true)) {
                $unsupported++;
                $this->logger->info(
                    message: "Ignoring library '{library.title}' from '{user}@{backend}': type '{library.type}' is unsupported.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.import',
                        'operation' => 'filter_library',
                        'outcome' => 'ignored',
                        'reason' => 'unsupported_library_type',
                        ...$logContext,
                    ],
                );
                continue;
            }

            $url = $context
                ->backendUrl
                ->withPath(
                    r('/Users/{user_id}/items/', ['user_id' => $context->backendUser]),
                )
                ->withQuery(
                    http_build_query([
                        'sortBy' => 'DateCreated',
                        'sortOrder' => 'Ascending',
                        'parentId' => $libraryId,
                        'recursive' => 'true',
                        'collapseBoxSetItems' => 'false',
                        'excludeLocationTypes' => 'Virtual',
                        'includeItemTypes' => implode(',', [JFC::TYPE_MOVIE, JFC::TYPE_EPISODE]),
                        'startIndex' => 0,
                        'limit' => 0,
                    ]),
                );

            $logContext['library']['url'] = (string) $url;

            $this->logger->debug(
                message: "Requesting item count for library '{library.title}' from '{user}@{backend}'.",
                context: [
                    'event_name' => 'backend.request.started',
                    'subsystem' => 'backend.import',
                    'operation' => 'request_library_count',
                    'outcome' => 'started',
                    ...$logContext,
                ],
            );

            try {
                $requests[] = $this->http->request(
                    method: Method::GET,
                    url: (string) $url,
                    options: array_replace_recursive($context->getHttpOptions(), ['user_data' => $logContext]),
                );
            } catch (iException $e) {
                $this->logger->error(
                    ...lw(
                        message: "Library count request failed for '{user}@{backend}'.",
                        context: [
                            'event_name' => 'backend.client.request_failed',
                            'subsystem' => 'backend.import',
                            'operation' => 'request_library_count',
                            'outcome' => 'failed',
                            ...$logContext,
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );
                continue;
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "Queueing library count requests failed for '{user}@{backend}'.",
                        context: [
                            'event_name' => 'backend.operation.failed',
                            'subsystem' => 'backend.import',
                            'operation' => 'queue_library_requests',
                            'outcome' => 'failed',
                            ...$logContext,
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
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
                        message: "Library count request for '{library.title}' on '{user}@{backend}' returned status {status_code}.",
                        context: [
                            'event_name' => 'backend.response.failed',
                            'subsystem' => 'backend.import',
                            'operation' => 'request_library_count',
                            'outcome' => 'failed',
                            'reason' => 'unexpected_status',
                            ...$logContext,
                            'status_code' => $response->getStatusCode(),
                        ],
                    );
                    continue;
                }

                $json = json_decode($response->getContent(), true);

                $totalCount = (int) ag($json, 'TotalRecordCount', 0);

                if ($totalCount < 1) {
                    $this->logger->warning(
                        message: "Ignoring library '{library.title}' from '{user}@{backend}': item count is empty.",
                        context: [
                            'event_name' => 'backend.item.ignored',
                            'subsystem' => 'backend.import',
                            'operation' => 'filter_library',
                            'outcome' => 'ignored',
                            'reason' => 'empty_library',
                            ...$logContext,
                            'response' => [
                                'headers' => $response->getHeaders(),
                                'body' => false === $json ? $response->getContent(false) : $json,
                            ],
                        ],
                    );
                    continue;
                }

                $total[ag($logContext, 'library.id')] = $totalCount;
            } catch (iException $e) {
                $this->logger->error(
                    ...lw(
                        message: "Reading library count response failed for '{library.title}' on '{user}@{backend}'.",
                        context: [
                            'event_name' => 'backend.client.request_failed',
                            'subsystem' => 'backend.import',
                            'operation' => 'request_library_count',
                            'outcome' => 'failed',
                            ...$logContext,
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );
                continue;
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "Parsing library count response failed for '{library.title}' on '{user}@{backend}'.",
                        context: [
                            'event_name' => 'backend.operation.failed',
                            'subsystem' => 'backend.import',
                            'operation' => 'parse_library_count',
                            'outcome' => 'failed',
                            ...$logContext,
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );
                continue;
            }
        }

        $requests = [];

        // -- Episodes Parent external ids.
        foreach ($listDirs as $section) {
            $libraryId = (string) ag($section, 'Id');
            $logContext = [
                ...$rContext,
                'library' => [
                    'id' => $libraryId,
                    'title' => ag($section, 'Name', '??'),
                    'type' => ag($section, ['CollectionType', 'Type'], 'unknown'),
                ],
                'segment' => [
                    'number' => 1,
                    'of' => 1,
                ],
            ];

            if (true === in_array($libraryId, $ignoreIds ?? [], true)) {
                continue;
            }

            if ($selectLibraryList && $inverseLibrarySelect === in_array($libraryId, $selectLibraryList, true)) {
                continue;
            }

            if (!in_array(
                ag($logContext, 'library.type'),
                [JFC::COLLECTION_TYPE_SHOWS, JFC::COLLECTION_TYPE_MIXED],
                true,
            )) {
                continue;
            }

            if (true === array_key_exists($libraryId, $total)) {
                $logContext['library']['totalRecords'] = $total[$libraryId];
            }

            $url = $context
                ->backendUrl
                ->withPath(
                    r('/Users/{user_id}/items/', [
                        'user_id' => $context->backendUser,
                    ]),
                )
                ->withQuery(
                    http_build_query([
                        'parentId' => $libraryId,
                        'recursive' => 'false',
                        'enableUserData' => 'false',
                        'enableImages' => 'false',
                        'includeItemTypes' => JFC::TYPE_SHOW,
                        'fields' => implode(',', JFC::EXTRA_FIELDS),
                        'excludeLocationTypes' => 'Virtual',
                    ]),
                );

            $logContext['library']['url'] = (string) $url;

            $this->logger->debug(
                message: "Requesting series metadata for library '{library.title}' from '{user}@{backend}'.",
                context: [
                    'event_name' => 'backend.request.started',
                    'subsystem' => 'backend.import',
                    'operation' => 'request_series_metadata',
                    'outcome' => 'started',
                    ...$logContext,
                ],
            );

            try {
                $requests[] = new Request(
                    method: Method::GET,
                    url: $url,
                    options: $context->getHttpOptions(),
                    success: $handle($logContext),
                    error: $error($logContext),
                    extras: ['logContext' => $logContext, iHttp::class => $this->http],
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "Queueing series metadata request for library '{library.title}' from '{user}@{backend}' failed.",
                        context: [
                            'event_name' => 'backend.operation.failed',
                            'subsystem' => 'backend.import',
                            'operation' => 'queue_series_requests',
                            'outcome' => 'failed',
                            ...$logContext,
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );
                continue;
            }
        }

        // -- get paginated movies/episodes.
        foreach ($listDirs as $section) {
            $libraryId = (string) ag($section, 'Id');
            $logContext = [
                ...$rContext,
                'library' => [
                    'id' => $libraryId,
                    'title' => ag($section, 'Name', '??'),
                    'type' => ag($section, ['CollectionType', 'Type'], 'unknown'),
                ],
            ];

            if (true === in_array($libraryId, $ignoreIds ?? [], true)) {
                $ignored++;
                $this->logger->info(
                    message: "Ignoring library '{library.title}' from '{user}@{backend}': excluded by selection.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.import',
                        'operation' => 'filter_library',
                        'outcome' => 'ignored',
                        'reason' => 'selected_excluded',
                        ...$logContext,
                    ],
                );
                continue;
            }

            if ($selectLibraryList && $inverseLibrarySelect === in_array($libraryId, $selectLibraryList, true)) {
                $this->logger->info(
                    message: "Ignoring library '{library.title}' from '{user}@{backend}': excluded by selection.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.import',
                        'operation' => 'filter_library',
                        'outcome' => 'ignored',
                        'reason' => 'selected_excluded',
                        ...$logContext,
                    ],
                );
                continue;
            }

            if (!in_array(ag($logContext, 'library.type'), $types, true)) {
                $unsupported++;
                $this->logger->info(
                    message: "Ignoring library '{library.title}' from '{user}@{backend}': type '{library.type}' is unsupported.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.import',
                        'operation' => 'filter_library',
                        'outcome' => 'ignored',
                        'reason' => 'unsupported_library_type',
                        ...$logContext,
                    ],
                );
                continue;
            }

            if (false === array_key_exists($libraryId, $total)) {
                $ignored++;
                $this->logger->warning(
                    message: "Ignoring library '{library.title}' from '{user}@{backend}': item count is unavailable.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.import',
                        'operation' => 'filter_library',
                        'outcome' => 'ignored',
                        'reason' => 'missing_item_count',
                        ...$logContext,
                    ],
                );
                continue;
            }

            $logContext['library']['totalRecords'] = $total[$libraryId];

            $segmentTotal = (int) $total[$libraryId];
            $segmentSize = (int) ag($context->options, Options::LIBRARY_SEGMENT, 1000);
            $segmented = ceil($segmentTotal / $segmentSize);

            for ($i = 0; $i < $segmented; $i++) {
                try {
                    $logContext['segment'] = [
                        'number' => $i + 1,
                        'of' => $segmented,
                        'size' => $segmentSize,
                    ];

                    $url = $context
                        ->backendUrl
                        ->withPath(
                            r('/Users/{user_id}/items/', [
                                'user_id' => $context->backendUser,
                            ]),
                        )
                        ->withQuery(
                            http_build_query([
                                'sortBy' => 'DateCreated',
                                'sortOrder' => 'Ascending',
                                'collapseBoxSetItems' => 'false',
                                'parentId' => ag($logContext, 'library.id'),
                                'recursive' => 'true',
                                'enableUserData' => 'true',
                                'enableImages' => 'false',
                                'excludeLocationTypes' => 'Virtual',
                                'fields' => implode(',', JFC::EXTRA_FIELDS),
                                'includeItemTypes' => implode(',', [JFC::TYPE_MOVIE, JFC::TYPE_EPISODE]),
                                'limit' => $segmentSize,
                                'startIndex' => $i < 1 ? 0 : $segmentSize * $i,
                            ]),
                        );

                    $logContext['library']['url'] = (string) $url;

                    $this->logger->debug(
                        message: "Requesting library items for '{library.title}' segment {segment.number}/{segment.of} from '{user}@{backend}'.",
                        context: [
                            'event_name' => 'backend.request.started',
                            'subsystem' => 'backend.import',
                            'operation' => 'request_library',
                            'outcome' => 'started',
                            ...$logContext,
                        ],
                    );

                    $requests[] = new Request(
                        method: Method::GET,
                        url: $url,
                        options: $context->getHttpOptions(),
                        success: $handle($logContext),
                        error: $error($logContext),
                        extras: ['logContext' => $logContext, iHttp::class => $this->http],
                    );
                } catch (Throwable $e) {
                    $this->logger->error(
                        ...lw(
                            message: "Queueing library items request for '{library.title}' segment {segment.number}/{segment.of} from '{user}@{backend}' failed.",
                            context: [
                                'event_name' => 'backend.operation.failed',
                                'subsystem' => 'backend.import',
                                'operation' => 'queue_library_requests',
                                'outcome' => 'failed',
                                ...$logContext,
                                ...exception_log($e),
                            ],
                            e: $e,
                        ),
                    );
                    continue;
                }
            }
        }

        if (0 === count($requests)) {
            $this->logger->warning("No eligible library requests were queued for '{user}@{backend}'.", [
                ...$rContext,
                'event_name' => 'backend.request.skipped',
                'subsystem' => 'backend.import',
                'operation' => 'queue_library_requests',
                'outcome' => 'skipped',
                'reason' => 'no_eligible_requests',
                'stats' => [
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
        if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
            $this->logger->error(
                message: "Library request for '{library.title}' segment {segment.number}/{segment.of} on '{user}@{backend}' returned status {status_code}.",
                context: [
                    'event_name' => 'backend.response.failed',
                    'subsystem' => 'backend.import',
                    'operation' => 'request_library',
                    'outcome' => 'failed',
                    'reason' => 'unexpected_status',
                    ...$logContext,
                    'status_code' => $response->getStatusCode(),
                ],
            );
            return;
        }

        $start = microtime(true);
        $this->logger->info(
            message: "Parsing library '{library.title}' segment {segment.number}/{segment.of} from '{user}@{backend}'.",
            context: [
                'event_name' => 'backend.response.processing',
                'subsystem' => 'backend.import',
                'operation' => 'parse_library_response',
                'outcome' => 'started',
                ...$logContext,
                'time' => [
                    'start' => $start,
                ],
            ],
        );

        $isParsed = false;

        try {
            $it = Items::fromIterable(
                iterable: http_client_chunks($this->http->stream($response)),
                options: [
                    'pointer' => '/Items',
                    'decoder' => new ErrorWrappingDecoder(
                        innerDecoder: new ExtJsonDecoder(assoc: true, options: JSON_INVALID_UTF8_IGNORE),
                    ),
                ],
            );

            foreach ($it as $entity) {
                try {
                    if ($entity instanceof DecodingError) {
                        $this->logger->warning(
                            message: "Ignoring malformed item from library '{library.title}' segment {segment.number}/{segment.of} on '{user}@{backend}': {error.message}.",
                            context: [
                                'event_name' => 'backend.item.ignored',
                                'subsystem' => 'backend.import',
                                'operation' => 'parse_item',
                                'outcome' => 'ignored',
                                'reason' => 'decode_error',
                                ...$logContext,
                                'error' => [
                                    'message' => $entity->getErrorMessage(),
                                    'body' => $entity->getMalformedJson(),
                                ],
                            ],
                        );
                        continue;
                    }

                    // -- Handle multi episode entries.
                    $indexNumber = ag($entity, 'IndexNumber');
                    $indexNumberEnd = ag($entity, 'IndexNumberEnd');
                    if (null !== $indexNumber && null !== $indexNumberEnd && $indexNumberEnd > $indexNumber) {
                        $episodeRangeLimit = (int) ag($context->options, Options::MAX_EPISODE_RANGE, 5);
                        $range = range(ag($entity, 'IndexNumber'), $indexNumberEnd);
                        if (count($range) > $episodeRangeLimit) {
                            $this->logger->warning(
                                message: "Treating episode range for {item.type} '{item.title}' in '{library.title}' from '{user}@{backend}' as a single episode: {item.indexNumber}-{item.indexNumberEnd} exceeds limit {range_limit}.",
                                context: [
                                    'event_name' => 'backend.response.processing',
                                    'subsystem' => 'backend.import',
                                    'operation' => 'expand_episode_range',
                                    'outcome' => 'skipped',
                                    'reason' => 'range_limit_exceeded',
                                    'range_limit' => $episodeRangeLimit,
                                    'item' => [
                                        'remote_id' => null === ag($entity, 'Id') ? null : (string) ag($entity, 'Id'),
                                        'title' => ag($entity, ['Name', 'OriginalTitle'], '??'),
                                        'type' => ag($entity, 'Type'),
                                        'rangeCount' => count($range),
                                        'indexNumber' => ag($entity, 'IndexNumber'),
                                        'indexNumberEnd' => $indexNumberEnd,
                                    ],
                                    ...$logContext,
                                ],
                            );
                            $callback(item: $entity, logContext: $logContext);
                        } else {
                            foreach ($range as $i) {
                                $this->logger->debug(
                                    message: "Creating virtual episode {item.i} for {item.type} '{item.title}' in '{library.title}' from '{user}@{backend}'.",
                                    context: [
                                        'event_name' => 'backend.response.processing',
                                        'subsystem' => 'backend.import',
                                        'operation' => 'expand_episode_range',
                                        'outcome' => 'completed',
                                        ...$logContext,
                                        'item' => [
                                            'remote_id' => null === ag($entity, 'Id') ? null : (string) ag($entity, 'Id'),
                                            'title' => ag($entity, ['Name', 'OriginalTitle'], '??'),
                                            'type' => ag($entity, 'Type'),
                                            'i' => $i,
                                            'indexNumber' => $indexNumber,
                                            'indexNumberEnd' => $indexNumberEnd,
                                        ],
                                    ],
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
                            message: "Processing library item from '{library.title}' segment {segment.number}/{segment.of} on '{user}@{backend}' failed.",
                            context: [
                                'event_name' => 'backend.operation.failed',
                                'subsystem' => 'backend.import',
                                'operation' => 'process_item',
                                'outcome' => 'failed',
                                ...$logContext,
                                'entity' => $entity,
                                ...exception_log($e),
                            ],
                            e: $e,
                        ),
                    );
                }
            }

            $isParsed = true;
        } catch (Throwable $e) {
            $this->logger->error(
                ...lw(
                    message: "Parsing library '{library.title}' segment {segment.number}/{segment.of} on '{user}@{backend}' failed.",
                    context: [
                        'event_name' => 'backend.operation.failed',
                        'subsystem' => 'backend.import',
                        'operation' => 'parse_library_response',
                        'outcome' => 'failed',
                        ...$logContext,
                        ...exception_log($e),
                    ],
                    e: $e,
                ),
            );
        }

        $end = microtime(true);

        if (true === $isParsed) {
            $duration = round($end - $start, 2);

            $this->logger->info(
                message: "Parsed library '{library.title}' segment {segment.number}/{segment.of} from '{user}@{backend}' in {duration_seconds}s.",
                context: [
                    'event_name' => 'backend.response.processing',
                    'subsystem' => 'backend.import',
                    'operation' => 'parse_library_response',
                    'outcome' => 'completed',
                    ...$logContext,
                    'duration_seconds' => $duration,
                    'time' => [
                        'start' => $start,
                        'end' => $end,
                        'duration' => $duration,
                    ],
                ],
            );
        }

        Message::increment('response.size', (int) $response->getInfo('size_download'));
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
            'remote_id' => null === ag($item, 'Id') ? null : (string) ag($item, 'Id'),
            'title' => r('{title} ({year})', [
                'title' => ag($item, ['Name', 'OriginalTitle'], '??'),
                'year' => ag($item, 'ProductionYear', '0000'),
            ]),
            'year' => ag($item, 'ProductionYear', null),
            'type' => ag($item, 'Type'),
        ];

        if ($context->trace) {
            $this->logger->debug(
                message: "Processing {item.type} '{item.title}' from '{user}@{backend}'.",
                context: [
                    'event_name' => 'backend.response.received',
                    'subsystem' => 'backend.import',
                    'operation' => 'process_item',
                    'outcome' => 'received',
                    ...$logContext,
                    'response' => ['body' => $item],
                ],
            );
        }

        $providersId = (array) ag($item, 'ProviderIds', []);
        $showMetadata = $this->cacheShowMetadata(context: $context, guid: $guid, item: $item, logContext: $logContext);

        if ([] === ag($showMetadata, 'guids', [])) {
            $message = "Ignoring {item.type} '{item.title}' from '{user}@{backend}': no supported external IDs.";

            if (empty($providersId)) {
                $message .= ' Most likely unmatched {item.type}.';
            }

            $this->logger->info($message, [
                'guids' => !empty($providersId) ? $providersId : 'None',
                'event_name' => 'backend.item.ignored',
                'subsystem' => 'backend.import',
                'operation' => 'process_item',
                'outcome' => 'ignored',
                'reason' => 'missing_supported_guid',
                ...$logContext,
            ]);

            return;
        }
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
        array $opts = [],
    ): void {
        if (JFC::TYPE_SHOW === ($type = ag($item, 'Type'))) {
            $this->processShow(context: $context, guid: $guid, item: $item, logContext: $logContext);
            return;
        }

        $mappedType = JFC::TYPE_MAPPER[$type] ?? $type;

        try {
            if ($context->trace) {
                $this->logger->debug(
                    message: "Processing {item.type} '{item.title}' from '{user}@{backend}'.",
                    context: [
                        'event_name' => 'backend.response.received',
                        'subsystem' => 'backend.import',
                        'operation' => 'process_item',
                        'outcome' => 'received',
                        ...$logContext,
                        'response' => ['body' => $item],
                    ],
                );
            }

            Message::increment("{$context->backendName}.{$mappedType}.total");

            try {
                $logContext['item'] = [
                    'remote_id' => null === ag($item, 'Id') ? null : (string) ag($item, 'Id'),
                    'title' => match ($type) {
                        JFC::TYPE_MOVIE => r('{title} ({year})', [
                            'title' => ag($item, ['Name', 'OriginalTitle'], '??'),
                            'year' => ag($item, 'ProductionYear', 0o000),
                        ]),
                        JFC::TYPE_EPISODE => r('{title} - ({season}x{episode})', [
                            'title' => ag($item, 'SeriesName', '??'),
                            'season' => str_pad((string) ag($item, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                            'episode' => str_pad((string) ag($item, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                        ]),
                        default => throw new InvalidArgumentException(
                            r(
                                "Unexpected content type '{type}' was received from '{user}@{backend}'.",
                                [...$logContext, 'type' => $type],
                            ),
                        ),
                    },
                    'type' => ag($item, 'Type'),
                ];
            } catch (InvalidArgumentException $e) {
                $this->logger->error(
                    ...lw(
                        message: "Failed to parse item response from '{user}@{backend}'.",
                        context: [
                            'event_name' => 'backend.operation.failed',
                            'subsystem' => 'backend.import',
                            'operation' => 'parse_item',
                            'outcome' => 'failed',
                            'response' => ['body' => $item],
                            ...$logContext,
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );
                return;
            }

            $isPlayed = true === (bool) ag($item, 'UserData.Played');
            $dateKey = true === $isPlayed ? 'UserData.LastPlayedDate' : 'DateCreated';

            /**
             * this code is workaround for bug in emby sometimes marking items as played
             * without adding UserData.LastPlayedDate data field to userData. thus, it will
             * trigger the No Date is set check.
             */
            if (true === $isPlayed && false === ag_exists($item, 'UserData.LastPlayedDate')) {
                if ($context->trace) {
                    $this->logger->debug(
                        message: "Using 'DateCreated' for {item.type} '{item.title}' from '{user}@{backend}': 'LastPlayedDate' is missing.",
                        context: [
                            'event_name' => 'backend.response.processing',
                            'subsystem' => 'backend.import',
                            'operation' => 'normalize_played_date',
                            'outcome' => 'skipped',
                            'reason' => 'missing_last_played_date',
                            'response' => ['body' => $item],
                            ...$logContext,
                        ],
                    );
                }
                $dateKey = 'DateCreated';
            }

            if (null === ag($item, $dateKey)) {
                $this->logger->debug(
                    message: "Ignoring {item.type} '#{item.remote_id}: {item.title}' from '{user}@{backend}': missing date '{date_key}'.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.import',
                        'operation' => 'process_item',
                        'outcome' => 'ignored',
                        'reason' => 'missing_date',
                        'date_key' => $dateKey,
                        'response' => ['body' => $item],
                        ...$logContext,
                    ],
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
                                    iState::COLUMN_EXTRA_DATE => make_date('now'),
                                ],
                            ],
                        ],
                    ]),
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "Creating local entity for {item.type} '{item.title}' from '{user}@{backend}' failed.",
                        context: [
                            'event_name' => 'backend.operation.failed',
                            'subsystem' => 'backend.import',
                            'operation' => 'create_entity',
                            'outcome' => 'failed',
                            ...$logContext,
                            'response' => ['body' => $item],
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_date_is_set");
                return;
            }

            if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
                $providerIds = (array) ag($item, 'ProviderIds', []);
                $message = "Ignoring {item.type} '{item.title}' from '{user}@{backend}': no supported external IDs.";

                if (empty($providerIds)) {
                    $message .= " Most likely unmatched '{item.type}'.";
                }

                $this->logger->info($message, [
                    'guids' => !empty($providerIds) ? $providerIds : 'None',
                    'event_name' => 'backend.item.ignored',
                    'subsystem' => 'backend.import',
                    'operation' => 'create_entity',
                    'outcome' => 'ignored',
                    'reason' => 'missing_supported_guid',
                    ...$logContext,
                ]);

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_supported_guid");
                return;
            }

            $opts = [
                Options::AFTER => ag($opts, Options::AFTER, null),
                Options::IMPORT_METADATA_ONLY => true === (bool) ag($context->options, Options::IMPORT_METADATA_ONLY),
                Options::DISABLE_MARK_UNPLAYED => true === (bool) ag($context->options, Options::DISABLE_MARK_UNPLAYED),
                Options::FORCE_FULL => true === (bool) ag($context->options, Options::FORCE_FULL),
                Options::FORCE_REPLACE_METADATA => true === (bool) ag($context->options, Options::FORCE_REPLACE_METADATA),
            ];

            $mapper->add(entity: $entity, opts: $opts);
        } catch (Throwable $e) {
            $this->logger->error(
                ...lw(
                    message: "Processing item response from '{user}@{backend}' failed.",
                    context: [
                        'event_name' => 'backend.operation.failed',
                        'subsystem' => 'backend.import',
                        'operation' => 'process_item',
                        'outcome' => 'failed',
                        ...$logContext,
                        'response' => ['body' => $item],
                        ...exception_log($e),
                    ],
                    e: $e,
                ),
            );
        }
    }
}
