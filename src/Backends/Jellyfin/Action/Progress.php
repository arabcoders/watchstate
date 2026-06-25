<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Request;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Extends\Date;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Class Progress
 *
 * This class is responsible for pushing play progress back to jellyfin API.
 */
class Progress
{
    use CommonTrait;
    use JellyfinActionTrait;

    /**
     * @var int Default time drift in seconds.
     */
    private const int DEFAULT_TIME_DRIFT = 30;

    /**
     * @var string Action name.
     */
    protected string $action = 'jellyfin.progress';

    /**
     * Class constructor.
     *
     * @param HttpClientInterface $http The HTTP client.
     * @param LoggerInterface $logger The logger.
     *
     * @return void
     */
    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Wrap the operation in try response block.
     *
     * @param Context $context
     * @param iGuid $guid
     * @param array<iState> $entities
     * @param QueueRequests $queue
     * @param DateTimeInterface|null $after
     * @return Response
     */
    public function __invoke(
        Context $context,
        iGuid $guid,
        array $entities,
        QueueRequests $queue,
        ?DateTimeInterface $after = null,
    ): Response {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->action($context, $guid, $entities, $queue, $after),
            action: $this->action,
        );
    }

    /**
     * Push play progress to the backend.
     *
     * @param Context $context Backend context.
     * @param iGuid $guid GUID Parser.
     * @param array $entities An array of entities.
     * @param QueueRequests $queue The queue object.
     * @param DateTimeInterface|null $after (Optional) The date after which to perform the action.
     *
     * @return Response The response.
     */
    private function action(
        Context $context,
        iGuid $guid,
        array $entities,
        QueueRequests $queue,
        ?DateTimeInterface $after = null,
    ): Response {
        $sessions = [];
        $ignoreDate = (bool) ag($context->options, Options::IGNORE_DATE, false);
        $replayProgress = (bool) ag($context->options, Options::REPLAY_PROGRESS, false);

        try {
            $remoteSessions = Container::get(GetSessions::class)($context);
            if (true === $remoteSessions->status) {
                foreach (ag($remoteSessions->response, 'sessions', []) as $session) {
                    $user_id = ag($session, 'user_id', null);

                    $uid = $user_id && $context->backendUser === $user_id;

                    if (true !== $uid) {
                        continue;
                    }

                    $sessions[ag($session, 'item_id')] = ag($session, 'item_offset_at', 0);
                }
            }
        } catch (Throwable) {
            // simply ignore this error as it's not important enough to interrupt the whole process.
        }

        foreach ($entities as $key => $entity) {
            if (true !== $entity instanceof iState) {
                continue;
            }

            if (null !== $after && false === (bool) ag($context->options, Options::IGNORE_DATE, false)) {
                if ($after->getTimestamp() > $entity->updated) {
                    continue;
                }
            }

            $metadata = $entity->getMetadata($context->backendName);

            $logContext = [
                'action' => $this->action,
                'identity' => [
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'user' => $context->userContext->name,
                ],
                'history' => [
                    'id' => $entity->id,
                    'type' => $entity->type,
                    'title' => $entity->getName(),
                    'progress' => format_duration($entity->getPlayProgress()),
                ],
            ];

            if ($context->backendName === $entity->via && false === $replayProgress) {
                $this->logger->info(
                    message: "Not processing '#{history.id}: {history.title}' for '{identity.user}@{identity.backend}'. Event originated from this backend.",
                    context: [
                        ...$logContext,
                        'operation' => 'progress.skip',
                        'error' => 'event_from_this_backend',
                    ],
                );
                continue;
            }

            if (null === ag($metadata, iState::COLUMN_ID, null)) {
                $this->logger->warning(
                    message: "Not processing '#{history.id}: {history.title}' for '{identity.user}@{identity.backend}'. No metadata was found.",
                    context: [
                        ...$logContext,
                        'operation' => 'progress.skip',
                        'error' => 'no_metadata',
                    ],
                );
                continue;
            }

            $senderDate = ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_DATE);
            if (null === $senderDate) {
                $this->logger->warning(
                    message: "Not processing '#{history.id}: {history.title}' for '{identity.user}@{identity.backend}'. The event originator did not set a date.",
                    context: [
                        ...$logContext,
                        'operation' => 'progress.skip',
                        'error' => 'no_event_date',
                    ],
                );
                continue;
            }
            $senderDate = make_date($senderDate)->getTimestamp();
            $senderDate -= (int) ag($context->options, 'progress.time_drift', self::DEFAULT_TIME_DRIFT);

            $datetime = ag($entity->getExtra($context->backendName), iState::COLUMN_EXTRA_DATE, null);
            if (false === $ignoreDate && null !== $datetime && make_date($datetime)->getTimestamp() > $senderDate) {
                $this->logger->warning(
                    message: "Not processing '#{history.id}: {history.title}' for '{identity.user}@{identity.backend}'. Event date '{comparison.event_date}' is older than local database date '{comparison.local_date}'.",
                    context: [
                        ...$logContext,
                        'operation' => 'progress.skip',
                        'error' => 'stale_event_date',
                        'comparison' => [
                            'event_date' => make_date($senderDate),
                            'local_date' => make_date($datetime),
                            'delta_seconds' => make_date($datetime)->getTimestamp() - $senderDate,
                        ],
                    ],
                );
                continue;
            }

            $logContext['remote']['id'] = ag($metadata, iState::COLUMN_ID);

            if (array_key_exists($logContext['remote']['id'], $sessions)) {
                $this->logger->notice(
                    message: "Not processing '#{history.id}: {history.title}' for '{identity.user}@{identity.backend}'. The item is playing right now.",
                    context: [
                        ...$logContext,
                        'operation' => 'progress.skip',
                        'error' => 'currently_playing',
                    ],
                );
                continue;
            }

            $unwatchFirst = false;

            try {
                $remoteData = $this->getItemDetails($context, $logContext['remote']['id'], [Options::NO_CACHE => true]);
                $remoteItem = $this->createEntity($context, $guid, $remoteData, ['latest_date' => true]);

                if (false === $ignoreDate && make_date($remoteItem->updated)->getTimestamp() > $senderDate) {
                    $this->logger->info(
                        message: "Not processing '#{history.id}: {history.title}' for '{identity.user}@{identity.backend}'. Event date '{comparison.event_date}' is older than backend date '{comparison.remote_date}'.",
                        context: [
                            ...$logContext,
                            'operation' => 'progress.skip',
                            'error' => 'stale_event_date',
                            'comparison' => [
                                'event_date' => make_date($senderDate),
                                'remote_date' => make_date($remoteItem->updated),
                                'delta_seconds' => make_date($remoteItem->updated)->getTimestamp() - $senderDate,
                            ],
                        ],
                    );
                    continue;
                }

                if ($remoteItem->isWatched()) {
                    if (true === $replayProgress) {
                        $unwatchFirst = true;
                    } else {
                        $allowUpdate = (int) Config::get('progress.threshold', 0);
                        $minThreshold = (int) Config::get('progress.minThreshold', 86_400);
                        if (false === ($allowUpdate >= $minThreshold && time() > ($entity->updated + $allowUpdate))) {
                            $this->logger->info(
                                message: "Not processing '#{history.id}: {history.title}' for '{identity.user}@{identity.backend}'. The backend says the item is marked as watched.",
                                context: [
                                    ...$logContext,
                                    'operation' => 'progress.skip',
                                    'error' => 'backend_marked_watched',
                                ],
                            );
                            continue;
                        }
                    }
                }
            } catch (\App\Libs\Exceptions\RuntimeException|RuntimeException|InvalidArgumentException $e) {
                $this->logger->error(
                    ...lw(
                        message: "Failed to get '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' status. {exception.message}",
                        context: [
                            ...$logContext,
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );
                continue;
            }

            try {
                $url = $context->backendUrl->withPath(
                    r('/Users/{user_id}/Items/{item_id}/UserData', [
                        'user_id' => $context->backendUser,
                        'item_id' => $logContext['remote']['id'],
                    ]),
                );

                $logContext['request']['url'] = (string) $url;

                $this->logger->debug(
                    message: "Updating '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' watch progress to '{progress}'.",
                    context: [
                        ...$logContext,
                        'progress' => $entity->hasPlayProgress()
                            ? format_duration(
                                $entity->getPlayProgress(),
                            )
                            : '0:0:0',
                        // -- convert secs to ms for jellyfin/emby to understand it.
                        'time' => floor($entity->getPlayProgress() * 1_00_00),
                    ],
                );

                if (false === (bool) ag($context->options, Options::DRY_RUN, false)) {
                    $requestContext = [
                        ...$logContext,
                        'progress' => format_duration($entity->getPlayProgress()),
                    ];

                    $json = [
                        'PlaybackPositionTicks' => (string) floor($entity->getPlayProgress() * 1_00_00),
                        'LastPlayedDate' => make_date($senderDate)->format(Date::ATOM),
                    ];

                    if (true === $unwatchFirst) {
                        $json['Played'] = false;
                    }

                    $progressRequest = new Request(
                        method: Method::POST,
                        url: $url,
                        options: array_replace_recursive($context->getHttpOptions(), [
                            'headers' => [
                                'Content-Type' => 'application/json',
                            ],
                            'json' => $json,
                        ]),
                        success: function (ResponseInterface $response) use ($requestContext): array {
                            $statusCode = $response->getStatusCode();

                            if (false === in_array(Status::tryFrom($statusCode), [Status::OK, Status::NO_CONTENT], true)) {
                                $this->logger->error(
                                    message: "Watch progress update for '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' returned HTTP {response.status_code}.",
                                    context: [
                                        ...$requestContext,
                                        'response' => ['status_code' => $statusCode],
                                    ],
                                );

                                return [];
                            }

                            $this->logger->notice(
                                message: "Updated '{identity.user}@{identity.backend}' '#{history.id}: {history.title}' watch progress to '{progress}'.",
                                context: [
                                    ...$requestContext,
                                    'response' => ['status_code' => $statusCode],
                                ],
                            );

                            return [];
                        },
                        error: function (Throwable $e) use ($requestContext): array {
                            $this->logger->error(
                                ...lw(
                                    message: "Failed to update watch progress for '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}'. {exception.message}",
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
                            HttpClientInterface::class => $this->http,
                        ],
                    );

                    if (true === $unwatchFirst) {
                        $unwatchContext = $requestContext;
                        $unwatchContext['request']['url'] = (string) $context->backendUrl->withPath(
                            r('/Users/{user_id}/PlayedItems/{item_id}', [
                                'user_id' => $context->backendUser,
                                'item_id' => $logContext['remote']['id'],
                            ]),
                        );

                        $queue->add(
                            new Request(
                                method: Method::DELETE,
                                url: $unwatchContext['request']['url'],
                                options: array_replace_recursive($context->getHttpOptions(), [
                                    'user_data' => [Options::NO_LOGGING => true],
                                ]),
                                success: function (ResponseInterface $response) use ($progressRequest, $unwatchContext): array {
                                    $statusCode = $response->getStatusCode();
                                    if (false === in_array(Status::tryFrom($statusCode), [Status::OK, Status::NO_CONTENT], true)) {
                                        $this->logger->error(
                                            message: "Mark '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' as unplayed before progress update returned HTTP {response.status_code}.",
                                            context: [
                                                ...$unwatchContext,
                                                'error' => 'unexpected_status',
                                                'response' => ['status_code' => $statusCode],
                                            ],
                                        );

                                        return [];
                                    }

                                    return [$progressRequest];
                                },
                                error: function (Throwable $e) use ($unwatchContext): array {
                                    $this->logger->error(
                                        ...lw(
                                            message: "Failed to mark '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' as unplayed before progress update. {exception.message}",
                                            context: [
                                                ...$unwatchContext,
                                                'error' => 'request_exception',
                                                ...exception_log($e),
                                            ],
                                            e: $e,
                                        ),
                                    );

                                    return [];
                                },
                                extras: [HttpClientInterface::class => $this->http],
                            ),
                        );

                        continue;
                    }

                    $queue->add(
                        $progressRequest,
                    );
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "Failed to update watch progress for '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}'. {exception.message}",
                        context: [
                            ...$logContext,
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );
            }
        }

        return new Response(status: true, response: $queue);
    }
}
