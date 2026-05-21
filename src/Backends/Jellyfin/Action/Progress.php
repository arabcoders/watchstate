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
            $forceChange = true === (bool) ag($context->options, Options::FORCE_REPLACE_METADATA, false);
            $hasProgress = ag_exists($context->options, Options::STATE_PROGRESS_VALUE);
            $progressValue = true === $hasProgress
                ? (int) ag($context->options, Options::STATE_PROGRESS_VALUE, 0)
                : $entity->getPlayProgress();

            $logContext = [
                'action' => $this->action,
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'user' => $context->userContext->name,
                'item' => [
                    'id' => $entity->id,
                    'type' => $entity->type,
                    'title' => $entity->getName(),
                    'progress' => format_duration($progressValue),
                ],
            ];

            if ($context->backendName === $entity->via) {
                $this->logger->info(
                    message: "Ignoring {item.type} '#{item.id}: {item.title}' for '{user}@{backend}': event originated from this backend.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.progress',
                        'operation' => 'prepare_progress_update',
                        'outcome' => 'ignored',
                        'reason' => 'origin_backend',
                        ...$logContext,
                    ],
                );
                continue;
            }

            if (null === ag($metadata, iState::COLUMN_ID, null)) {
                $this->logger->warning(
                    message: "Ignoring {item.type} '#{item.id}: {item.title}' for '{user}@{backend}': backend metadata is missing.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.progress',
                        'operation' => 'load_remote_state',
                        'outcome' => 'ignored',
                        'reason' => 'missing_backend_metadata',
                        ...$logContext,
                    ],
                );
                continue;
            }

            $senderDate = ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_DATE);
            if (null === $senderDate) {
                $this->logger->warning(
                    message: "Ignoring {item.type} '#{item.id}: {item.title}' for '{user}@{backend}': sender event date is missing.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.progress',
                        'operation' => 'prepare_progress_update',
                        'outcome' => 'ignored',
                        'reason' => 'missing_event_date',
                        ...$logContext,
                    ],
                );
                continue;
            }
            $senderDate = make_date($senderDate)->getTimestamp();
            $senderDate -= (int) ag($context->options, 'progress.time_drift', self::DEFAULT_TIME_DRIFT);

            $datetime = ag($entity->getExtra($context->backendName), iState::COLUMN_EXTRA_DATE, null);
            if (false === $ignoreDate && null !== $datetime && make_date($datetime)->getTimestamp() > $senderDate) {
                $this->logger->warning(
                    message: "Ignoring {item.type} '#{item.id}: {item.title}' for '{user}@{backend}': event date is not newer than local backend history.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.progress',
                        'operation' => 'compare_dates',
                        'outcome' => 'ignored',
                        'reason' => 'date_not_newer_than_local_history',
                        ...$logContext,
                        'comparison' => [
                            'local' => make_date($datetime),
                            'sender' => make_date($senderDate),
                        ],
                    ],
                );
                continue;
            }

            $logContext['remote']['id'] = ag($metadata, iState::COLUMN_ID);

            if (array_key_exists($logContext['remote']['id'], $sessions)) {
                $this->logger->info(
                    message: "Ignoring {item.type} '#{item.id}: {item.title}' for '{user}@{backend}': item is active in another session.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.progress',
                        'operation' => 'prepare_progress_update',
                        'outcome' => 'ignored',
                        'reason' => 'active_session',
                        ...$logContext,
                    ],
                );
                continue;
            }

            try {
                $remoteData = $this->getItemDetails($context, $logContext['remote']['id'], [Options::NO_CACHE => true]);
                $remoteItem = $this->createEntity($context, $guid, $remoteData, ['latest_date' => true]);

                if (false === $ignoreDate && make_date($remoteItem->updated)->getTimestamp() > $senderDate) {
                    $this->logger->info(
                        message: "Ignoring {item.type} '#{item.id}: {item.title}' for '{user}@{backend}': event date is not newer than backend state.",
                        context: [
                            'event_name' => 'backend.item.ignored',
                            'subsystem' => 'backend.progress',
                            'operation' => 'compare_dates',
                            'outcome' => 'ignored',
                            'reason' => 'date_not_newer_than_remote_state',
                            ...$logContext,
                            'comparison' => [
                                'remote' => make_date($remoteItem->updated),
                                'sender' => make_date($senderDate),
                            ],
                        ],
                    );
                    continue;
                }

                if ($remoteItem->isWatched()) {
                    $allowUpdate = (int) Config::get('progress.threshold', 0);
                    $minThreshold = (int) Config::get('progress.minThreshold', 86_400);
                    if (false === ($allowUpdate >= $minThreshold && time() > ($entity->updated + $allowUpdate))) {
                        $this->logger->info(
                            message: "Ignoring {item.type} '#{item.id}: {item.title}' for '{user}@{backend}': backend item is already watched.",
                            context: [
                                'event_name' => 'backend.item.ignored',
                                'subsystem' => 'backend.progress',
                                'operation' => 'update_progress',
                                'outcome' => 'ignored',
                                'reason' => 'remote_marked_watched',
                                ...$logContext,
                            ],
                        );
                        continue;
                    }
                }
            } catch (\App\Libs\Exceptions\RuntimeException|RuntimeException|InvalidArgumentException $e) {
                $this->logger->error(
                    ...lw(
                        message: "Failed to load backend state for {item.type} '#{item.id}: {item.title}' from '{user}@{backend}'.",
                        context: [
                            'event_name' => 'backend.operation.failed',
                            'subsystem' => 'backend.progress',
                            'operation' => 'load_remote_state',
                            'outcome' => 'failed',
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

                $logContext['remote']['url'] = (string) $url;

                $this->logger->debug(
                    message: "Updating watch progress for {item.type} '#{item.id}: {item.title}' on '{user}@{backend}'.",
                    context: [
                        'event_name' => 'backend.request.started',
                        'subsystem' => 'backend.progress',
                        'operation' => 'update_progress',
                        'outcome' => 'started',
                        ...$logContext,
                        'progress' => format_duration($progressValue),
                        // -- convert secs to ms for jellyfin/emby to understand it.
                        'time' => floor($progressValue * 1_00_00),
                    ],
                );

                if (false === (bool) ag($context->options, Options::DRY_RUN, false)) {
                    $requestContext = [
                        ...$logContext,
                        'progress' => format_duration($progressValue),
                    ];

                    $json = [
                        'PlaybackPositionTicks' => (string) floor($progressValue * 1_00_00),
                    ];

                    if (false === ($forceChange && true === $hasProgress && $progressValue < 1)) {
                        $json['LastPlayedDate'] = make_date($senderDate)->format(Date::ATOM);
                    }

                    $queue->add(
                        new Request(
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
                                        message: "Watch-progress update for {item.type} '#{item.id}: {item.title}' on '{user}@{backend}' returned status {status_code}.",
                                        context: [
                                            'event_name' => 'backend.response.failed',
                                            'subsystem' => 'backend.progress',
                                            'operation' => 'update_progress',
                                            'outcome' => 'failed',
                                            ...$requestContext,
                                            'status_code' => $statusCode,
                                        ],
                                    );

                                    return [];
                                }

                                $this->logger->notice(
                                    message: "Updated watch progress for '#{item.id}: {item.title}' on '{user}@{backend}'.",
                                    context: [
                                        'event_name' => 'backend.state_update.completed',
                                        'subsystem' => 'backend.progress',
                                        'operation' => 'update_progress',
                                        'outcome' => 'completed',
                                        ...$requestContext,
                                        'status_code' => $statusCode,
                                    ],
                                );

                                return [];
                            },
                            error: function (Throwable $e) use ($requestContext): array {
                                $this->logger->error(
                                    ...lw(
                                        message: "Watch-progress request failed for {item.type} '#{item.id}: {item.title}' on '{user}@{backend}'.",
                                        context: [
                                            'event_name' => 'backend.client.request_failed',
                                            'subsystem' => 'backend.progress',
                                            'operation' => 'update_progress',
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
                                HttpClientInterface::class => $this->http,
                            ],
                        ),
                    );
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "Failed to prepare watch-progress update for {item.type} '#{item.id}: {item.title}' on '{user}@{backend}'.",
                        context: [
                            'event_name' => 'backend.operation.failed',
                            'subsystem' => 'backend.progress',
                            'operation' => 'prepare_progress_update',
                            'outcome' => 'failed',
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
