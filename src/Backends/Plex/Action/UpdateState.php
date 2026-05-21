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
use App\Libs\Options;
use App\Libs\QueueRequests;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

final class UpdateState
{
    use CommonTrait;

    private string $action = 'plex.updateState';

    public function __construct(
        protected iHttp $http,
        protected iLogger $logger,
    ) {}

    /**
     * Get Backend unique identifier.
     *
     * @param Context $context Context instance.
     * @param array<iState> $entities State instance.
     * @param QueueRequests $queue QueueRequests instance.
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, array $entities, QueueRequests $queue, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: function () use ($context, $entities, $queue) {
                $rContext = [
                    'action' => $this->action,
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'user' => $context->backendUser,
                ];

                foreach ($entities as $entity) {
                    $meta = $entity->getMetadata($context->backendName);
                    if (count($meta) < 1) {
                        continue;
                    }

                    if (null === ($itemId = ag($meta, iState::COLUMN_ID))) {
                        continue;
                    }

                    $itemBackendState = (bool) ag($meta, iState::COLUMN_WATCHED);

                    if ($entity->isWatched() === $itemBackendState) {
                        continue;
                    }

                    if (true === (bool) ag($context->options, Options::DRY_RUN, false)) {
                        $this->logger->notice(
                            message: "Would update play state for {item.type} '{item.title}' on '{user}@{backend}' to '{item.play_state}'.",
                            context: [
                                'event_name' => 'backend.request.skipped',
                                'subsystem' => 'backend.restore',
                                'operation' => 'update_state',
                                'outcome' => 'skipped',
                                'reason' => 'dry_run',
                                ...$rContext,
                                'item' => [
                                    'id' => $itemId,
                                    'title' => $entity->getName(),
                                    'type' => $entity->type === iState::TYPE_EPISODE ? 'episode' : 'movie',
                                    'play_state' => $entity->isWatched() ? 'played' : 'unplayed',
                                ],
                            ],
                        );
                        return new Response(status: true);
                    }

                    $url = $context
                        ->backendUrl
                        ->withPath($entity->isWatched() ? '/:/scrobble' : '/:/unscrobble')
                        ->withQuery(
                            http_build_query(['identifier' => 'com.plexapp.plugins.library', 'key' => $itemId]),
                        );

                    $requestContext = [
                        ...$rContext,
                        'play_state' => $entity->isWatched() ? 'played' : 'unplayed',
                        'item' => [
                            'id' => $itemId,
                            'title' => $entity->getName(),
                            'type' => $entity->type === iState::TYPE_EPISODE ? 'episode' : 'movie',
                            'state' => $entity->isWatched() ? 'played' : 'unplayed',
                        ],
                        'url' => (string) $url,
                    ];

                    $this->logger->debug(
                        message: "Updating play state for {item.type} '{item.title}' on '{user}@{backend}' to '{play_state}'.",
                        context: [
                            'event_name' => 'backend.request.started',
                            'subsystem' => 'backend.restore',
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
                            success: function (ResponseInterface $response) use ($requestContext): array {
                                $statusCode = $response->getStatusCode();
                                if (Status::OK !== Status::tryFrom($statusCode)) {
                                    $this->logger->error(
                                        message: "Play-state update for {item.type} '{item.title}' on '{user}@{backend}' returned status {status_code}.",
                                        context: [
                                            'event_name' => 'backend.response.failed',
                                            'subsystem' => 'backend.restore',
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
                                        'subsystem' => 'backend.restore',
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
                                            'subsystem' => 'backend.restore',
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

                return new Response(status: true);
            },
            action: $this->action,
        );
    }
}
