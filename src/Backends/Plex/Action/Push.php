<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Request;
use App\Backends\Common\Response;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Extends\RetryableHttpClient;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface as iDate;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
use Throwable;

final class Push
{
    use CommonTrait;

    private string $action = 'plex.push';

    /**
     * @var iHttp&RetryableHttpClient
     */
    private iHttp $http;

    public function __construct(
        iHttp $http,
        protected iLogger $logger,
    ) {
        $this->http = new RetryableHttpClient($http, maxRetries: 3, logger: $this->logger);
    }

    /**
     * Push Play state.
     *
     * @param Context $context
     * @param array<iState> $entities
     * @param QueueRequests $queue
     * @param iDate|null $after
     * @return Response
     */
    public function __invoke(
        Context $context,
        array $entities,
        QueueRequests $queue,
        ?iDate $after = null,
    ): Response {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->action($context, $entities, $queue, $after),
            action: $this->action,
        );
    }

    private function action(
        Context $context,
        array $entities,
        QueueRequests $queue,
        ?iDate $after = null,
    ): Response {
        $requests = [];

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
                ],
            ];

            if (null === ag($metadata, iState::COLUMN_ID)) {
                $this->logger->warning(
                    message: "Ignoring {item.type} '#{item.id}: {item.title}' for '{user}@{backend}': backend metadata is missing.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.push',
                        'operation' => 'load_remote_state',
                        'outcome' => 'ignored',
                        'reason' => 'missing_backend_metadata',
                        ...$logContext,
                    ],
                );
                continue;
            }

            $logContext['remote']['id'] = ag($metadata, iState::COLUMN_ID);

            try {
                $url = $context->backendUrl->withPath('/library/metadata/' . ag($metadata, iState::COLUMN_ID));

                $logContext['remote']['url'] = (string) $url;

                $this->logger->debug(
                    message: "Loading backend state for {item.type} '#{item.id}: {item.title}' from '{user}@{backend}'.",
                    context: [
                        'event_name' => 'backend.request.started',
                        'subsystem' => 'backend.push',
                        'operation' => 'load_remote_state',
                        'outcome' => 'started',
                        ...$logContext,
                    ],
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
                        message: "Failed to load backend state for {item.type} '#{item.id}: {item.title}' from '{user}@{backend}'.",
                        context: [
                            'event_name' => 'backend.client.request_failed',
                            'subsystem' => 'backend.push',
                            'operation' => 'load_remote_state',
                            'outcome' => 'failed',
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
                        message: "Push response for '{user}@{backend}' is missing the local entity reference.",
                        context: [
                            'event_name' => 'backend.response.failed',
                            'subsystem' => 'backend.push',
                            'operation' => 'load_remote_state',
                            'outcome' => 'failed',
                            'reason' => 'missing_entity_reference',
                            ...$logContext,
                        ],
                    );
                    continue;
                }

                $entity = $entities[$id];

                assert($entity instanceof iState, 'Expected state entity for Plex push response.');

                if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
                    if (Status::NOT_FOUND === Status::tryFrom($response->getStatusCode())) {
                        $this->logger->warning(
                            message: "Backend state for {item.type} '#{item.id}: {item.title}' was not found on '{user}@{backend}'.",
                            context: [
                                'event_name' => 'backend.response.failed',
                                'subsystem' => 'backend.push',
                                'operation' => 'load_remote_state',
                                'outcome' => 'failed',
                                'reason' => 'not_found',
                                ...$logContext,
                                'status_code' => $response->getStatusCode(),
                            ],
                        );
                    } else {
                        $this->logger->error(
                            message: "Backend state request for {item.type} '#{item.id}: {item.title}' on '{user}@{backend}' returned status {status_code}.",
                            context: [
                                'event_name' => 'backend.response.failed',
                                'subsystem' => 'backend.push',
                                'operation' => 'load_remote_state',
                                'outcome' => 'failed',
                                ...$logContext,
                                'status_code' => $response->getStatusCode(),
                            ],
                        );
                    }

                    continue;
                }

                $body = json_decode(
                    json: $response->getContent(),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
                );

                if ($context->trace) {
                    $this->logger->debug(
                        message: "Parsing backend state for {item.type} '#{item.id}: {item.title}' from '{user}@{backend}'.",
                        context: [
                            'event_name' => 'backend.response.received',
                            'subsystem' => 'backend.push',
                            'operation' => 'load_remote_state',
                            'outcome' => 'received',
                            ...$logContext,
                            'response' => ['body' => $body],
                        ],
                    );
                }

                $json = ag($body, 'MediaContainer.Metadata.0', []);

                if (empty($json)) {
                    $this->logger->error(
                        message: "Backend state for {item.type} '#{item.id}: {item.title}' from '{user}@{backend}' returned an empty payload.",
                        context: [
                            'event_name' => 'backend.response.failed',
                            'subsystem' => 'backend.push',
                            'operation' => 'load_remote_state',
                            'outcome' => 'failed',
                            'reason' => 'empty_response_body',
                            ...$logContext,
                            'response' => ['body' => $body],
                        ],
                    );
                    continue;
                }

                $isWatched = 0 === (int) ag($json, 'viewCount', 0) ? 0 : 1;
                $playState = 1 === $isWatched ? 'Played' : 'Unplayed';

                if ($entity->watched === $isWatched) {
                    $this->logger->info(
                        message: "Ignoring {item.type} '#{item.id}: {item.title}' from '{user}@{backend}': play state is already '{play_state}'.",
                        context: [
                            'event_name' => 'backend.item.ignored',
                            'subsystem' => 'backend.push',
                            'operation' => 'update_state',
                            'outcome' => 'ignored',
                            'reason' => 'state_unchanged',
                            ...$logContext,
                            'play_state' => $playState,
                        ],
                    );
                    continue;
                }

                if (false === (bool) ag($context->options, Options::IGNORE_DATE, false)) {
                    $dateKey = 1 === $isWatched ? 'lastViewedAt' : 'addedAt';

                    if (null === ($date = ag($json, $dateKey))) {
                        $this->logger->error(
                            message: "Ignoring {item.type} '#{item.id}: {item.title}' from '{user}@{backend}': missing backend date '{date_key}'.",
                            context: [
                                'event_name' => 'backend.item.ignored',
                                'subsystem' => 'backend.push',
                                'operation' => 'update_state',
                                'outcome' => 'ignored',
                                'reason' => 'missing_date',
                                'date_key' => $dateKey,
                                ...$logContext,
                                'response' => ['body' => $json],
                            ],
                        );
                        continue;
                    }

                    $date = make_date($date);

                    $timeExtra = (int) ag($context->options, Options::EXPORT_ALLOWED_TIME_DIFF, 10);

                    if ($date->getTimestamp() >= ($entity->updated + $timeExtra)) {
                        $this->logger->notice(
                            message: "Ignoring {item.type} '#{item.id}: {item.title}' from '{user}@{backend}': backend date is newer than local state.",
                            context: [
                                'event_name' => 'backend.item.ignored',
                                'subsystem' => 'backend.push',
                                'operation' => 'update_state',
                                'outcome' => 'ignored',
                                'reason' => 'backend_date_newer',
                                ...$logContext,
                                'comparison' => [
                                    'database' => make_date($entity->updated),
                                    'backend' => $date,
                                    'difference' => $date->getTimestamp() - $entity->updated,
                                    'extra_margin' => [Options::EXPORT_ALLOWED_TIME_DIFF => $timeExtra],
                                ],
                            ],
                        );
                        continue;
                    }
                }

                $url = $context
                    ->backendUrl
                    ->withPath($entity->isWatched() ? '/:/scrobble' : '/:/unscrobble')
                    ->withQuery(
                        http_build_query(
                            [
                                'identifier' => 'com.plexapp.plugins.library',
                                'key' => ag($json, 'ratingKey'),
                            ],
                        ),
                    );

                $logContext['remote']['url'] = (string) $url;
                $requestContext = $logContext + ['play_state' => $entity->isWatched() ? 'Played' : 'Unplayed'];

                $this->logger->debug(
                    message: "Updating play state for {item.type} '#{item.id}: {item.title}' on '{user}@{backend}' to '{play_state}'.",
                    context: [
                        'event_name' => 'backend.request.started',
                        'subsystem' => 'backend.push',
                        'operation' => 'update_state',
                        'outcome' => 'started',
                        ...$requestContext,
                    ],
                );

                if (false === (bool) ag($context->options, Options::DRY_RUN)) {
                    $queue->add(
                        new Request(
                            method: Method::GET,
                            url: $url,
                            options: $context->getHttpOptions(),
                            success: function (iResponse $response) use ($requestContext): array {
                                $statusCode = $response->getStatusCode();

                                if (Status::OK !== Status::tryFrom($statusCode)) {
                                    $this->logger->error(
                                        message: "Play-state update for {item.type} '#{item.id}: {item.title}' on '{user}@{backend}' returned status {status_code}.",
                                        context: [
                                            'event_name' => 'backend.response.failed',
                                            'subsystem' => 'backend.push',
                                            'operation' => 'update_state',
                                            'outcome' => 'failed',
                                            ...$requestContext,
                                            'status_code' => $statusCode,
                                        ],
                                    );

                                    return [];
                                }

                                $this->logger->notice(
                                    message: "Updated play state for {item.type} '#{item.id}: {item.title}' on '{user}@{backend}' to '{play_state}'.",
                                    context: [
                                        'event_name' => 'backend.state_update.completed',
                                        'subsystem' => 'backend.push',
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
                                        message: "Play-state request failed for {item.type} '#{item.id}: {item.title}' on '{user}@{backend}'.",
                                        context: [
                                            'event_name' => 'backend.client.request_failed',
                                            'subsystem' => 'backend.push',
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
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "Failed to prepare play-state update for {item.type} '#{item.id}: {item.title}' on '{user}@{backend}'.",
                        context: [
                            'event_name' => 'backend.operation.failed',
                            'subsystem' => 'backend.push',
                            'operation' => 'prepare_state_update',
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
