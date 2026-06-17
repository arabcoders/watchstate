<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Request;
use App\Backends\Common\Response;
use App\Backends\Emby\EmbyActionTrait;
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

class Progress
{
    use CommonTrait;
    use EmbyActionTrait;

    /**
     * @var int Default time drift in seconds.
     */
    private const int DEFAULT_TIME_DRIFT = 30;

    protected string $action = 'emby.progress';

    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Push play progress.
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
                'item' => [
                    'id' => $entity->id,
                    'type' => $entity->type,
                    'title' => $entity->getName(),
                    'progress' => format_duration($entity->getPlayProgress()),
                ],
            ];

            if ($context->backendName === $entity->via && false === $replayProgress) {
                $this->logger->info(
                    message: "{action}: Not processing '#{item.id}: {item.title}' for '{identity.client}: {identity.user}@{identity.backend}'. Event originated from this backend.",
                    context: $logContext,
                );
                continue;
            }

            if (null === ag($metadata, iState::COLUMN_ID, null)) {
                $this->logger->warning(
                    message: "{action}: Not processing '#{item.id}: {item.title}' for '{identity.client}: {identity.user}@{identity.backend}'. No metadata was found.",
                    context: $logContext,
                );
                continue;
            }

            $senderDate = ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_DATE);
            if (null === $senderDate) {
                $this->logger->warning(
                    message: "{action}: Not processing '#{item.id}: {item.title}' for '{identity.client}: {identity.user}@{identity.backend}'. The event originator did not set a date.",
                    context: $logContext,
                );
                continue;
            }
            $senderDate = make_date($senderDate)->getTimestamp();
            $senderDate -= (int) ag($context->options, 'progress.time_drift', self::DEFAULT_TIME_DRIFT);

            $datetime = ag($entity->getExtra($context->backendName), iState::COLUMN_EXTRA_DATE, null);
            if (false === $ignoreDate && null !== $datetime && make_date($datetime)->getTimestamp() > $senderDate) {
                $this->logger->warning(
                    message: "{action}: Not processing '#{item.id}: {item.title}' for '{identity.client}: {identity.user}@{identity.backend}'. Event date '{event_date}' is older than backend local db item date '{local_date}'.",
                    context: [
                        ...$logContext,
                        'event_date' => make_date($senderDate),
                        'local_date' => make_date($datetime),
                        'compare' => ['remote' => make_date($datetime), 'sender' => make_date($senderDate)],
                    ],
                );
                continue;
            }

            $logContext['remote']['id'] = ag($metadata, iState::COLUMN_ID);

            if (array_key_exists($logContext['remote']['id'], $sessions)) {
                $this->logger->notice(
                    message: "{action}: Not processing '#{item.id}: {item.title}' for '{identity.client}: {identity.user}@{identity.backend}'. The item is playing right now.",
                    context: $logContext,
                );
                continue;
            }

            $unwatchFirst = false;

            try {
                $remoteItem = $this->createEntity(
                    $context,
                    $guid,
                    $this->getItemDetails($context, $logContext['remote']['id'], [Options::NO_CACHE => true]),
                    ['latest_date' => true],
                );

                if (false === $ignoreDate && make_date($remoteItem->updated)->getTimestamp() > $senderDate) {
                    $this->logger->info(
                        message: "{action}: Not processing '#{item.id}: {item.title}' for '{identity.client}: {identity.user}@{identity.backend}'. Event date '{event_date}' is older than backend remote item date '{remote_date}'.",
                        context: [
                            ...$logContext,
                            'event_date' => make_date($senderDate),
                            'remote_date' => make_date($remoteItem->updated),
                            'compare' => [
                                'remote' => make_date($remoteItem->updated),
                                'sender' => make_date($senderDate),
                            ],
                        ],
                    );
                    continue;
                }

                if ($remoteItem->isWatched()) {
                    if (true === $replayProgress) {
                        $unwatchFirst = true;
                    } else {
                        $minThreshold = (int) Config::get('progress.minThreshold', 86_400);
                        $allowUpdate = (int) Config::get('progress.threshold', 0);
                        if (false === ($allowUpdate >= $minThreshold && time() > ($entity->updated + $allowUpdate))) {
                            $this->logger->info(
                                message: "{action}: Not processing '#{item.id}: {item.title}' for '{identity.client}: {identity.user}@{identity.backend}'. The backend says the item is marked as watched.",
                                context: $logContext,
                            );
                            continue;
                        }
                    }
                }
            } catch (\App\Libs\Exceptions\RuntimeException|RuntimeException|InvalidArgumentException $e) {
                $this->logger->error(
                    ...lw(
                        message: "{action}: Exception '{exception.type}' was thrown unhandled during '{identity.client}: {identity.user}@{identity.backend}' get {item.type} '#{item.id}: {item.title}' status. '{exception.message}' at '{exception.file}:{exception.line}'.",
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

                $logContext['remote']['url'] = (string) $url;

                $this->logger->debug(
                    message: "{action}: Updating '{identity.client}: {identity.user}@{identity.backend}' {item.type} '{item.title}' watch progress to '{progress}'.",
                    context: [
                        ...$logContext,
                        'progress' => format_duration($entity->getPlayProgress()),
                        // -- convert secs to ms for emby to understand it.
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
                                    message: "{action}: Request to change '{identity.client}: {identity.user}@{identity.backend}' {item.type} '{item.title}' watch progress returned with unexpected '{status_code}' status code.",
                                    context: [
                                        ...$requestContext,
                                        'status_code' => $statusCode,
                                    ],
                                );

                                return [];
                            }

                            $this->logger->notice(
                                message: "{action}: Updated '{identity.client}: {identity.user}@{identity.backend}' '{item.title}' watch progress to '{progress}'.",
                                context: [
                                    ...$requestContext,
                                    'status_code' => $statusCode,
                                ],
                            );

                            return [];
                        },
                        error: function (Throwable $e) use ($requestContext): array {
                            $this->logger->error(
                                ...lw(
                                    message: "{action}: Exception '{exception.type}' was thrown unhandled during '{identity.client}: {identity.user}@{identity.backend}' request to change watch progress of {item.type} '{item.title}'. '{exception.message}' at '{exception.file}:{exception.line}'.",
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
                        $unwatchContext['remote']['url'] = (string) $context->backendUrl->withPath(
                            r('/Users/{user_id}/PlayedItems/{item_id}', [
                                'user_id' => $context->backendUser,
                                'item_id' => $logContext['remote']['id'],
                            ]),
                        );

                        $queue->add(
                            new Request(
                                method: Method::DELETE,
                                url: $unwatchContext['remote']['url'],
                                options: array_replace_recursive($context->getHttpOptions(), [
                                    'user_data' => [Options::NO_LOGGING => true],
                                ]),
                                success: function (ResponseInterface $response) use ($progressRequest, $unwatchContext): array {
                                    $statusCode = $response->getStatusCode();
                                    if (false === in_array(Status::tryFrom($statusCode), [Status::OK, Status::NO_CONTENT], true)) {
                                        $this->logger->error(
                                            message: "{action}: Request to mark '{identity.client}: {identity.user}@{identity.backend}' {item.type} '{item.title}' as unplayed before progress update returned with unexpected '{status_code}' status code.",
                                            context: [
                                                ...$unwatchContext,
                                                'error' => 'unexpected_status',
                                                'status_code' => $statusCode,
                                            ],
                                        );

                                        return [];
                                    }

                                    return [$progressRequest];
                                },
                                error: function (Throwable $e) use ($unwatchContext): array {
                                    $this->logger->error(
                                        ...lw(
                                            message: "{action}: Exception was thrown during '{identity.client}: {identity.user}@{identity.backend}' unplayed request before progress update.",
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
                        message: "{action}: Exception '{exception.type}' was thrown unhandled during '{identity.client}: {identity.user}@{identity.backend}' change {item.type} '{item.title}' watch progress. '{exception.message}' at '{exception.file}:{exception.line}'.",
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
