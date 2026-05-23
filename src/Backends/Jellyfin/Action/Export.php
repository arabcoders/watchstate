<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Request;
use App\Backends\Jellyfin\JellyfinClient;
use App\Backends\Jellyfin\JellyfinClient as JFC;
use App\Libs\Container;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Extends\Date;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
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
        $logContext = array_replace_recursive([
            'action' => $this->action,
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
        ], $logContext);

        if (JFC::TYPE_SHOW === ($type = ag($item, 'Type'))) {
            $this->processShow(context: $context, guid: $guid, item: $item, logContext: $logContext);
            return;
        }

        $mappedType = (string) (JFC::TYPE_MAPPER[$type] ?? $type);

        try {
            if ($context->trace) {
                $this->logger->debug(
                    message: "Processing export payload from '{user}@{backend}'.",
                    context: [
                        'event_name' => 'backend.response.received',
                        'subsystem' => 'backend.export',
                        'operation' => 'process_item',
                        'outcome' => 'received',
                        ...$logContext,
                        'response' => ['body' => $item],
                    ],
                );
            }

            $queue = ag($opts, 'queue', static fn() => Container::get(QueueRequests::class));
            $after = ag($opts, 'after', null);

            Message::increment("{$context->backendName}.{$mappedType}.total");

            try {
                $logContext['item'] = [
                    'remote_id' => null === ag($item, 'Id') ? null : (string) ag($item, 'Id'),
                    'title' => match ($type) {
                        JFC::TYPE_MOVIE => r('{title} ({year})', [
                            'title' => ag($item, ['Name', 'OriginalTitle'], '??'),
                            'year' => ag($item, 'ProductionYear', '0000'),
                        ]),
                        JFC::TYPE_EPISODE => r('{title} - ({season}x{episode})', [
                            'title' => ag($item, 'SeriesName', '??'),
                            'season' => str_pad((string) ag($item, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                            'episode' => str_pad((string) ag($item, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                        ]),
                        default => throw new InvalidArgumentException(
                            r("Unexpected content type '{type}' was received.", ['type' => $type]),
                        ),
                    },
                    'type' => $type,
                ];
            } catch (InvalidArgumentException $e) {
                $this->logger->error(
                    ...lw(
                        message: "Failed to parse export item response from '{user}@{backend}'.",
                        context: [
                            'event_name' => 'backend.operation.failed',
                            'subsystem' => 'backend.export',
                            'operation' => 'parse_item',
                            'outcome' => 'failed',
                            ...$logContext,
                            'response' => ['body' => $item],
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );
                return;
            }

            $isPlayed = true === (bool) ag($item, 'UserData.Played');
            $dateKey = true === $isPlayed ? 'UserData.LastPlayedDate' : 'DateCreated';

            if (null === ag($item, $dateKey)) {
                $this->logger->debug(
                    message: "Ignoring {item.type} '{item.title}' from '{user}@{backend}': missing date '{date_key}'.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.export',
                        'operation' => 'process_item',
                        'outcome' => 'ignored',
                        'reason' => 'missing_date',
                        'date_key' => $dateKey,
                        ...$logContext,
                        'response' => [
                            'body' => $item,
                        ],
                    ],
                );

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_date_is_set");
                return;
            }

            $rItem = $this->createEntity(
                context: $context,
                guid: $guid,
                item: $item,
                opts: $opts + ['library' => ag($logContext, 'library.id')],
            );

            if (!$rItem->hasGuids() && !$rItem->hasRelativeGuid()) {
                $providerIds = (array) ag($item, 'ProviderIds', []);

                $message = "Ignoring {item.type} '{item.title}' from '{user}@{backend}': no supported external IDs.";

                if (empty($providerIds)) {
                    $message .= ' Most likely unmatched {item.type}.';
                }

                $this->logger->info(
                    message: $message,
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.export',
                        'operation' => 'create_entity',
                        'outcome' => 'ignored',
                        'reason' => 'missing_supported_guid',
                        ...$logContext,
                        'guids' => !empty($providerIds) ? $providerIds : 'None',
                    ],
                );

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_supported_guid");
                return;
            }

            if (false === ag($context->options, Options::IGNORE_DATE, false)) {
                if (true === $after instanceof DateTimeInterface && $rItem->updated >= $after->getTimestamp()) {
                    $this->logger->debug(
                        message: "Ignoring {item.type} '{item.title}' from '{user}@{backend}': backend date is not newer than last sync date.",
                        context: [
                            'event_name' => 'backend.item.ignored',
                            'subsystem' => 'backend.export',
                            'operation' => 'compare_dates',
                            'outcome' => 'ignored',
                            'reason' => 'date_not_newer_than_last_sync',
                            ...$logContext,
                            'comparison' => [
                                'lastSync' => make_date($after),
                                'backend' => make_date($rItem->updated),
                            ],
                        ],
                    );

                    Message::increment("{$context->backendName}.{$mappedType}.ignored_date_is_equal_or_higher");
                    return;
                }
            }

            if (null === ($entity = $mapper->get($rItem))) {
                $metadataOnly = true === (bool) ag($context->options, Options::IMPORT_METADATA_ONLY);
                $message = "Ignoring {item.type} '{item.title}' from '{user}@{backend}': item is not imported yet.";
                if (true === $metadataOnly) {
                    $message .= ' Backend is configured as metadata-only.';
                }

                $this->logger->info(
                    message: $message,
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.export',
                        'operation' => 'load_local_state',
                        'outcome' => 'ignored',
                        'reason' => 'missing_local_state',
                        ...$logContext,
                        'metadata_only' => $metadataOnly,
                    ],
                );
                Message::increment("{$context->backendName}.{$mappedType}.ignored_not_found_in_db");
                return;
            }

            if ($rItem->watched === $entity->watched) {
                if (true === (bool) ag($context->options, Options::DEBUG_TRACE)) {
                    $this->logger->debug(
                        message: "Ignoring {item.type} '{item.title}' from '{user}@{backend}': play state is unchanged.",
                        context: [
                            'event_name' => 'backend.item.ignored',
                            'subsystem' => 'backend.export',
                            'operation' => 'compare_state',
                            'outcome' => 'ignored',
                            'reason' => 'state_unchanged',
                            ...$logContext,
                            'play_state' => $rItem->isWatched() ? 'Played' : 'Unplayed',
                            'comparison' => [
                                'backend' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                'remote' => $rItem->isWatched() ? 'Played' : 'Unplayed',
                            ],
                        ],
                    );
                }

                Message::increment("{$context->backendName}.{$mappedType}.ignored_state_unchanged");
                return;
            }

            if ($rItem->updated >= $entity->updated && false === ag($context->options, Options::IGNORE_DATE, false)) {
                $this->logger->debug(
                    message: "Ignoring {item.type} '{item.title}' from '{user}@{backend}': backend date is not newer than local history date.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.export',
                        'operation' => 'compare_dates',
                        'outcome' => 'ignored',
                        'reason' => 'date_not_newer_than_local_history',
                        ...$logContext,
                        'comparison' => [
                            'database' => make_date($entity->updated),
                            'backend' => make_date($rItem->updated),
                        ],
                    ],
                );

                Message::increment("{$context->backendName}.{$mappedType}.ignored_date_is_newer");
                return;
            }

            $url = $context->backendUrl->withPath(r('/Users/{user}/PlayedItems/{id}', [
                'user' => $context->backendUser,
                'id' => ag($item, 'Id'),
            ]));

            $lastPlayed = make_date($entity->updated)->format(Date::ATOM);

            if ($context->clientName === JellyfinClient::CLIENT_NAME) {
                $url = $url->withQuery(http_build_query(['DatePlayed' => $lastPlayed]));
            }

            $logContext['item']['url'] = (string) $url;

            Message::increment("{$context->userContext->name}.{$context->backendName}.export");

            $playState = $entity->isWatched() ? 'Played' : 'Unplayed';
            $requestContext = [...$logContext, 'play_state' => $playState];

            if (true === (bool) ag($context->options, Options::DRY_RUN, false)) {
                $this->logger->notice(
                    message: "Would update play state for {item.type} '{item.title}' on '{user}@{backend}' to '{play_state}'.",
                    context: [
                        'event_name' => 'backend.request.skipped',
                        'subsystem' => 'backend.export',
                        'operation' => 'update_state',
                        'outcome' => 'skipped',
                        'reason' => 'dry_run',
                        ...$requestContext,
                    ],
                );
                return;
            }

            $this->logger->debug(
                message: "Updating play state for {item.type} '{item.title}' on '{user}@{backend}' to '{play_state}'.",
                context: [
                    'event_name' => 'backend.request.started',
                    'subsystem' => 'backend.export',
                    'operation' => 'update_state',
                    'outcome' => 'started',
                    ...$requestContext,
                ],
            );

            $queue->add(
                new Request(
                    method: $entity->isWatched() ? Method::POST : Method::DELETE,
                    url: $url,
                    options: $context->getHttpOptions(),
                    success: function (iResponse $response) use ($context, $entity, $item, $lastPlayed, $requestContext): array {
                        $statusCode = $response->getStatusCode();

                        if (Status::OK !== Status::tryFrom($statusCode)) {
                            $this->logger->error(
                                message: "Play-state update for {item.type} '{item.title}' on '{user}@{backend}' returned status {status_code}.",
                                context: [
                                    'event_name' => 'backend.response.failed',
                                    'subsystem' => 'backend.export',
                                    'operation' => 'update_state',
                                    'outcome' => 'failed',
                                    ...$requestContext,
                                    'status_code' => $statusCode,
                                ],
                            );

                            return [];
                        }

                        $this->logger->notice(
                            message: "Updated play state for {item.type} '{item.title}' on '{user}@{backend}' to '{play_state}'.",
                            context: [
                                'event_name' => 'backend.state_update.completed',
                                'subsystem' => 'backend.export',
                                'operation' => 'update_state',
                                'outcome' => 'completed',
                                ...$requestContext,
                            ],
                        );

                        if (true !== $entity->isWatched()) {
                            return [];
                        }

                        return [
                            new Request(
                                method: Method::POST,
                                url: $context->backendUrl->withPath(r('/Users/{user}/Items/{id}/UserData', [
                                    'user' => $context->backendUser,
                                    'id' => ag($item, 'Id'),
                                ])),
                                options: $context->getHttpOptions()
                                + [
                                    'json' => [
                                        'Played' => true,
                                        'PlaybackPositionTicks' => 0,
                                        'LastPlayedDate' => $lastPlayed,
                                    ],
                                    'user_data' => [Options::NO_LOGGING => true],
                                ],
                                extras: [iHttp::class => $this->http],
                            ),
                        ];
                    },
                    error: function (Throwable $e) use ($requestContext): array {
                        $this->logger->error(
                            ...lw(
                                message: "Play-state request failed for {item.type} '{item.title}' on '{user}@{backend}'.",
                                context: [
                                    'event_name' => 'backend.client.request_failed',
                                    'subsystem' => 'backend.export',
                                    'operation' => 'update_state',
                                    'outcome' => 'failed',
                                    ...$requestContext,
                                    ...exception_log($e),
                                ],
                                e: $e,
                            ),
                        );

                        return [];
                    },
                    extras: [
                        'context' => $requestContext,
                        iHttp::class => $this->http,
                    ],
                ),
            );
        } catch (Throwable $e) {
            $message = ag_exists($logContext, 'item.title')
                ? "Export failed for {item.type} '{item.title}' on '{user}@{backend}'."
                : "Export failed for '{user}@{backend}'.";

            $this->logger->error(
                ...lw(
                    message: $message,
                    context: [
                        'event_name' => 'backend.operation.failed',
                        'subsystem' => 'backend.export',
                        'operation' => 'process_item',
                        'outcome' => 'failed',
                        ...$logContext,
                        ...exception_log($e),
                    ],
                    e: $e,
                ),
            );
        }
    }
}
