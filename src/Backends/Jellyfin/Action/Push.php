<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Request;
use App\Backends\Common\Response;
use App\Backends\Emby\Action\GetSessions as EmbyGetSessions;
use App\Backends\Emby\EmbyClient;
use App\Backends\Jellyfin\JellyfinClient as JFC;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Extends\Date;
use App\Libs\Extends\HttpClient;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Class Push
 *
 * This class is responsible for pushing the play state to jellyfin API.
 */
class Push
{
    use CommonTrait;

    /**
     * @var string Action name.
     */
    protected string $action = 'jellyfin.push';

    /**
     * Class constructor.
     *
     * @param iHttp&HttpClient $http The HTTP client.
     * @param iLogger $logger The logger.
     */
    public function __construct(
        protected readonly iHttp $http,
        protected readonly iLogger $logger,
    ) {}

    /**
     * Wrap the operation in try response block.
     *
     * @param Context $context Backend context.
     * @param array<iState> $entities Entities to process.
     * @param QueueRequests $queue The requests queue.
     * @param DateTimeInterface|null $after Only process entities updated after this date.
     *
     * @return Response the response.
     */
    public function __invoke(
        Context $context,
        array $entities,
        QueueRequests $queue,
        ?DateTimeInterface $after = null,
    ): Response {
        return $this->tryResponse(context: $context, fn: fn() => $this->action($context, $entities, $queue, $after));
    }

