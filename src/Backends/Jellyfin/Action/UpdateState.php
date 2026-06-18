<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Request;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinClient;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Extends\Date;
use App\Libs\Options;
use App\Libs\QueueRequests;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
use Throwable;

class UpdateState
{
    use CommonTrait;

    protected string $action = 'jellyfin.updateState';

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

                    if ($entity->isWatched() === (bool) ag($meta, iState::COLUMN_WATCHED)) {
                        continue;
                    }

                    if (null === ($itemId = ag($meta, iState::COLUMN_ID))) {
                        continue;
                    }

                    $url = $context->backendUrl->withPath(
                        r('/Users/{user_id}/PlayedItems/{item_id}', [
                            'user_id' => $context->backendUser,
                            'item_id' => $itemId,
                        ]),
                    );

                    if ($context->clientName === JellyfinClient::CLIENT_NAME) {
                        $url = $url->withQuery(
                            http_build_query([
                                'DatePlayed' => make_date($entity->updated)->format(Date::ATOM),
                            ]),
                        );
                    }

                    if (true === (bool) ag($context->options, Options::DRY_RUN, false)) {
                        $this->logger->notice(
                            message: "Would mark '{identity.user}@{identity.backend}' {item.type} '{item.title}' as '{item.play_state}'.",
                            context: [
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

                    $queue->add(
                        new Request(
                            method: $entity->isWatched() ? Method::POST : Method::DELETE,
                            url: $url,
                            options: $context->getHttpOptions(),
                            success: function (iResponse $response) use ($entity, $itemId, $rContext, $url): array {
                                $requestContext = [
                                    ...$rContext,
                                    'play_state' => $entity->isWatched() ? 'played' : 'unplayed',
                                    'item' => [
                                        'id' => $itemId,
                                        'title' => $entity->getName(),
                                        'type' => $entity->type === iState::TYPE_EPISODE ? 'episode' : 'movie',
                                        'state' => $entity->isWatched() ? 'played' : 'unplayed',
                                    ],
                                    'request' => ['url' => (string) $url],
                                ];

                                $statusCode = $response->getStatusCode();
                                if (Status::OK !== Status::tryFrom($statusCode)) {
                                    $this->logger->error(
                                        message: "Failed to change '{identity.user}@{identity.backend}' - '{item.title}' play state. Invalid HTTP '{response.status_code}' status code returned.",
                                        context: [
                                            ...$requestContext,
                                            'response' => ['status_code' => $statusCode],
                                        ],
                                    );

                                    return [];
                                }

                                $this->logger->notice(
                                    message: "Changed '{identity.user}@{identity.backend}' - '{item.title}' play state to '{play_state}'.",
                                    context: $requestContext,
                                );

                                return [];
                            },
                            error: function (Throwable $e) use ($context, $entity, $itemId, $rContext): array {
                                $this->logger->error(
                                    ...lw(
                                        message: "Failed during '{identity.user}@{identity.backend}' restore play state of {item.type} '{item.title}'. {exception.message}",
                                        context: [
                                            ...$rContext,
                                            'play_state' => $entity->isWatched() ? 'played' : 'unplayed',
                                            'item' => [
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
                                    'item' => [
                                        'id' => $itemId,
                                        'title' => $entity->getName(),
                                        'type' => $entity->type === iState::TYPE_EPISODE ? 'episode' : 'movie',
                                        'state' => $entity->isWatched() ? 'played' : 'unplayed',
                                    ],
                                    'request' => ['url' => (string) $url],
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
