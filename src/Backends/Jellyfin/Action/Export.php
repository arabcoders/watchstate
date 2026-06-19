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
        $logContext['action'] = $this->action;
        $logContext['identity'] = [
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
        ];

        if (JFC::TYPE_SHOW === ($type = ag($item, 'Type'))) {
            $this->processShow(context: $context, guid: $guid, item: $item, logContext: $logContext);
            return;
        }

        $mappedType = (string) (JFC::TYPE_MAPPER[$type] ?? $type);

        try {
            if ($context->trace) {
                $this->logger->debug("Processing '{identity.user}@{identity.backend}' response payload.", [
                    ...$logContext,
                    'response' => ['body' => $item],
                ]);
            }

            $queue = ag($opts, 'queue', static fn() => Container::get(QueueRequests::class));
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
                $this->logger->info(
                    ...lw(
                        message: $e->getMessage(),
                        context: [
                            ...$logContext,
                            'response' => ['body' => $item],
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
                    message: "Ignoring '{identity.user}@{identity.backend}' {item.type} '{item.title}'. No date is set on object.",
                    context: [
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

                $message = "Ignoring '{identity.user}@{identity.backend}' - '{item.title}'. No valid/supported external ids.";

                if (empty($providerIds)) {
                    $message .= ' Most likely unmatched {item.type}.';
                }

                $this->logger->info($message, [
                    ...$logContext,
                    'context' => [
                        'guids' => !empty($providerIds) ? $providerIds : 'None',
                    ],
                ]);

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_supported_guid");
                return;
            }

            if (false === ag($context->options, Options::IGNORE_DATE, false)) {
                if (true === $after instanceof DateTimeInterface && $rItem->updated >= $after->getTimestamp()) {
                    $this->logger->debug(
                        message: "Ignoring '{identity.user}@{identity.backend}' - '{item.title}'. Backend date is equal or newer than last sync date.",
                        context: [
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
                $this->logger->info(
                    message: "Ignoring '{identity.user}@{identity.backend}' - '{item.title}'. {item.type} Is not imported yet. Possibly because the backend was imported as metadata only.",
                    context: $logContext,
                );
                Message::increment("{$context->backendName}.{$mappedType}.ignored_not_found_in_db");
                return;
            }

            if ($rItem->watched === $entity->watched) {
                if (true === (bool) ag($context->options, Options::DEBUG_TRACE)) {
                    $this->logger->debug(
                        message: "Ignoring '{identity.user}@{identity.backend}' - '{item.title}'. {item.type} play state is identical.",
                        context: [
                            ...$logContext,
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
                    message: "Ignoring '{identity.user}@{identity.backend}' - '{item.title}'. Backend date is equal or newer than database date.",
                    context: [
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

            $logContext['item']['url'] = $url;

            Message::increment("{$context->userContext->name}.{$context->backendName}.export");

            $playState = $entity->isWatched() ? 'Played' : 'Unplayed';
            $requestContext = $logContext + ['play_state' => $playState];

            if (true === (bool) ag($context->options, Options::DRY_RUN, false)) {
                $this->logger->notice(
                    message: "Queuing request to change '{identity.user}@{identity.backend}' {item.type} '{item.title}' play state to '{play_state}'.",
                    context: $requestContext,
                );
                return;
            }

            $queue->add(
                new Request(
                    method: $entity->isWatched() ? Method::POST : Method::DELETE,
                    url: $url,
                    options: $context->getHttpOptions(),
                    success: function (iResponse $response) use ($context, $entity, $item, $lastPlayed, $requestContext): array {
                        $statusCode = $response->getStatusCode();

                        if (Status::OK !== Status::tryFrom($statusCode)) {
                            $this->logger->error(
                                message: "Request to change '{identity.user}@{identity.backend}' {item.type} '{item.title}' play state returned with unexpected '{response.status_code}' status code.",
                                context: [
                                    ...$requestContext,
                                    'response' => ['status_code' => $statusCode],
                                ],
                            );

                            return [];
                        }

                        $this->logger->notice(
                            message: "Updated '{identity.user}@{identity.backend}' {item.type} '{item.title}' play state to '{play_state}'.",
                            context: $requestContext,
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
                                message: "Failed during '{identity.user}@{identity.backend}' request to change play state of {item.type} '{item.title}'. {exception.message}",
                                context: [
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
            $this->logger->error(
                ...lw(
                    message: "Failed during '{identity.user}@{identity.backend}' export. {exception.message}",
                    context: [
                        ...$logContext,
                        ...exception_log($e),
                    ],
                    e: $e,
                ),
            );
        }
    }
}
