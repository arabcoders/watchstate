<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexClient;
use App\Libs\Data;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
use Throwable;

final class Export extends Import
{
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
                callback: fn(array $item, array $logContext = []) => $this->export(
                    context:    $context,
                    guid:       $guid,
                    queue:      $opts['queue'],
                    mapper:     $mapper,
                    item:       $item,
                    logContext: $logContext,
                    opts:       ['after' => $after],
                ),
                logContext: $logContext,
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

    private function export(
        Context $context,
        iGuid $guid,
        QueueRequests $queue,
        ImportInterface $mapper,
        array $item,
        array $logContext = [],
        array $opts = [],
    ): void {
        $after = ag($opts, 'after', null);
        $library = ag($logContext, 'library.id');
        $type = ag($item, 'type');

        if (PlexClient::TYPE_SHOW === $type) {
            $this->processShow($context, $guid, $item, $logContext);
            return;
        }

        try {
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
                'type' => $type,
            ];

            if ($context->trace) {
                $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title)] payload.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'payload' => $item,
                ]);
            }

            if (null === ag($item, true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt')) {
                $this->logger->debug('Ignoring [%(backend)] [%(item.title)]. No Date is set on object.', [
                    'backend' => $context->backendName,
                    'date_key' => true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt',
                    ...$logContext,
                    'response' => [
                        'body' => $item,
                    ],
                ]);

                Data::increment($context->backendName, $type . '_ignored_no_date_is_set');
                return;
            }

            $rItem = $this->createEntity(
                context: $context,
                guid:    $guid,
                item:    $item,
                opts:    $opts
            );

            if (!$rItem->hasGuids() && !$rItem->hasRelativeGuid()) {
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
                    'context' => [
                        'guids' => !empty($item['Guid']) ? $item['Guid'] : 'None'
                    ],
                ]);

                Data::increment($context->backendName, $type . '_ignored_no_supported_guid');
                return;
            }

            if (false === ag($context->options, Options::IGNORE_DATE, false)) {
                if (true === ($after instanceof DateTimeInterface) && $rItem->updated >= $after->getTimestamp()) {
                    $this->logger->debug(
                        'Ignoring [%(backend)] [%(item.title)]. Backend date is equal or newer than last sync date.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                            'comparison' => [
                                'lastSync' => makeDate($after),
                                'backend' => makeDate($rItem->updated),
                            ],
                        ]
                    );

                    Data::increment($context->backendName, $type . '_ignored_date_is_equal_or_higher');
                    return;
                }
            }

            if (null === ($entity = $mapper->get($rItem))) {
                $this->logger->warning('Ignoring [%(backend)] [%(item.title)]. %(item.type) Is not imported yet.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                ]);
                Data::increment($context->backendName, $type . '_ignored_not_found_in_db');
                return;
            }

            if ($rItem->watched === $entity->watched) {
                if (true === (bool)ag($context->options, Options::DEBUG_TRACE)) {
                    $this->logger->debug(
                        'Ignoring [%(backend)] [%(item.title)]. %(item.type) play state is identical.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                            'comparison' => [
                                'backend' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                'remote' => $rItem->isWatched() ? 'Played' : 'Unplayed',
                            ],
                        ]
                    );
                }

                Data::increment($context->backendName, $type . '_ignored_state_unchanged');
                return;
            }

            if ($rItem->updated >= $entity->updated && false === ag($context->options, Options::IGNORE_DATE, false)) {
                $this->logger->debug(
                    'Ignoring [%(backend)] [%(item.title)]. Backend date is equal or newer than storage date.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                        'comparison' => [
                            'storage' => makeDate($entity->updated),
                            'backend' => makeDate($rItem->updated),
                        ],
                    ]
                );

                Data::increment($context->backendName, $type . '_ignored_date_is_newer');
                return;
            }

            $url = $context->backendUrl->withPath(
                '/:' . ($entity->isWatched() ? '/scrobble' : '/unscrobble')
            )->withQuery(
                http_build_query(
                    [
                        'identifier' => 'com.plexapp.plugins.library',
                        'key' => $item['ratingKey'],
                    ]
                )
            );

            $logContext['item']['url'] = $url;

            $this->logger->debug(
                'Queuing Request to change [%(backend)] [%(item.title)] play state to [%(play_state)].',
                [
                    'backend' => $context->backendName,
                    'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                    ...$logContext,
                ]
            );

            if (false === (bool)ag($context->options, Options::DRY_RUN, false)) {
                $queue->add(
                    $this->http->request(
                        'GET',
                        (string)$url,
                        array_replace_recursive($context->backendHeaders, [
                            'user_data' => [
                                'context' => $logContext + [
                                        'backend' => $context->backendName,
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
                    'backend' => $context->backendName,
                    ...$logContext,
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
}
