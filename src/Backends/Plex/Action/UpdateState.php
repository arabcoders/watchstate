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
                    'identity' => [
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                        'user' => $context->backendUser,
                    ],
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
                            message: "Would mark '{identity.user}@{identity.backend}' {history.type} '{history.title}' as '{history.play_state}'.",
                            context: [
                                ...$rContext,
                                'history' => [
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

                    $queue->add(
                        new Request(
                            method: Method::GET,
                            url: $url,
                            options: $context->getHttpOptions(),
                            success: function (ResponseInterface $response) use ($entity, $itemId, $rContext): array {
                                $requestContext = [
                                    ...$rContext,
                                    'play_state' => $entity->isWatched() ? 'played' : 'unplayed',
                                    'history' => [
                                        'id' => $itemId,
                                        'title' => $entity->getName(),
                                        'type' => $entity->type === iState::TYPE_EPISODE ? 'episode' : 'movie',
                                        'state' => $entity->isWatched() ? 'played' : 'unplayed',
                                    ],
                                ];

                                $statusCode = $response->getStatusCode();
                                if (Status::OK !== Status::tryFrom($statusCode)) {
                                    $this->logger->error(
                                        message: "Failed to change '{identity.user}@{identity.backend}' - '{history.title}' play state. Invalid HTTP '{response.status_code}' status code returned.",
                                        context: [
                                            ...$requestContext,
                                            'response' => ['status_code' => $statusCode],
                                        ],
                                    );

                                    return [];
                                }

                                $this->logger->notice(
                                    message: "Changed '{identity.user}@{identity.backend}' - '{history.title}' play state to '{play_state}'.",
                                    context: $requestContext,
                                );

                                return [];
                            },
                            error: function (Throwable $e) use ($entity, $itemId, $rContext): array {
                                $this->logger->error(
                                    ...lw(
                                        message: "Failed during '{identity.user}@{identity.backend}' restore play state of {history.type} '{history.title}'. {exception.message}",
                                        context: [
                                            ...$rContext,
                                            'play_state' => $entity->isWatched() ? 'played' : 'unplayed',
                                            'history' => [
                                                'id' => $itemId,
                                                'title' => $entity->getName(),
                                                'type' => $entity->type === iState::TYPE_EPISODE ? 'episode' : 'movie',
                                                'state' => $entity->isWatched() ? 'played' : 'unplayed',
                                            ],
                                            ...exception_log($e),
                                        ],
                                        e: $e,
                                    ),
                                );

                                return [];
                            },
                            extras: [
                                'context' => [
                                    ...$rContext,
                                    'play_state' => $entity->isWatched() ? 'played' : 'unplayed',
                                    'history' => [
                                        'id' => $itemId,
                                        'title' => $entity->getName(),
                                        'type' => $entity->type === iState::TYPE_EPISODE ? 'episode' : 'movie',
                                        'state' => $entity->isWatched() ? 'played' : 'unplayed',
                                    ],
                                ],
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
