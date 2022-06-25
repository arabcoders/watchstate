<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexActionTrait;
use App\Backends\Plex\PlexClient;
use App\Libs\Data;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Options;
use Closure;
use DateTimeInterface;
use JsonException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
use Throwable;

class Import
{
    use CommonTrait, PlexActionTrait;

    public function __construct(protected HttpClientInterface $http, protected LoggerInterface $logger)
    {
    }

    /**
     * @param Context $context
     * @param iGuid $guid
     * @param ImportInterface $mapper
     * @param DateTimeInterface|null $after
     * @param array $opts
     *
     * @return Response
     */
    public function __invoke(
        Context $context,
        iGuid $guid,
        ImportInterface $mapper,
        DateTimeInterface|null $after = null,
        array $opts = []
    ): Response {
        return $this->tryResponse($context, fn() => $this->getLibraries(
            context: $context,
            handle: fn(array $logContext = []) => fn(iResponse $response) => $this->handle(
                context:    $context,
                response:   $response,
                callback: fn(array $item, array $logContext = []) => $this->process(
                    context:    $context,
                    guid:       $guid,
                    mapper:     $mapper,
                    item:       $item,
                    logContext: $logContext,
                    opts:       $opts + ['after' => $after],
                ),
                logContext: $logContext
            ),
            error: fn(array $logContext = []) => fn(Throwable $e) => $this->logger->error(
                'Unhandled Exception was thrown during [%(backend)] library [%(library.title)] request.',
                [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                    ],
                ]
            ),
        ));
    }

    protected function getLibraries(Context $context, Closure $handle, Closure $error): array
    {
        try {
            $url = $context->backendUrl->withPath('/library/sections');

            $this->logger->debug('Requesting [%(backend)] libraries.', [
                'backend' => $context->backendName,
                'url' => $url
            ]);

            $response = $this->http->request('GET', (string)$url, $context->backendHeaders);

            if (200 !== $response->getStatusCode()) {
                $this->logger->error(
                    'Request for [%(backend)] libraries returned with unexpected [%(status_code)] status code.',
                    [
                        'backend' => $context->backendName,
                        'status_code' => $response->getStatusCode(),
                    ]
                );

                Data::add($context->backendName, 'has_errors', true);
                return [];
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            $listDirs = ag($json, 'MediaContainer.Directory', []);

            if (empty($listDirs)) {
                $this->logger->warning('Request for [%(backend)] libraries returned with empty list.', [
                    'backend' => $context->backendName,
                    'body' => $json,
                ]);
                Data::add($context->backendName, 'has_errors', true);
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error('Request for [%(backend)] libraries has failed.', [
                'backend' => $context->backendName,
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'message' => $e->getMessage(),
                ],
                'trace' => $context->trace ? $e->getTrace() : [],
            ]);
            Data::add($context->backendName, 'has_errors', true);
            return [];
        } catch (JsonException $e) {
            $this->logger->error('Request for [%(backend)] libraries returned with invalid body.', [
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage(),
                ],
                'trace' => $context->trace ? $e->getTrace() : [],
            ]);
            Data::add($context->backendName, 'has_errors', true);
            return [];
        }

        if (null !== ($ignoreIds = ag($context->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => (int)trim($v), explode(',', (string)$ignoreIds));
        }

        $requests = [];
        $ignored = $unsupported = 0;

        // -- Get TV shows metadata.
        foreach ($listDirs as $section) {
            $key = (int)ag($section, 'key');

            if (PlexClient::TYPE_SHOW !== ag($section, 'type', 'unknown')) {
                continue;
            }

            if (true === in_array($key, $ignoreIds ?? [])) {
                continue;
            }

            $url = $context->backendUrl->withPath(sprintf('/library/sections/%d/all', $key))->withQuery(
                http_build_query(['type' => 2, 'includeGuids' => 1])
            );

            $logContext = [
                'library' => [
                    'id' => $key,
                    'title' => ag($section, 'title', '??'),
                    'type' => ag($section, 'type', 'unknown'),
                    'url' => $url,
                ],
            ];

            $this->logger->debug('Requesting [%(backend)] [%(library.title)] series external ids.', [
                'backend' => $context->backendName,
                ...$logContext,
            ]);

            try {
                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    $context->backendHeaders + [
                        'user_data' => [
                            'ok' => $handle($logContext),
                            'error' => $error($logContext),
                        ]
                    ]
                );
            } catch (ExceptionInterface $e) {
                $this->logger->error(
                    'Request for [%(backend)] [%(library.title)] series external ids has failed.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                        ],
                        'trace' => $context->trace ? $e->getTrace() : [],
                    ]
                );
                continue;
            } catch (Throwable $e) {
                $this->logger->error(
                    'Unhandled exception was thrown during [%(backend)] [%(library.title)] series external ids request.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                        ],
                        'trace' => $context->trace ? $e->getTrace() : [],
                    ]
                );
                continue;
            }
        }

        // -- Get Movies/episodes.
        foreach ($listDirs as $section) {
            $key = (int)ag($section, 'key');

            $logContext = [
                'library' => [
                    'id' => ag($section, 'key'),
                    'title' => ag($section, 'title', '??'),
                    'type' => ag($section, 'type', 'unknown'),
                ],
            ];

            if (true === in_array($key, $ignoreIds ?? [])) {
                $ignored++;
                $this->logger->info('Ignoring [%(backend)] [%(library.title)]. Requested by user config.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                ]);
                continue;
            }

            if (!in_array(ag($logContext, 'library.type'), [PlexClient::TYPE_MOVIE, PlexClient::TYPE_SHOW])) {
                $unsupported++;
                $this->logger->info(
                    'Ignoring [%(backend)] [%(library.title)]. Library type [%(library.type)] is not supported.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                    ]
                );
                continue;
            }

            $url = $context->backendUrl->withPath(sprintf('/library/sections/%d/all', $key))->withQuery(
                http_build_query(
                    [
                        'type' => PlexClient::TYPE_MOVIE === ag($logContext, 'library.type') ? 1 : 4,
                        'includeGuids' => 1,
                    ]
                )
            );

            $logContext['library']['url'] = $url;

            $this->logger->debug('Requesting [%(backend)] [%(library.title)] content list.', [
                'backend' => $context->backendName,
                ...$logContext,
            ]);

            try {
                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    $context->backendHeaders + [
                        'user_data' => [
                            'ok' => $handle($logContext),
                            'error' => $error($logContext),
                        ]
                    ],
                );
            } catch (ExceptionInterface $e) {
                $this->logger->error('Requesting for [%(backend)] [%(library.title)] content list has failed.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                    ],
                    'trace' => $context->trace ? $e->getTrace() : [],
                ]);
                continue;
            } catch (Throwable $e) {
                $this->logger->error(
                    'Unhandled exception was thrown during [%(backend)] [%(library.title)] content list request.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                        ],
                        'trace' => $context->trace ? $e->getTrace() : [],
                    ]
                );
                continue;
            }
        }

        if (0 === count($requests)) {
            $this->logger->warning('No requests for [%(backend)] libraries were queued.', [
                'backend' => $context->backendName,
                'context' => [
                    'total' => count($listDirs),
                    'ignored' => $ignored,
                    'unsupported' => $unsupported,
                ],
            ]);

            Data::add($context->backendName, 'has_errors', true);
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
                'Request for [%(backend)] [%(library.title)] content returned with unexpected [%(status_code)] status code.',
                [
                    'backend' => $context->backendName,
                    'status_code' => $response->getStatusCode(),
                    ...$logContext,
                ]
            );
            return;
        }

        $start = makeDate();
        $this->logger->info('Parsing [%(backend)] library [%(library.title)] response.', [
            'backend' => $context->backendName,
            ...$logContext,
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
                'Unhandled exception was thrown during parsing of [%(backend)] library [%(library.title)] response.',
                [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                    ],
                    'trace' => $context->trace ? $e->getTrace() : [],
                ]
            );
        }

        $end = makeDate();
        $this->logger->info('Parsing [%(backend)] library [%(library.title)] response is complete.', [
            'backend' => $context->backendName,
            ...$logContext,
            'time' => [
                'start' => $start,
                'end' => $end,
                'duration' => number_format($end->getTimestamp() - $start->getTimestamp()),
            ],
        ]);
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
            'title' => sprintf(
                '%s (%s)',
                ag($item, ['title', 'originalTitle'], '??'),
                0 === $year ? '0000' : $year,
            ),
            'year' => 0 === $year ? '0000' : $year,
            'type' => ag($item, 'type', 'unknown'),
        ];

        if ($context->trace) {
            $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title) (%(item.year))].', [
                'backend' => $context->backendName,
                ...$logContext,
                'body' => $item,
            ]);
        }

        if (!$guid->has(guids: $guids, context: $logContext)) {
            $message = 'Ignoring [%(backend)] [%(item.title)]. %(item.type) has no valid/supported external ids.';

            if (empty($guids)) {
                $message .= ' Most likely unmatched %(item.type).';
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
        ImportInterface $mapper,
        array $item,
        array $logContext = [],
        array $opts = []
    ): void {
        $after = ag($opts, 'after', null);
        $library = ag($logContext, 'library.id');
        $type = ag($item, 'type');

        try {
            if (PlexClient::TYPE_SHOW === $type) {
                $this->processShow($context, $guid, $item, $logContext);
                return;
            }

            Data::increment($context->backendName, $library . '_total');
            Data::increment($context->backendName, $type . '_total');

            $year = (int)ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
            if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
                $year = (int)makeDate($airDate)->format('Y');
            }

            $logContext['item'] = [
                'id' => ag($item, 'ratingKey'),
                'title' => match ($type) {
                    PlexClient::TYPE_MOVIE => sprintf(
                        '%s (%s)',
                        ag($item, ['title', 'originalTitle'], '??'),
                        0 === $year ? '0000' : $year,
                    ),
                    PlexClient::TYPE_EPISODE => sprintf(
                        '%s - (%sx%s)',
                        ag($item, ['grandparentTitle', 'originalTitle', 'title'], '??'),
                        str_pad((string)ag($item, 'parentIndex', 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)ag($item, 'index', 0), 3, '0', STR_PAD_LEFT),
                    ),
                },
                'type' => ag($item, 'type', 'unknown'),
            ];

            if ($context->trace) {
                $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title)]', [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'body' => $item,
                ]);
            }

            if (null === ag($item, true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt')) {
                $this->logger->debug('Ignoring [%(backend)] %(item.type) [%(item.title)]. No Date is set on object.', [
                    'backend' => $context->backendName,
                    'date_key' => true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt',
                    ...$logContext,
                    'body' => $item,
                ]);

                Data::increment($context->backendName, $type . '_ignored_no_date_is_set');
                return;
            }

            $entity = $this->createEntity(
                context: $context,
                guid:    $guid,
                item:    $item,
                opts:    $opts + [
                             'override' => [
                                 iFace::COLUMN_EXTRA => [
                                     $context->backendName => [
                                         iFace::COLUMN_EXTRA_EVENT => 'task.import',
                                         iFace::COLUMN_EXTRA_DATE => makeDate('now'),
                                     ],
                                 ],
                             ],
                         ]
            );

            if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
                $message = 'Ignoring [%(backend)] [%(item.title)]. No valid/supported external ids.';

                if (null === ($item['Guid'] ?? null)) {
                    $item['Guid'] = [];
                }

                if (null !== ($itemGuid = ag($item, 'guid')) && false === $guid->isLocal($itemGuid)) {
                    $item['Guid'][] = $itemGuid;
                }

                if (empty($item['Guid'])) {
                    $message .= ' Most likely unmatched %(item.type).';
                }

                $this->logger->info($message, [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'guids' => !empty($item['Guid']) ? $item['Guid'] : 'None'
                ]);

                Data::increment($context->backendName, $type . '_ignored_no_supported_guid');
                return;
            }

            $mapper->add(entity: $entity, opts: [
                'after' => $after,
                Options::IMPORT_METADATA_ONLY => true === (bool)ag($context->options, Options::IMPORT_METADATA_ONLY),
            ]);
        } catch (Throwable $e) {
            $this->logger->error(
                'Unhandled exception was thrown during handling of [%(backend)] [%(library.title)] [%(item.title)] import.',
                [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                    ],
                    'trace' => $context->trace ? $e->getTrace() : [],
                ]
            );
        }
    }
}
