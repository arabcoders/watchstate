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
                        'action' => $this->action,
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

    protected function getLibraries(Context $context, Closure $handle, Closure $error, array $opts = []): array
    {
        $segmentSize = (int) ag($context->options, Options::LIBRARY_SEGMENT, 1000);

        $rContext = [
            'action' => property_exists($this, 'action') ? $this->action : 'import',
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
        ];

        try {
            $url = $context->backendUrl->withPath('/library/sections');
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
                $this->logger->debug("Received libraries response from '{user}@{backend}'.", [
                    ...$rContext,
                    'event_name' => 'backend.response.received',
                    'subsystem' => 'backend.import',
                    'operation' => 'request_libraries',
                    'outcome' => 'received',
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
                    message: "Libraries request to '{user}@{backend}' returned status {status_code}.",
                    context: [
                        'event_name' => 'backend.response.failed',
                        'subsystem' => 'backend.import',
                        'operation' => 'request_libraries',
                        'outcome' => 'failed',
                        'reason' => 'unexpected_status',
                        ...$logContext,
                    ],
                );

                Message::add("{$context->backendName}.has_errors", true);
                return [];
            }

            $json = json_decode(
                json: $payload,
                associative: true,
                flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
            );

            unset($payload);

            $listDirs = ag($json, 'MediaContainer.Directory', []);

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
                        'response' => [
                            'key' => 'MediaContainer.Directory',
                            'body' => $json,
                        ],
                    ],
                );
                Message::add("{$context->backendName}.has_errors", true);
                return [];
            }
        } catch (ExceptionInterface $e) {
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
            $ignoreIds = array_map(static fn($v) => (int) trim($v), explode(',', (string) $ignoreIds));
        }

        $limitLibraryId = ag($opts, Options::ONLY_LIBRARY_ID, null);

        $selectLibraryList = null;
        $inverseLibrarySelect = true === (bool) ag($context->options, Options::LIBRARY_INVERSE, false);
        if (null !== ($selectLibraryIds = ag($context->options, Options::LIBRARY_SELECT, null))) {
            $selectLibraryList = array_map(static fn($value) => (int) $value, $selectLibraryIds);
        }

        $requests = $total = [];
        $ignored = $unsupported = 0;

        // -- Get library items count.
        foreach ($listDirs as $section) {
            $libraryId = (int) ag($section, 'key');

            if ($limitLibraryId && $libraryId !== (int) $limitLibraryId) {
                continue;
            }

            $logContext = [
                ...$rContext,
                'library' => [
                    'id' => $libraryId,
                    'title' => ag($section, 'title', '??'),
                    'type' => ag($section, 'type', 'unknown'),
                    'agent' => ag($section, 'agent', 'unknown'),
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

            if (
                false === in_array(
                    ag($logContext, 'library.type'),
                    [PlexClient::TYPE_MOVIE, PlexClient::TYPE_SHOW],
                    true,
                )
            ) {
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

            if (false === in_array(ag($logContext, 'library.agent'), PlexClient::SUPPORTED_AGENTS, true)) {
                $this->logger->notice(
                    message: "Ignoring library '{library.title}' from '{user}@{backend}': unsupported agent '{agent}'.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.import',
                        'operation' => 'filter_library',
                        'outcome' => 'ignored',
                        'reason' => 'unsupported_agent',
                        ...$logContext,
                        'agent' => ag($logContext, 'library.agent', '??'),
                    ],
                );
                continue;
            }

            $isMovieLibrary = PlexClient::TYPE_MOVIE === ag($logContext, 'library.type');

            $url = $context
                ->backendUrl
                ->withPath(r('/library/sections/{library_id}/all', ['library_id' => $libraryId]))
                ->withQuery(
                    http_build_query([
                        'includeGuids' => 1,
                        'type' => $isMovieLibrary ? 1 : 4,
                        'sort' => $isMovieLibrary ? 'addedAt' : 'episode.addedAt',
                        'X-Plex-Container-Start' => 0,
                        'X-Plex-Container-Size' => $segmentSize,
                    ]),
                );

            $logContext['library']['url'] = $url;

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
                    options: array_replace_recursive($context->getHttpOptions(), [
                        'headers' => [
                            'X-Plex-Container-Start' => 0,
                            'X-Plex-Container-Size' => 0,
                        ],
                        'user_data' => $logContext,
                    ]),
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
                        ]),
                    );

                    $this->logger->debug(
                        message: "Requesting series count for library '{library.title}' from '{user}@{backend}'.",
                        context: [
                            'event_name' => 'backend.request.started',
                            'subsystem' => 'backend.import',
                            'operation' => 'request_library_count',
                            'outcome' => 'started',
                            ...$logContextSub,
                        ],
                    );

                    $requests[] = $this->http->request(
                        method: Method::GET,
                        url: (string) $logContextSub['library']['url'],
                        options: array_replace_recursive($context->getHttpOptions(), [
                            'headers' => [
                                'X-Plex-Container-Start' => 0,
                                'X-Plex-Container-Size' => 0,
                            ],
                            'user_data' => [
                                'isShowRequest' => true,
                                ...$logContextSub,
                            ],
                        ]),
                    );
                }
            } catch (ExceptionInterface $e) {
                $this->logger->error(
                    ...lw(
                        message: "Library count request failed for '{user}@{backend}'.",
                        context: [
                            'event_name' => 'backend.client.request_failed',
                            'subsystem' => 'backend.import',
                            'operation' => 'request_library_count',
                            'outcome' => 'failed',
                            ...$rContext,
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
                            ...$rContext,
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

                $totalCount = (int) (ag($response->getHeaders(), 'x-plex-container-total-size')[0] ?? 0);

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
                            ],
                        ],
                    );
                    continue;
                }

                if (ag_exists($logContext, 'isShowRequest')) {
                    $total['show_' . ag($logContext, 'library.id')] = $totalCount;
                } else {
                    $total[(int) ag($logContext, 'library.id')] = $totalCount;
                }
            } catch (ExceptionInterface $e) {
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

        // -- get paginated tv shows metadata.
        foreach ($listDirs as $section) {
            $libraryId = (int) ag($section, 'key');

            if (PlexClient::TYPE_SHOW !== ag($section, 'type', 'unknown')) {
                continue;
            }

            if (!in_array(ag($section, 'agent'), PlexClient::SUPPORTED_AGENTS, true)) {
                continue;
            }

            if ($selectLibraryList && $inverseLibrarySelect === in_array($libraryId, $selectLibraryList, true)) {
                continue;
            }

            if (true === in_array($libraryId, $ignoreIds ?? [], true)) {
                continue;
            }

            $logContext = [
                ...$rContext,
                'library' => [
                    'id' => $libraryId,
                    'title' => ag($section, 'title', '??'),
                    'type' => ag($section, 'type', 'unknown'),
                    'url' => $url,
                ],
            ];

            if (false === array_key_exists('show_' . $libraryId, $total)) {
                $ignored++;
                $this->logger->warning(
                    message: "Ignoring library '{library.title}' from '{user}@{backend}': series count is unavailable.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.import',
                        'operation' => 'filter_library',
                        'outcome' => 'ignored',
                        'reason' => 'missing_series_count',
                        ...$logContext,
                    ],
                );
                continue;
            }

            $logContext['library']['totalRecords'] = $total['show_' . $libraryId];

            $segmentTotal = (int) $total['show_' . $libraryId];
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
                            r('/library/sections/{library_id}/all', ['library_id' => $libraryId]),
                        )
                        ->withQuery(
                            http_build_query([
                                'type' => 2,
                                'includeGuids' => 1,
                                'X-Plex-Container-Size' => $segmentSize,
                                'X-Plex-Container-Start' => $i < 1 ? 0 : $segmentSize * $i,
                            ]),
                        );

                    $logContext['library']['url'] = $url;

                    $this->logger->debug(
                        message: "Requesting series metadata for library '{library.title}' segment {segment.number}/{segment.of} from '{user}@{backend}'.",
                        context: [
                            'event_name' => 'backend.request.started',
                            'subsystem' => 'backend.import',
                            'operation' => 'request_series_metadata',
                            'outcome' => 'started',
                            ...$logContext,
                        ],
                    );

                    $requests[] = new Request(
                        method: Method::GET,
                        url: $url,
                        options: array_replace_recursive($context->getHttpOptions(), [
                            'headers' => [
                                'X-Plex-Container-Size' => $segmentSize,
                                'X-Plex-Container-Start' => $i < 1 ? 0 : $segmentSize * $i,
                            ],
                        ]),
                        success: $handle($logContext),
                        error: $error($logContext),
                        extras: ['logContext' => $logContext, iHttp::class => $this->http],
                    );
                } catch (Throwable $e) {
                    $this->logger->error(
                        ...lw(
                            message: "Queueing series metadata request for library '{library.title}' segment {segment.number}/{segment.of} from '{user}@{backend}' failed.",
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
        }

        // -- get paginated movies/episodes.
        foreach ($listDirs as $section) {
            $libraryId = (int) ag($section, 'key');

            $logContext = [
                ...$rContext,
                'library' => [
                    'id' => $libraryId,
                    'title' => ag($section, 'title', '??'),
                    'type' => ag($section, 'type', 'unknown'),
                ],
            ];

            if (false === in_array(ag($section, 'agent'), PlexClient::SUPPORTED_AGENTS, true)) {
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

            if (!in_array(ag($logContext, 'library.type'), [PlexClient::TYPE_MOVIE, PlexClient::TYPE_SHOW], true)) {
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
            $segmented = ceil($segmentTotal / $segmentSize);

            for ($i = 0; $i < $segmented; $i++) {
                try {
                    $logContext['segment'] = [
                        'number' => $i + 1,
                        'of' => $segmented,
                        'size' => $segmentSize,
                    ];

                    $isMovieLibrary = PlexClient::TYPE_MOVIE === ag($logContext, 'library.type');

                    $url = $context
                        ->backendUrl
                        ->withPath(r('/library/sections/{library_id}/all', ['library_id' => $libraryId]))
                        ->withQuery(
                            http_build_query([
                                'includeGuids' => 1,
                                'type' => $isMovieLibrary ? 1 : 4,
                                'sort' => $isMovieLibrary ? 'addedAt' : 'episode.addedAt',
                                'X-Plex-Container-Size' => $segmentSize,
                                'X-Plex-Container-Start' => $i < 1 ? 0 : $segmentSize * $i,
                            ]),
                        );

                    $logContext['library']['url'] = $url;

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
                        options: array_replace_recursive($context->getHttpOptions(), [
                            'headers' => [
                                'X-Plex-Container-Size' => $segmentSize,
                                'X-Plex-Container-Start' => $i < 1 ? 0 : $segmentSize * $i,
                            ],
                        ]),
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
     * @throws TransportExceptionInterface
     * @noinspection PhpUnusedParameterInspection
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
                    'pointer' => '/MediaContainer/Metadata',
                    'decoder' => new ErrorWrappingDecoder(
                        innerDecoder: new ExtJsonDecoder(
                            assoc: true,
                            options: JSON_INVALID_UTF8_IGNORE,
                        ),
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
                    $callback(item: $entity, logContext: $logContext);
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

    protected function processShow(Context $context, iGuid $guid, array $item, array $logContext = []): void
    {
        $guids = [];

        if (null !== ($item['Guid'] ?? null)) {
            $guids = $item['Guid'];
        }

        if (null !== ($itemGuid = ag($item, 'guid')) && false === $guid->isLocal($itemGuid)) {
            $guids[] = ['id' => $itemGuid];
        }

        $year = (int) ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
        if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
            $year = (int) make_date($airDate)->format('Y');
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
                message: "Processing {item.type} '{item.title}' from '{user}@{backend}'.",
                context: [
                    'event_name' => 'backend.response.received',
                    'subsystem' => 'backend.import',
                    'operation' => 'process_item',
                    'outcome' => 'received',
                    ...$logContext,
                    'response' => [
                        'body' => $item,
                    ],
                ],
            );
        }

        $showMetadata = $this->cacheShowMetadata(context: $context, guid: $guid, item: $item, logContext: $logContext);

        if ([] === ag($showMetadata, 'guids', [])) {
            $message = "Ignoring {item.type} '{item.title}' from '{user}@{backend}': no supported external IDs.";

            if (empty($guids)) {
                $message .= ' Most likely unmatched {item.type}.';
            }

            $this->logger->info($message, [
                ...$logContext,
                'event_name' => 'backend.item.ignored',
                'subsystem' => 'backend.import',
                'operation' => 'process_item',
                'outcome' => 'ignored',
                'reason' => 'missing_supported_guid',
                'guids' => !empty($item['Guid']) ? $item['Guid'] : 'None',
            ]);

            return;
        }
    }

    protected function process(
        Context $context,
        iGuid $guid,
        iImport $mapper,
        array $item,
        array $logContext = [],
        array $opts = [],
    ): void {
        if (PlexClient::TYPE_SHOW === ($type = ag($item, 'type'))) {
            $this->processShow(context: $context, guid: $guid, item: $item, logContext: $logContext);
            return;
        }

        $mappedType = PlexClient::TYPE_MAPPER[$type] ?? $type;

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
                        'response' => [
                            'body' => $item,
                        ],
                    ],
                );
            }

            Message::increment("{$context->backendName}.{$mappedType}.total");

            $year = (int) ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
            if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
                $year = (int) make_date($airDate)->format('Y');
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
                            'season' => str_pad((string) ag($item, 'parentIndex', 0), 2, '0', STR_PAD_LEFT),
                            'episode' => str_pad((string) ag($item, 'index', 0), 3, '0', STR_PAD_LEFT),
                        ]),
                        default => throw new InvalidArgumentException(
                            r(
                                text: "Unexpected content type '{type}' was received from '{user}@{backend}'.",
                                context: [...$logContext, 'type' => $type],
                            ),
                        ),
                    },
                    'type' => ag($item, 'type', 'unknown'),
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
                            ...$logContext,
                            'response' => [
                                'body' => $item,
                            ],
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );
                return;
            }

            if (null === ag($item, true === (bool) ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt')) {
                $this->logger->debug(
                    message: "Ignoring {item.type} '#{item.id}: {item.title}' from '{backend}': missing date '{date_key}'.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.import',
                        'operation' => 'process_item',
                        'outcome' => 'ignored',
                        'reason' => 'missing_date',
                        ...$logContext,
                        'date_key' => true === (bool) ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt',
                        'response' => [
                            'body' => $item,
                        ],
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
                    opts: $opts
                    + [
                        iState::COLUMN_META_LIBRARY => ag($logContext, 'library.id'),
                        'override' => [
                            iState::COLUMN_EXTRA => [
                                $context->backendName => [
                                    iState::COLUMN_EXTRA_EVENT => 'task.import',
                                    iState::COLUMN_EXTRA_DATE => make_date('now'),
                                ],
                            ],
                        ],
                    ],
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
                            'response' => [
                                'body' => $item,
                            ],
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );
                return;
            }

            if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
                $message = "Ignoring {item.type} '{item.title}' from '{user}@{backend}': no supported external IDs.";

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
                    'event_name' => 'backend.item.ignored',
                    'subsystem' => 'backend.import',
                    'operation' => 'create_entity',
                    'outcome' => 'ignored',
                    'reason' => 'missing_supported_guid',
                    'guids' => !empty($item['Guid']) ? $item['Guid'] : 'None',
                ]);

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_supported_guid");
                return;
            }

            $mapper->add(entity: $entity, opts: [
                Options::AFTER => ag($opts, Options::AFTER, null),
                Options::IMPORT_METADATA_ONLY => true === (bool) ag($context->options, Options::IMPORT_METADATA_ONLY),
                Options::DISABLE_MARK_UNPLAYED => true === (bool) ag($context->options, Options::DISABLE_MARK_UNPLAYED),
                Options::FORCE_FULL => true === (bool) ag($context->options, Options::FORCE_FULL),
                Options::FORCE_REPLACE_METADATA => true === (bool) ag($context->options, Options::FORCE_REPLACE_METADATA),
            ]);
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
                        'response' => [
                            'body' => $item,
                        ],
                        ...exception_log($e),
                    ],
                    e: $e,
                ),
            );
        }
    }
}
