<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinClient as JFC;
use App\Libs\Data;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
use Throwable;

class Export extends Import
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

    private function export(
        Context $context,
        iGuid $guid,
        QueueRequests $queue,
        ImportInterface $mapper,
        array $item,
        array $logContext = [],
        array $opts = [],
    ): void {
        if (JFC::TYPE_SHOW === ($type = ag($item, 'Type'))) {
            $this->processShow(context: $context, guid: $guid, item: $item, logContext: $logContext);
            return;
        }

        try {
            $after = ag($opts, 'after');
            $type = JFC::TYPE_MAPPER[$type];

            Data::increment($context->backendName, $type . '_total');

            $logContext['item'] = [
                'id' => ag($item, 'Id'),
                'title' => match ($type) {
                    iFace::TYPE_MOVIE => sprintf(
                        '%s (%d)',
                        ag($item, ['Name', 'OriginalTitle'], '??'),
                        ag($item, 'ProductionYear', 0000)
                    ),
                    iFace::TYPE_EPISODE => trim(
                        sprintf(
                            '%s - (%sx%s)',
                            ag($item, 'SeriesName', '??'),
                            str_pad((string)ag($item, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                            str_pad((string)ag($item, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                        )
                    ),
                },
                'type' => $type,
            ];

            if ($context->trace) {
                $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title)] payload.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'response' => [
                        'body' => $item
                    ],
                ]);
            }

            $isPlayed = true === (bool)ag($item, 'UserData.Played');
            $dateKey = true === $isPlayed ? 'UserData.LastPlayedDate' : 'DateCreated';

            if (null === ag($item, $dateKey)) {
                $this->logger->debug('Ignoring [%(backend)] %(item.type) [%(item.title)]. No Date is set on object.', [
                    'backend' => $context->backendName,
                    'date_key' => $dateKey,
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
                opts:    $opts + ['library' => ag($logContext, 'library.id')]
            );

            if (!$rItem->hasGuids() && !$rItem->hasRelativeGuid()) {
                $providerIds = (array)ag($item, 'ProviderIds', []);

                $message = 'Ignoring [%(backend)] [%(item.title)]. No valid/supported external ids.';

                if (empty($providerIds)) {
                    $message .= ' Most likely unmatched %(item.type).';
                }

                $this->logger->info($message, [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'context' => [
                        'guids' => !empty($providerIds) ? $providerIds : 'None'
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
                sprintf('/Users/%s/PlayedItems/%s', $context->backendUser, ag($item, 'Id'))
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
                        $entity->isWatched() ? 'POST' : 'DELETE',
                        (string)$url,
                        $context->backendHeaders + [
                            'user_data' => [
                                'context' => $logContext + [
                                        'backend' => $context->backendName,
                                        'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                    ],
                            ],
                        ]
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
