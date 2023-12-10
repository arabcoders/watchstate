<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Plex\PlexClient;
use App\Libs\Container;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface;
use InvalidArgumentException;
use Throwable;

final class Export extends Import
{
    protected function process(
        Context $context,
        iGuid $guid,
        iImport $mapper,
        array $item,
        array $logContext = [],
        array $opts = [],
    ): void {
        $queue = ag($opts, 'queue', fn() => Container::get(QueueRequests::class));
        $after = ag($opts, 'after', null);
        $library = ag($logContext, 'library.id');
        $type = ag($item, 'type');

        if (PlexClient::TYPE_SHOW === $type) {
            $this->processShow($context, $guid, $item, $logContext);
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

            Message::increment("{$context->backendName}.{$library}.total");
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
                    'type' => $type,
                ];
            } catch (InvalidArgumentException $e) {
                $this->logger->info($e->getMessage(), [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'body' => $item,
                ]);
                return;
            }

            if (null === ag($item, true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt')) {
                $this->logger->debug('Ignoring [{backend}] [{item.title}]. No Date is set on object.', [
                    'backend' => $context->backendName,
                    'date_key' => true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt',
                    ...$logContext,
                    'response' => [
                        'body' => $item,
                    ],
                ]);

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_date_is_set");
                return;
            }

            $rItem = $this->createEntity(
                context: $context,
                guid: $guid,
                item: $item,
                opts: $opts
            );

            if (!$rItem->hasGuids() && !$rItem->hasRelativeGuid()) {
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
                    'context' => [
                        'guids' => !empty($item['Guid']) ? $item['Guid'] : 'None'
                    ],
                ]);

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_supported_guid");
                return;
            }

            if (false === ag($context->options, Options::IGNORE_DATE, false)) {
                if (true === ($after instanceof DateTimeInterface) && $rItem->updated >= $after->getTimestamp()) {
                    $this->logger->debug(
                        'Ignoring [{backend}] [{item.title}]. Backend date is equal or newer than last sync date.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                            'comparison' => [
                                'lastSync' => makeDate($after),
                                'backend' => makeDate($rItem->updated),
                            ],
                        ]
                    );

                    Message::increment("{$context->backendName}.{$mappedType}.ignored_date_is_equal_or_higher");
                    return;
                }
            }

            if (null === ($entity = $mapper->get($rItem))) {
                $this->logger->info(
                    'Ignoring [{backend}] [{item.title}]. {item.type} Is not imported yet. Possibly because the backend was imported as metadata only.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                    ]
                );
                Message::increment("{$context->backendName}.{$mappedType}.ignored_not_found_in_db");
                return;
            }

            if ($rItem->watched === $entity->watched) {
                if (true === (bool)ag($context->options, Options::DEBUG_TRACE)) {
                    $this->logger->debug(
                        'Ignoring [{backend}] [{item.title}]. {item.type} play state is identical.',
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

                Message::increment("{$context->backendName}.{$mappedType}.ignored_state_unchanged");
                return;
            }

            if ($rItem->updated >= $entity->updated && false === ag($context->options, Options::IGNORE_DATE, false)) {
                $this->logger->debug(
                    'Ignoring [{backend}] [{item.title}]. Backend date is equal or newer than database date.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                        'comparison' => [
                            'database' => makeDate($entity->updated),
                            'backend' => makeDate($rItem->updated),
                        ],
                    ]
                );

                Message::increment("{$context->backendName}.{$mappedType}.ignored_date_is_newer");
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
                'Queuing Request to change [{backend}] [{item.title}] play state to [{play_state}].',
                [
                    'backend' => $context->backendName,
                    'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                    ...$logContext,
                ]
            );

            if (true === (bool)ag($context->options, Options::DRY_RUN, false)) {
                return;
            }

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
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] backup. Error [{error.message} @ {error.file}:{error.line}].',
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
