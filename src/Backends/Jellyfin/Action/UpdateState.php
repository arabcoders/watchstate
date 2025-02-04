<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinClient;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\Date;
use App\Libs\Options;
use App\Libs\QueueRequests;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

class UpdateState
{
    use CommonTrait;

    protected string $action = 'jellyfin.updateState';

    public function __construct(protected iHttp $http, protected iLogger $logger)
    {
    }

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
            fn: function () use ($context, $entities, $opts, $queue) {
                foreach ($entities as $entity) {
                    $meta = $entity->getMetadata($context->backendName);
                    if (count($meta) < 1) {
                        continue;
                    }

                    if ($entity->isWatched() === (bool)ag($meta, iState::COLUMN_WATCHED)) {
                        continue;
                    }

                    if (null === ($itemId = ag($meta, iState::COLUMN_ID))) {
                        continue;
                    }

                    $url = $context->backendUrl->withPath(
                        r('/Users/{user_id}/PlayedItems/{item_id}', [
                            'user_id' => $context->backendUser,
                            'item_id' => $itemId,
                        ])
                    );

                    if ($context->clientName === JellyfinClient::CLIENT_NAME) {
                        $url = $url->withQuery(
                            http_build_query([
                                'DatePlayed' => makeDate($entity->updated)->format(Date::ATOM)
                            ])
                        );
                    }

                    if (true === (bool)ag($context->options, Options::DRY_RUN, false)) {
                        $this->logger->notice(
                            "Would mark '{backend}' {item.type} '{item.title}' as '{item.play_state}'.",
                            [
                                'backend' => $context->backendName,
                                'item' => [
                                    'id' => $itemId,
                                    'title' => $entity->getName(),
                                    'type' => $entity->type == iState::TYPE_EPISODE ? 'episode' : 'movie',
                                    'play_state' => $entity->isWatched() ? 'played' : 'unplayed',
                                ],
                            ]
                        );
                        return new Response(status: true);
                    }

                    $queue->add(
                        $this->http->request(
                            method: $entity->isWatched() ? 'POST' : 'DELETE',
                            url: (string)$url,
                            options: $context->backendHeaders + [
                                'user_data' => [
                                    'context' => [
                                        'backend' => $context->backendName,
                                        'play_state' => $entity->isWatched() ? 'played' : 'unplayed',
                                        'item' => [
                                            'id' => $itemId,
                                            'title' => $entity->getName(),
                                            'type' => $entity->type == iState::TYPE_EPISODE ? 'episode' : 'movie',
                                            'state' => $entity->isWatched() ? 'played' : 'unplayed',
                                        ],
                                        'url' => (string)$url,
                                    ]
                                ],
                            ]
                        )
                    );
                }

                return new Response(status: true);
            },
            action: $this->action
        );
    }
}
