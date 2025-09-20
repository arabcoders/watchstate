<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexActionTrait;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Method;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class Progress
{
    use CommonTrait;
    use PlexActionTrait;

    /**
     * @var int Default time drift in seconds.
     */
    private const int DEFAULT_TIME_DRIFT = 30;

    private string $action = 'plex.progress';

    public function __construct(protected HttpClientInterface $http, protected LoggerInterface $logger)
    {
    }

    /**
     * Push Play state.
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
        DateTimeInterface|null $after = null
    ): Response {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->action($context, $guid, $entities, $queue, $after),
            action: $this->action
        );
    }

    private function action(
        Context $context,
        iGuid $guid,
        array $entities,
        QueueRequests $queue,
        DateTimeInterface|null $after = null
    ): Response {
        $sessions = [];
        $ignoreDate = (bool)ag($context->options, Options::IGNORE_DATE, false);

        /**
         * as plex act weird if we change the progress of a watched item while the item is playing,
         * we need to check if the item is watched before changing its progress.
         */
        try {
            $remoteSessions = Container::get(GetSessions::class)($context);
            if (true === $remoteSessions->status) {
                foreach (ag($remoteSessions->response, 'sessions', []) as $session) {
                    $user_id = ag($session, 'user_id', null);
                    $user_uuid = ag($session, 'user_uuid', null);

                    /**
                     * Plex is back at it again reporting admin user id as 1.
                     * So, we have to resort to use the user uuid to identify the user.
                     */
                    $uid = $user_id && $context->backendUser === $user_id;
                    $uuid = $user_uuid && ag($context->options, 'plex_user_uuid', 'non_set') === $user_uuid;

                    if (true !== ($uid || $uuid)) {
                        continue;
                    }

                    $sessions[ag($session, 'item_id')] = ag($session, 'item_offset_at', 0);
                }
            }
        } catch (Throwable) {
            // simply ignore this error as it's not important enough to interrupt the whole process.
        }

        foreach ($entities as $key => $entity) {
            if (true !== ($entity instanceof iState)) {
                continue;
            }

            if (null !== $after && false === (bool)ag($context->options, Options::IGNORE_DATE, false)) {
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
            $senderDate = makeDate($senderDate)->getTimestamp();
            $senderDate = $senderDate - (int)ag($context->options, 'progress.time_drift', self::DEFAULT_TIME_DRIFT);

            $datetime = ag($entity->getExtra($context->backendName), iState::COLUMN_EXTRA_DATE, null);

            if (false === $ignoreDate && null !== $datetime && makeDate($datetime)->getTimestamp() > $senderDate) {
                $this->logger->warning(
                    message: "{action}: Not processing '#{item.id}: {item.title}' for '{client}: {user}@{backend}'. Event date '{event_date}' is older than backend local db item date '{local_date}'.",
                    context: [
                        ...$logContext,
                        'event_date' => makeDate($senderDate),
                        'local_date' => makeDate($datetime),
                        'compare' => ['remote' => makeDate($datetime), 'sender' => makeDate($senderDate)],
                    ]
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
                $remoteData = ag(
                    $this->getItemDetails($context, $logContext['remote']['id'], [Options::NO_CACHE => true]),
                    'MediaContainer.Metadata.0',
                    []
                );

                $remoteItem = $this->createEntity($context, $guid, $remoteData, ['latest_date' => true,]);

                if (false === $ignoreDate && makeDate($remoteItem->updated)->getTimestamp() > $senderDate) {
                    $this->logger->info(
                        message: "{action}: Not processing '#{item.id}: {item.title}' for '{client}: {user}@{backend}'. Event date '{event_date}' is older than backend remote item date '{remote_date}'.",
                        context: [
                            ...$logContext,
                            'event_date' => makeDate($senderDate),
                            'remote_date' => makeDate($remoteItem->updated),
                            'compare' => [
                                'remote' => makeDate($remoteItem->updated),
                                'sender' => makeDate($senderDate)
                            ],
                        ]
                    );
                    continue;
                }

                if ($remoteItem->isWatched()) {
                    $allowUpdate = (int)Config::get('progress.threshold', 0);
                    $minThreshold = (int)Config::get('progress.minThreshold', 86_400);
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
                continue;
            }

            try {
                // -- it seems /:/timeline/ allow us to update external user progress, while /:/progress/ does not.
                $url = $context->backendUrl->withPath('/:/timeline/')->withQuery(http_build_query([
                    'ratingKey' => $logContext['remote']['id'],
                    'key' => '/library/metadata/' . $logContext['remote']['id'],
                    'identifier' => 'com.plexapp.plugins.library',
                    'state' => 'stopped',
                    'time' => $entity->getPlayProgress(),
                    // -- Without duration & client identifier plex ignore watch progress update.
                    'duration' => ag($remoteData, 'duration', 0),
                    'X-Plex-Client-Identifier' => $context->backendId,
                ]));

                $logContext['remote']['url'] = (string)$url;

                $this->logger->debug(
                    message: "{action}: Updating '{client}: {user}@{backend}' {item.type} '#{item.id}: {item.title}' watch progress to '{progress}'.",
                    context: [
                        ...$logContext,
                        'progress' => formatDuration($entity->getPlayProgress()),
                        'time' => $entity->getPlayProgress(),
                    ]
                );

                if (false === (bool)ag($context->options, Options::DRY_RUN, false)) {
                    $queue->add(
                        $this->http->request(
                            method: Method::POST,
                            url: (string)$url,
                            options: array_replace_recursive($context->backendHeaders, [
                                'user_data' => ['id' => $key, 'context' => $logContext]
                            ])
                        )
                    );
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' change {item.type} '#{item.id}: {item.title}' watch progress. '{error.message}' at '{error.file}:{error.line}'.",
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

        return new Response(status: true, response: $queue);
    }
}
