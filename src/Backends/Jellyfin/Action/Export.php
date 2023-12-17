<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Jellyfin\JellyfinClient;
use App\Backends\Jellyfin\JellyfinClient as JFC;
use App\Libs\Container;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Extends\Date;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface;
use Throwable;

/**
 * Class Export
 *
 * Represents a class for exporting data to Jellyfin.
 *
 * @extends Import
 */
class Export extends Import
{
    protected string $action = 'jellyfin.export';

    /**
     * Process given item.
     *
     * @param Context $context The context object.
     * @param iGuid $guid The guid object.
     * @param iImport $mapper The mapper object.
     * @param array $item The item to be processed.
     * @param array $logContext The log context (optional).
     * @param array $opts The options (optional).
     * @return void
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
                $this->logger->debug('Processing [{backend}] payload.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'body' => $item,
                ]);
            }

            $queue = ag($opts, 'queue', fn() => Container::get(QueueRequests::class));
            $after = ag($opts, 'after', null);

            Message::increment("{$context->backendName}.{$mappedType}.total");

            try {
                $logContext['item'] = [
                    'id' => ag($item, 'Id'),
                    'title' => match ($type) {
                        JFC::TYPE_MOVIE => r('{title} ({year})', [
                            'title' => ag($item, ['Name', 'OriginalTitle'], '??'),
                            'year' => ag($item, 'ProductionYear', '0000'),
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

            $isPlayed = true === (bool)ag($item, 'UserData.Played');
            $dateKey = true === $isPlayed ? 'UserData.LastPlayedDate' : 'DateCreated';

            if (null === ag($item, $dateKey)) {
                $this->logger->debug('Ignoring [{backend}] {item.type} [{item.title}]. No Date is set on object.', [
                    'backend' => $context->backendName,
                    'date_key' => $dateKey,
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
                opts: $opts + ['library' => ag($logContext, 'library.id')]
            );

            if (!$rItem->hasGuids() && !$rItem->hasRelativeGuid()) {
                $providerIds = (array)ag($item, 'ProviderIds', []);

                $message = 'Ignoring [{backend}] [{item.title}]. No valid/supported external ids.';

                if (empty($providerIds)) {
                    $message .= ' Most likely unmatched {item.type}.';
                }

                $this->logger->info($message, [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'context' => [
                        'guids' => !empty($providerIds) ? $providerIds : 'None'
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
                r('/Users/{user}/PlayedItems/{id}', [
                    'user' => $context->backendUser,
                    'id' => ag($item, 'Id')
                ])
            );

            if ($context->clientName === JellyfinClient::CLIENT_NAME) {
                $url = $url->withQuery(
                    http_build_query([
                        'DatePlayed' => makeDate($entity->updated)->format(Date::ATOM)
                    ])
                );
            }

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
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] export. Error [{error.message} @ {error.file}:{error.line}].',
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
