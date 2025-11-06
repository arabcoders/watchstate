<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Jellyfin\JellyfinClient;
use App\Backends\Jellyfin\JellyfinClient as JFC;
use App\Libs\Container;
use App\Libs\Enums\Http\Method;
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
        $logContext['action'] = $this->action;
        $logContext['client'] = $context->clientName;
        $logContext['backend'] = $context->backendName;
        $logContext['user'] = $context->userContext->name;

        if (JFC::TYPE_SHOW === ($type = ag($item, 'Type'))) {
            $this->processShow(context: $context, guid: $guid, item: $item, logContext: $logContext);
            return;
        }

        $mappedType = (string)(JFC::TYPE_MAPPER[$type] ?? $type);

        try {
            if ($context->trace) {
                $this->logger->debug("{action}: Processing '{client}: {user}@{backend}' response payload.", [
                    ...$logContext,
                    'response' => ['body' => $item],
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
                            r("Unexpected content type '{type}' was received.", ['type' => $type])
                        ),
                    },
                    'type' => $type,
                ];
            } catch (InvalidArgumentException $e) {
                $this->logger->info(...lw(message: $e->getMessage(), context: [
                    ...$logContext,
                    'response' => ['body' => $item],
                ], e: $e));
                return;
            }

            $isPlayed = true === (bool)ag($item, 'UserData.Played');
            $dateKey = true === $isPlayed ? 'UserData.LastPlayedDate' : 'DateCreated';

            if (null === ag($item, $dateKey)) {
                $this->logger->debug(
                    message: "{action}: Ignoring '{client}: {user}@{backend}' {item.type} '{item.title}'. No date is set on object.",
                    context: [
                        'date_key' => $dateKey,
                        ...$logContext,
                        'response' => [
                            'body' => $item,
                        ],
                    ]
                );

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

                $message = "{action}: Ignoring '{client}: {user}@{backend}' - '{item.title}'. No valid/supported external ids.";

                if (empty($providerIds)) {
                    $message .= ' Most likely unmatched {item.type}.';
                }

                $this->logger->info($message, [
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
                        message: "{action}: Ignoring '{client}: {user}@{backend}' - '{item.title}'. Backend date is equal or newer than last sync date.",
                        context: [
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
                    message: "{action}: Ignoring '{client}: {user}@{backend}' - '{item.title}'. {item.type} Is not imported yet. Possibly because the backend was imported as metadata only.",
                    context: $logContext,
                );
                Message::increment("{$context->backendName}.{$mappedType}.ignored_not_found_in_db");
                return;
            }

            if ($rItem->watched === $entity->watched) {
                if (true === (bool)ag($context->options, Options::DEBUG_TRACE)) {
                    $this->logger->debug(
                        message: "{action}: Ignoring '{client}: {user}@{backend}' - '{item.title}'. {item.type} play state is identical.",
                        context: [
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
                    message: "{action}: Ignoring '{client}: {user}@{backend}' - '{item.title}'. Backend date is equal or newer than database date.",
                    context: [
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

            $url = $context->backendUrl->withPath(r('/Users/{user}/PlayedItems/{id}', [
                'user' => $context->backendUser,
                'id' => ag($item, 'Id')
            ]));

            $lastPlayed = makeDate($entity->updated)->format(Date::ATOM);

            if ($context->clientName === JellyfinClient::CLIENT_NAME) {
                $url = $url->withQuery(http_build_query(['DatePlayed' => $lastPlayed]));
            }

            $logContext['item']['url'] = $url;

            Message::increment("{$context->userContext->name}.{$context->backendName}.export");

            if (true === (bool)ag($context->options, Options::DRY_RUN, false)) {
                $this->logger->notice(
                    message: "{action}: Queuing request to change '{client}: {user}@{backend}' {item.type} '{item.title}' play state to '{play_state}'.",
                    context: [
                        ...$logContext,
                        'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                    ]
                );
                return;
            }

            $queue->add(
                $this->http->request(
                    method: $entity->isWatched() ? Method::POST : Method::DELETE,
                    url: (string)$url,
                    options: $context->getHttpOptions() + [
                        'user_data' => [
                            'context' => $logContext + [
                                    'backend' => $context->backendName,
                                    'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                ],
                        ],
                    ]
                )
            );

            /**
             * A workaround for some API limitations,
             * Jellyfin: sometimes doesn't reset the `PlaybackPositionTicks`.
             * Emby: Doesn't support sending `LastPlayedDate` in the initial request.
             */
            if (true === $entity->isWatched()) {
                $queue->add(
                    $this->http->request(
                        method: Method::POST,
                        url: (string)$context->backendUrl->withPath(r('/Users/{user}/Items/{id}/UserData', [
                            'user' => $context->backendUser,
                            'id' => ag($item, 'Id')
                        ])),
                        options: $context->getHttpOptions() + [
                            'json' => [
                                'Played' => true,
                                'PlaybackPositionTicks' => 0,
                                'LastPlayedDate' => $lastPlayed,
                            ],
                            'user_data' => [Options::NO_LOGGING => true]
                        ]
                    )
                );
            }
        } catch (Throwable $e) {
            $this->logger->error(
                ...lw(
                    message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' export. '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
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
        }
    }
}
