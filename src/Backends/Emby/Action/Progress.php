<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Response;
use App\Backends\Emby\EmbyActionTrait;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Method;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Extends\Date;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
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
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'user' => $context->userContext->name,
                'item' => [
                    'id' => $entity->id,
                    'type' => $entity->type,
                    'title' => $entity->getName(),
                    'progress' => format_duration($entity->getPlayProgress()),
                ],
            ];

            if ($context->backendName === $entity->via) {
                $this->logger->info(
                    message: "{action}: Not processing '#{item.id}: {item.title}' for '{client}: {user}@{backend}'. Event originated from this backend.",
                    context: $logContext,
                );
                continue;
            }

            if (null === ag($metadata, iState::COLUMN_ID, null)) {
                $this->logger->warning(
                    message: "{action}: Not processing '#{item.id}: {item.title}' for '{client}: {user}@{backend}'. No metadata was found.",
                    context: $logContext,
                );
                continue;
            }

            $senderDate = ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_DATE);
            if (null === $senderDate) {
                $this->logger->warning(
                    message: "{action}: Not processing '#{item.id}: {item.title}' for '{client}: {user}@{backend}'. The event originator did not set a date.",
                    context: $logContext,
                );
                continue;
            }
            $senderDate = make_date($senderDate)->getTimestamp();
            $senderDate -= (int) ag($context->options, 'progress.time_drift', self::DEFAULT_TIME_DRIFT);

            $datetime = ag($entity->getExtra($context->backendName), iState::COLUMN_EXTRA_DATE, null);
            if (false === $ignoreDate && null !== $datetime && make_date($datetime)->getTimestamp() > $senderDate) {
                $this->logger->warning(
                    message: "{action}: Not processing '#{item.id}: {item.title}' for '{client}: {user}@{backend}'. Event date '{event_date}' is older than backend local db item date '{local_date}'.",
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
                    message: "{action}: Not processing '#{item.id}: {item.title}' for '{client}: {user}@{backend}'. The item is playing right now.",
                    context: $logContext,
                );
                continue;
            }

            try {
                $remoteItem = $this->createEntity(
                    $context,
                    $guid,
                    $this->getItemDetails($context, $logContext['remote']['id'], [Options::NO_CACHE => true]),
                    ['latest_date' => true],
                );

                if (false === $ignoreDate && make_date($remoteItem->updated)->getTimestamp() > $senderDate) {
                    $this->logger->info(
                        message: "{action}: Not processing '#{item.id}: {item.title}' for '{client}: {user}@{backend}'. Event date '{event_date}' is older than backend remote item date '{remote_date}'.",
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
                    $minThreshold = (int) Config::get('progress.minThreshold', 86_400);
                    $allowUpdate = (int) Config::get('progress.threshold', 0);
                    if (false === ($allowUpdate >= $minThreshold && time() > ($entity->updated + $allowUpdate))) {
                        $this->logger->info(
                            message: "{action}: Not processing '#{item.id}: {item.title}' for '{client}: {user}@{backend}'. The backend says the item is marked as watched.",
                            context: $logContext,
                        );
                        continue;
                    }
                }
            } catch (\App\Libs\Exceptions\RuntimeException|RuntimeException|InvalidArgumentException $e) {
                $this->logger->error(
                    ...lw(
                        message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' get {item.type} '#{item.id}: {item.title}' status. '{error.message}' at '{error.file}:{error.line}'.",
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
                    message: "{action}: Updating '{client}: {user}@{backend}' {item.type} '{item.title}' watch progress to '{progress}'.",
                    context: [
                        ...$logContext,
                        'progress' => format_duration($entity->getPlayProgress()),
                        // -- convert secs to ms for emby to understand it.
                        'time' => floor($entity->getPlayProgress() * 1_00_00),
                    ],
                );

                if (false === (bool) ag($context->options, Options::DRY_RUN, false)) {
                    $queue->add(
                        $this->http->request(
                            method: Method::POST,
                            url: (string) $url,
                            options: array_replace_recursive($context->getHttpOptions(), [
                                'headers' => [
                                    'Content-Type' => 'application/json',
                                ],
                                'json' => [
                                    'PlaybackPositionTicks' => (string) floor($entity->getPlayProgress() * 1_00_00),
                                    'LastPlayedDate' => make_date($senderDate)->format(Date::ATOM),
                                ],
                                'user_data' => [
                                    'id' => $key,
                                    'context' => $logContext,
                                ],
                            ]),
                        ),
                    );
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' change {item.type} '{item.title}' watch progress. '{error.message}' at '{error.file}:{error.line}'.",
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
                        e: $e,
                    ),
                );
            }
        }

        return new Response(status: true, response: $queue);
    }
}
