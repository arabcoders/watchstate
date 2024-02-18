<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexActionTrait;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
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
    private const DEFAULT_TIME_DRIFT = 30;

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
                'item' => [
                    'id' => $entity->id,
                    'type' => $entity->type,
                    'title' => $entity->getName(),
                ],
            ];

            if ($context->backendName === $entity->via) {
                $this->logger->info('Ignoring [{item.title}] for [{backend}]. Event originated from this backend.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                ]);
                continue;
            }

            if (null === ag($metadata, iState::COLUMN_ID, null)) {
                $this->logger->warning(
                    'Ignoring [{item.title}] for [{backend}]. No metadata was found.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                    ]
                );
                continue;
            }

            $senderDate = ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_DATE);
            if (null === $senderDate) {
                $this->logger->warning('Ignoring [{item.title}] for [{backend}]. Sender did not set a date.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                ]);
                continue;
            }
            $senderDate = makeDate($senderDate)->getTimestamp();
            $senderDate = $senderDate - (int)ag($context->options, 'progress.time_drift', self::DEFAULT_TIME_DRIFT);

            $datetime = ag($entity->getExtra($context->backendName), iState::COLUMN_EXTRA_DATE, null);

            if (false === $ignoreDate && null !== $datetime && makeDate($datetime)->getTimestamp() > $senderDate) {
                $this->logger->warning(
                    'Ignoring [{item.title}] for [{backend}]. Sender date is older than backend date.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                    ]
                );
                continue;
            }

            $logContext['remote']['id'] = ag($metadata, iState::COLUMN_ID);

            if (array_key_exists($logContext['remote']['id'], $sessions)) {
                $this->logger->notice(
                    'Ignoring [{item.title}] watch progress update for [{backend}]. The item is being played right now.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                    ]
                );
                continue;
            }

            try {
                $remoteData = ag(
                    $this->getItemDetails($context, $logContext['remote']['id'], [Options::NO_CACHE => true]),
                    'MediaContainer.Metadata.0',
                    []
                );

                $remoteItem = $this->createEntity($context, $guid, $remoteData, [
                    'latest_date' => true,
                ]);

                if (false === $ignoreDate && makeDate($remoteItem->updated)->getTimestamp() > $senderDate) {
                    $this->logger->info(
                        'Ignoring [{item.title}] for [{backend}]. Sender date is older than backend item date.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                        ]
                    );
                    continue;
                }

                if ($remoteItem->isWatched()) {
                    $this->logger->info(
                        'Ignoring [{item.title}] for [{backend}]. The backend reported the item as watched.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                        ]
                    );
                    continue;
                }
            } catch (\App\Libs\Exceptions\RuntimeException|RuntimeException|InvalidArgumentException $e) {
                $this->logger->error(
                    message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] request to get {item.type} [{item.title}] status. Error [{error.message} @ {error.file}:{error.line}].',
                    context: [
                        'backend' => $context->backendName,
                        'client' => $context->clientName,
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
                            'trace' => $context->trace ? $e->getTrace() : [],
                        ],
                    ]
                );
                continue;
            }

            try {
                // -- it seems /:/timeline/ allow us to update external user progress, while /:/progress/ does not.
                $url = $context->backendUrl
                    ->withPath('/:/timeline/')
                    ->withQuery(
                        http_build_query([
                            'ratingKey' => $logContext['remote']['id'],
                            'key' => '/library/metadata/' . $logContext['remote']['id'],
                            'identifier' => 'com.plexapp.plugins.library',
                            'state' => 'stopped',
                            'time' => $entity->getPlayProgress(),
                            // -- Without duration & client identifier plex ignore watch progress update.
                            'duration' => ag($remoteData, 'duration', 0),
                            'X-Plex-Client-Identifier' => md5('WatchState/' . getAppVersion())
                        ])
                    );

//                $url = $context->backendUrl->withPath('/:/progress/')->withQuery(
//                    http_build_query([
//                        'key' => $logContext['remote']['id'],
//                        'identifier' => 'com.plexapp.plugins.library',
//                        'state' => 'stopped',
//                        'time' => $entity->getPlayProgress(),
//                    ])
//                );

                $logContext['remote']['url'] = (string)$url;

                $this->logger->debug('Updating [{backend}] {item.type} [{item.title}] watch progress.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                ]);

                if (false === (bool)ag($context->options, Options::DRY_RUN, false)) {
                    $queue->add(
                        $this->http->request(
                            'POST',
                            (string)$url,
                            array_replace_recursive($context->backendHeaders, [
                                'user_data' => [
                                    'id' => $key,
                                    'context' => $logContext + [
                                            'backend' => $context->backendName,
                                        ],
                                ],
                            ])
                        )
                    );
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] request to change {item.type} [{item.title}] watch progress. Error [{error.message} @ {error.file}:{error.line}].',
                    context: [
                        'backend' => $context->backendName,
                        'client' => $context->clientName,
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
                            'trace' => $context->trace ? $e->getTrace() : [],
                        ],
                    ]
                );
            }
        }

        return new Response(status: true, response: $queue);
    }
}
