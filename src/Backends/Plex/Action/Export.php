<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Request;
use App\Backends\Plex\PlexClient;
use App\Libs\Container;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
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
        $queue = ag($opts, 'queue', static fn() => Container::get(QueueRequests::class));
        $after = ag($opts, 'after', null);
        $logContext = array_replace_recursive([
            'action' => $this->action,
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
        ], $logContext);
        $library = ag($logContext, 'library.id');
        $type = ag($item, 'type');

        if (PlexClient::TYPE_SHOW === $type) {
            $this->processShow($context, $guid, $item, $logContext);
            return;
        }

        $mappedType = PlexClient::TYPE_MAPPER[$type] ?? $type;

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

            Message::increment("{$context->backendName}.{$library}.total");
            Message::increment("{$context->backendName}.{$mappedType}.total");

            $year = (int) ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
            if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
                $year = (int) make_date($airDate)->format('Y');
            }

            try {
                $logContext['item'] = [
                    'remote_id' => null === ag($item, 'ratingKey') ? null : (string) ag($item, 'ratingKey'),
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

            if (null === ag($item, true === (bool) ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt')) {
                $this->logger->debug(
                    message: "Ignoring {item.type} '{item.title}' from '{user}@{backend}': missing date '{date_key}'.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.export',
                        'operation' => 'process_item',
                        'outcome' => 'ignored',
                        'reason' => 'missing_date',
                        ...$logContext,
                        'date_key' => true === (bool) ag($item, 'viewCount', false) ? 'lastViewedAt' : 'addedAt',
                        'response' => ['body' => $item],
                    ],
                );

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_date_is_set");
                return;
            }

            $rItem = $this->createEntity(
                context: $context,
                guid: $guid,
                item: $item,
                opts: $opts,
            );

            if (!$rItem->hasGuids() && !$rItem->hasRelativeGuid()) {
                $message = "Ignoring {item.type} '{item.title}' from '{user}@{backend}': no supported external IDs.";

                if (null === ($item['Guid'] ?? null)) {
                    $item['Guid'] = [];
                }

                if (null !== ($itemGuid = ag($item, 'guid')) && false === $guid->isLocal($itemGuid)) {
                    $item['Guid'][] = $itemGuid;
                }

                if (empty($item['Guid'])) {
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
                        'guids' => !empty($item['Guid']) ? $item['Guid'] : 'None',
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
                            'last_sync' => make_date($after),
                            'backend_date' => make_date($rItem->updated),
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
                        'db_date' => make_date($entity->updated),
                        'backend_date' => make_date($rItem->updated),
                    ],
                );

                Message::increment("{$context->backendName}.{$mappedType}.ignored_date_is_newer");
                return;
            }

            $url = $context->backendUrl->withPath('/:' . ($entity->isWatched() ? '/scrobble' : '/unscrobble'));
            $url = $url->withQuery(
                http_build_query(['identifier' => 'com.plexapp.plugins.library', 'key' => $item['ratingKey']]),
            );

            $logContext['item']['url'] = (string) $url;

            Message::increment("{$context->userContext->name}.{$context->backendName}.export");

            $requestContext = [
                ...$logContext,
                'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                ...$this->timeContext($entity->updated, $rItem->updated),
            ];

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
                    method: Method::GET,
                    url: $url,
                    options: $context->getHttpOptions(),
                    success: function (iResponse $response) use ($requestContext): array {
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

                        return [];
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
