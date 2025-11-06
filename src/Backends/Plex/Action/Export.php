<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Plex\PlexClient;
use App\Libs\Container;
use App\Libs\Enums\Http\Method;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface;
use Throwable;

final class Export extends Import
{
    protected string $action = 'plex.export';

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
                $this->logger->debug("{action}: Processing '{client}: {user}@{backend}' response payload.", [
                    ...$logContext,
                    'response' => ['body' => $item],
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
                            r("Unexpected content type '{type}' was received.", ['type' => $type])
                        ),
                    },
                    'type' => $type,
                ];
            } catch (InvalidArgumentException $e) {
                $this->logger->error(
                    ...lw(
                        message: "{action}: Failed to parse '{client}: {user}@{backend}' item response. '{error.kind}' with '{error.message}' at '{error.file}:{error.line}' ",
                        context: [
                            ...$logContext,
                            'error' => [
                                'kind' => $e::class,
                                'line' => $e->getLine(),
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                            'response' => ['body' => $item],
                        ],
                        e: $e
                    )
                );
                return;
            }

            if (null === ag($item, true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt')) {
                $this->logger->debug(
                    message: "{action}: Ignoring '{client}: {user}@{backend}' {item.type} '{item.title}'. No Date is set on object.",
                    context: [
                        ...$logContext,
                        'date_key' => true === (bool)ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt',
                        'response' => ['body' => $item],
                    ]
                );

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
                $message = "{action}: Ignoring '{client}: {user}@{backend}' - '{item.title}'. No valid/supported external ids.";

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
                    'context' => ['guids' => !empty($item['Guid']) ? $item['Guid'] : 'None'],
                ]);

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_supported_guid");
                return;
            }

            if (false === ag($context->options, Options::IGNORE_DATE, false)) {
                if (true === ($after instanceof DateTimeInterface) && $rItem->updated >= $after->getTimestamp()) {
                    $this->logger->debug(
                        message: "{action}: Ignoring '{client}: {user}@{backend}' - '{item.title}'. Backend date '{backend_date}' is equal or newer than last sync date '{last_sync}'.",
                        context: [
                            ...$logContext,
                            'last_sync' => makeDate($after),
                            'backend_date' => makeDate($rItem->updated),
                        ]
                    );

                    Message::increment("{$context->backendName}.{$mappedType}.ignored_date_is_equal_or_higher");
                    return;
                }
            }

            if (null === ($entity = $mapper->get($rItem))) {
                $message = "{action}: Ignoring '{client}: {user}@{backend}' - '{item.title}'. {item.type} is not imported yet.";
                if (true === (bool)ag($context->options, Options::IMPORT_METADATA_ONLY)) {
                    $message .= " The backend is marked as metadata source only.";
                }

                $this->logger->info(message: $message, context: $logContext);
                Message::increment("{$context->backendName}.{$mappedType}.ignored_not_found_in_db");
                return;
            }

            if ($rItem->watched === $entity->watched) {
                if (true === (bool)ag($context->options, Options::DEBUG_TRACE)) {
                    $this->logger->debug(
                        message: "{action}: Ignoring '{client}: {backend}' - '{item.title}'. {item.type} play state is identical.",
                        context: $logContext,
                    );
                }

                Message::increment("{$context->backendName}.{$mappedType}.ignored_state_unchanged");
                return;
            }

            if ($rItem->updated >= $entity->updated && false === ag($context->options, Options::IGNORE_DATE, false)) {
                $this->logger->debug(
                    message: "{action}: Ignoring '{client}: {user}@{backend}' - '{item.title}'. Backend date '{backend_date}' is equal or newer than local history date '{db_date}'.",
                    context: [
                        ...$logContext,
                        'db_date' => makeDate($entity->updated),
                        'backend_date' => makeDate($rItem->updated),
                    ]
                );

                Message::increment("{$context->backendName}.{$mappedType}.ignored_date_is_newer");
                return;
            }

            $url = $context->backendUrl->withPath('/:' . ($entity->isWatched() ? '/scrobble' : '/unscrobble'));
            $url = $url->withQuery(
                http_build_query(['identifier' => 'com.plexapp.plugins.library', 'key' => $item['ratingKey']])
            );

            $logContext['item']['url'] = $url;

            Message::increment("{$context->userContext->name}.{$context->backendName}.export");

            if (true === (bool)ag($context->options, Options::DRY_RUN, false)) {
                $this->logger->notice(
                    message: "{action}: Queuing request to change '{client}: {user}@{backend}' {item.type} '{item.title}' play state to '{play_state}'.",
                    context: [...$logContext, 'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed']
                );
                return;
            }

            $queue->add(
                $this->http->request(
                    method: Method::GET,
                    url: (string)$url,
                    options: array_replace_recursive($context->getHttpOptions(), [
                        'user_data' => [
                            'context' => $logContext + ['play_state' => $entity->isWatched() ? 'Played' : 'Unplayed'],
                        ]
                    ])
                )
            );
        } catch (Throwable $e) {
            $this->logger->error(
                ...lw(
                    message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' export. {error.message} at '{error.file}:{error.line}'.",
                    context: [
                        ...$logContext,
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
        }
    }
}