    /**
     * Push the play state to jellyfin API.
     *
     * @param Context $context Backend context.
     * @param array<iState> $entities Entities to process.
     * @param QueueRequests $queue The request queue.
     * @param DateTimeInterface|null $after (optional) Only process entities updated after this date.
     *
     * @return Response The response.
     */
    private function action(
        Context $context,
        array $entities,
        QueueRequests $queue,
        ?DateTimeInterface $after = null,
    ): Response {
        $requests = [];
        $sessions = $this->getActiveSessions($context);

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
                ],
            ];

            if (null === ag($metadata, iState::COLUMN_ID, null)) {
                $this->logger->warning(
                    message: "Ignoring '#{history.id}: {history.title}' for '{identity.user}@{identity.backend}'. No metadata was found.",
                    context: [
                        ...$logContext,
                        'operation' => 'push.skip',
                        'error' => 'no_metadata',
                    ],
                );
                continue;
            }

            $logContext['remote']['id'] = ag($metadata, iState::COLUMN_ID);

            try {
                $url = $context
                    ->backendUrl
                    ->withPath(
                        r('/Users/{user_id}/items/{item_id}', [
                            'user_id' => $context->backendUser,
                            'item_id' => ag($metadata, iState::COLUMN_ID),
                        ]),
                    )
                    ->withQuery(
                        http_build_query([
                            'fields' => implode(',', JFC::EXTRA_FIELDS),
                            'enableUserData' => 'true',
                            'enableImages' => 'false',
                        ]),
                    );

                $logContext['request']['url'] = (string) $url;

                $this->logger->debug(
                    message: "Requesting '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' metadata.",
                    context: $logContext,
                );

                $requests[] = $this->http->request(
                    method: Method::GET,
                    url: (string) $url,
                    options: array_replace_recursive($context->getHttpOptions(), [
                        'user_data' => ['id' => $key, 'context' => $logContext],
                    ]),
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "Failed to request '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' metadata. {exception.message}",
                        context: [
                            ...$logContext,
                            ...exception_log($e),
                        ],
                        e: $e,
                    ),
                );
            }
        }

        $logContext = null;

        foreach ($requests as $response) {
            $logContext = ag($response->getInfo('user_data'), 'context', []);

            try {
                if (null === ($id = ag($response->getInfo('user_data'), 'id'))) {
                    $this->logger->error(
                        message: "Unable to get entity object id for '{identity.user}@{identity.backend}'.",
                        context: $logContext,
                    );
                    continue;
                }

                $entity = $entities[$id];

                assert($entity instanceof iState, 'Expected state entity for Jellyfin push response.');

                if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
                    if (Status::NOT_FOUND === Status::tryFrom($response->getStatusCode())) {
                        $this->logger->warning(
                            message: "Request for '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' metadata returned HTTP 404 (Not Found).",
                            context: [...$logContext, 'response' => ['status_code' => $response->getStatusCode()]],
                        );
                    } else {
                        $this->logger->error(
                            message: "Request for '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' metadata returned HTTP {response.status_code}.",
                            context: [...$logContext, 'response' => ['status_code' => $response->getStatusCode()]],
                        );
                    }

                    continue;
                }

                $json = json_decode(
                    json: $response->getContent(),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
                );

                if ($context->trace) {
                    $this->logger->debug(
                        message: "Parsing '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' payload.",
                        context: [...$logContext, 'response' => ['body' => $json]],
                    );
                }

                $isWatched = (int) (bool) ag($json, 'UserData.Played', false);

                if ($entity->watched === $isWatched) {
                    $remoteProgress = (int) ag($json, 'UserData.PlaybackPositionTicks', 0);
                    if (true === $entity->isWatched() && $remoteProgress > 0) {
                        if (true === array_key_exists((string) ag($json, 'Id'), $sessions)) {
                            $this->logger->notice(
                                message: "Not clearing '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' watched item progress. The item is playing right now.",
                                context: [
                                    ...$logContext,
                                    'operation' => 'push.skip',
                                    'error' => 'currently_playing',
                                ],
                            );

                            continue;
                        }

                        $lastPlayed = make_date($entity->updated)->format(Date::ATOM);
                        $requestContext = $logContext + ['play_state' => 'Played'];

                        $this->logger->debug(
                            message: "Queuing request to clear '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' watched item progress.",
                            context: [
                                ...$requestContext,
                                'progress' => format_duration((int) floor($remoteProgress / 1_00_00)),
                            ],
                        );

                        if (false === (bool) ag($context->options, Options::DRY_RUN, false)) {
                            $queue->add($this->createProgressResetRequest($context, $json, $lastPlayed));
                        }

                        continue;
                    }

                    $this->logger->info(
                        message: "Ignoring '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}'. Play state is identical.",
                        context: [
                            ...$logContext,
                            'operation' => 'push.skip',
                            'error' => 'play_state_identical',
                        ],
                    );
                    continue;
                }

                if (false === (bool) ag($context->options, Options::IGNORE_DATE, false)) {
                    $dateKey = 1 === $isWatched ? 'UserData.LastPlayedDate' : 'DateCreated';
                    if (null === ($date = ag($json, $dateKey))) {
                        $this->logger->error(
                            message: "Ignoring '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}'. No {date_key} is set on backend object.",
                            context: [
                                'date_key' => $dateKey,
                                ...$logContext,
                                'operation' => 'push.skip',
                                'error' => 'no_backend_date',
                                'response' => ['body' => $json],
                            ],
                        );
                        continue;
                    }

                    $date = make_date($date);

                    $timeExtra = (int) ag($context->options, Options::EXPORT_ALLOWED_TIME_DIFF, 10);

                    if ($date->getTimestamp() >= ($timeExtra + $entity->updated)) {
                        $this->logger->notice(
                            message: "Ignoring '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}'. Database date is older than backend date.",
                            context: [
                                ...$logContext,
                                'operation' => 'push.skip',
                                'error' => 'stale_database_date',
                                'comparison' => [
                                    'database_date' => make_date($entity->updated),
                                    'backend_date' => $date,
                                    'delta_seconds' => $date->getTimestamp() - $entity->updated,
                                    'margin_seconds' => $timeExtra,
                                ],
                            ],
                        );
                        continue;
                    }
                }

                $url = $context->backendUrl->withPath(
                    r('/Users/{user_id}/PlayedItems/{item_id}', [
                        'user_id' => $context->backendUser,
                        'item_id' => ag($json, 'Id'),
                    ]),
                );

                $lastPlayed = make_date($entity->updated)->format(Date::ATOM);
                if ($context->clientName === JFC::CLIENT_NAME) {
                    $url = $url->withQuery(http_build_query(['DatePlayed' => $lastPlayed]));
                }
                if ($context->clientName === EmbyClient::CLIENT_NAME) {
                    $url = $url->withQuery(http_build_query([
                        'DatePlayed' => make_date($entity->updated)->format('YmdHis'),
                    ]));
                }

                $logContext['request']['url'] = (string) $url;
                $playState = $entity->isWatched() ? 'Played' : 'Unplayed';
                $requestContext = $logContext + ['play_state' => $playState];

                $this->logger->debug(
                    message: "Queuing request to change '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' play state to '{play_state}'.",
                    context: $requestContext,
                );

                if (false === (bool) ag($context->options, Options::DRY_RUN, false)) {
                    $queue->add(
                        new Request(
                            method: $entity->isWatched() ? Method::POST : Method::DELETE,
                            url: $url,
                            options: $context->getHttpOptions(),
                            success: function (ResponseInterface $response) use ($context, $entity, $json, $lastPlayed, $sessions): array {
                                if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
                                    return [];
                                }

                                if (true !== $entity->isWatched()) {
                                    return [];
                                }

                                if (true === array_key_exists((string) ag($json, 'Id'), $sessions)) {
                                    return [];
                                }

                                return [
                                    $this->createProgressResetRequest($context, $json, $lastPlayed),
                                ];
                            },
                            extras: [
                                'context' => $requestContext,
                                iHttp::class => $this->http,
                            ],
                        ),
                    );
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "Failed to push play state for '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}'. {exception.message}",
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

    private function getActiveSessions(Context $context): array
    {
        $sessions = [];

        try {
            $action = EmbyClient::CLIENT_NAME === $context->clientName ? EmbyGetSessions::class : GetSessions::class;
            $remoteSessions = Container::get($action)($context);
            if (true !== $remoteSessions->status) {
                return [];
            }

            foreach (ag($remoteSessions->response, 'sessions', []) as $session) {
                $userId = ag($session, 'user_id', null);
                if (!$userId || $context->backendUser !== $userId) {
                    continue;
                }

                $sessions[(string) ag($session, 'item_id')] = ag($session, 'item_offset_at', 0);
            }
        } catch (Throwable) {
            return [];
        }

        return $sessions;
    }

    private function createProgressResetRequest(Context $context, array $item, string $lastPlayed): Request
    {
        if (EmbyClient::CLIENT_NAME === $context->clientName) {
            $url = $context
                ->backendUrl
                ->withPath(r('/Users/{user}/PlayingItems/{id}/Progress', [
                    'user' => $context->backendUser,
                    'id' => ag($item, 'Id'),
                ]))
                ->withQuery(http_build_query([
                    'MediaSourceId' => ag($item, ['MediaSources.0.Id', 'MediaSourceId', 'Id']),
                    'PositionTicks' => 0,
                    'IsPaused' => 'true',
                    'PlayMethod' => 'DirectPlay',
                ]));

            return new Request(
                method: Method::POST,
                url: $url,
                options: array_replace_recursive($context->getHttpOptions(), [
                    'user_data' => [Options::NO_LOGGING => true],
                ]),
                extras: [iHttp::class => $this->http],
            );
        }

        return new Request(
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
        );
    }
}
