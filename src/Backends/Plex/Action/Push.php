<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class Push
{
    use CommonTrait;

    public function __construct(protected HttpClientInterface $http, protected LoggerInterface $logger)
    {
    }

    /**
     * Push Play state.
     *
     * @param Context $context
     * @param array<iState> $entities
     * @param QueueRequests $queue
     * @param DateTimeInterface|null $after
     * @return Response
     */
    public function __invoke(
        Context $context,
        array $entities,
        QueueRequests $queue,
        DateTimeInterface|null $after = null
    ): Response {
        return $this->tryResponse(context: $context, fn: fn() => $this->action($context, $entities, $queue, $after));
    }

    private function action(
        Context $context,
        array $entities,
        QueueRequests $queue,
        DateTimeInterface|null $after = null
    ): Response {
        $requests = [];

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

            if (null === ag($metadata, iState::COLUMN_ID)) {
                $this->logger->warning(
                    'Ignoring [%(item.title)] for [%(backend)]. No metadata was found.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                    ]
                );
                continue;
            }

            $logContext['remote']['id'] = ag($metadata, iState::COLUMN_ID);

            try {
                $url = $context->backendUrl->withPath('/library/metadata/' . ag($metadata, iState::COLUMN_ID));

                $logContext['remote']['url'] = (string)$url;

                $this->logger->debug('Requesting [%(backend)] %(item.type) [%(item.title)] metadata.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                ]);

                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    $context->backendHeaders + [
                        'user_data' => [
                            'id' => $key,
                            'context' => $logContext,
                        ]
                    ]
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    'Unhandled exception was thrown during request for [%(backend)] %(item.type) [%(item.title)] metadata.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                        'exception' => [
                            'file' => after($e->getFile(), ROOT_PATH),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                            'trace' => $context->trace ? $e->getTrace() : [],
                        ],
                    ]
                );
            }
        }

        $logContext = null;

        foreach ($requests as $response) {
            $logContext = ag($response->getInfo('user_data'), 'context', []);

            try {
                if (null === ($id = ag($response->getInfo('user_data'), 'id'))) {
                    $this->logger->error('Unable to get entity object id.', [
                        'backend' => $context->backendName,
                        ...$logContext,
                    ]);
                    continue;
                }

                $entity = $entities[$id];

                assert($entity instanceof iState);

                if (200 !== $response->getStatusCode()) {
                    if (404 === $response->getStatusCode()) {
                        $this->logger->warning(
                            'Request for [%(backend)] %(item.type) [%(item.title)] metadata returned with (Not Found) status code.',
                            [
                                'backend' => $context->backendName,
                                'status_code' => $response->getStatusCode(),
                                ...$logContext
                            ]
                        );
                    } else {
                        $this->logger->error(
                            'Request for [%(backend)] %(item.type) [%(item.title)] metadata returned with unexpected [%(status_code)] status code.',
                            [
                                'backend' => $context->backendName,
                                'status_code' => $response->getStatusCode(),
                                ...$logContext
                            ]
                        );
                    }

                    continue;
                }

                $body = json_decode(
                    json: $response->getContent(),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                );

                if ($context->trace) {
                    $this->logger->debug(
                        'Parsing [%(backend)] %(item.type) [%(item.title)] payload.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                            'trace' => $body,
                        ]
                    );
                }

                $json = ag($body, 'MediaContainer.Metadata.0', []);

                if (empty($json)) {
                    $this->logger->error(
                        'Ignoring [%(backend)] %(item.type) [%(item.title)]. Returned with unexpected body.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                            'response' => [
                                'body' => $body,
                            ],
                        ]
                    );
                    continue;
                }

                $isWatched = 0 === (int)ag($json, 'viewCount', 0) ? 0 : 1;

                if ($entity->watched === $isWatched) {
                    $this->logger->info(
                        'Ignoring [%(backend)] %(item.type) [%(item.title)]. Play state is identical.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                        ]
                    );
                    continue;
                }

                if (false === (bool)ag($context->options, Options::IGNORE_DATE, false)) {
                    $dateKey = 1 === $isWatched ? 'lastViewedAt' : 'addedAt';
                    $date = ag($json, $dateKey);

                    if (null === $date) {
                        $this->logger->error(
                            'Ignoring [%(backend)] %(item.type) [%(item.title)]. No %(date_key) is set on backend object.',
                            [
                                'backend' => $context->backendName,
                                'date_key' => $dateKey,
                                ...$logContext,
                                'response' => [
                                    'body' => $body,
                                ],
                            ]
                        );
                        continue;
                    }

                    $date = makeDate($date);

                    $timeExtra = (int)(ag($context->options, Options::EXPORT_ALLOWED_TIME_DIFF, 10));

                    if ($date->getTimestamp() >= ($entity->updated + $timeExtra)) {
                        $this->logger->notice(
                            'Ignoring [%(backend)] %(item.type) [%(item.title)]. Database date is older than backend date.',
                            [
                                'backend' => $context->backendName,
                                ...$logContext,
                                'comparison' => [
                                    'database' => makeDate($entity->updated),
                                    'backend' => $date,
                                    'difference' => $date->getTimestamp() - $entity->updated,
                                    'extra_margin' => [
                                        Options::EXPORT_ALLOWED_TIME_DIFF => $timeExtra,
                                    ],
                                ],
                            ]
                        );
                        continue;
                    }
                }

                $url = $context->backendUrl
                    ->withPath($entity->isWatched() ? '/:/scrobble' : '/:/unscrobble')->withQuery(
                        http_build_query(
                            [
                                'identifier' => 'com.plexapp.plugins.library',
                                'key' => ag($json, 'ratingKey'),
                            ]
                        )
                    );

                $logContext['remote']['url'] = $url;

                $this->logger->debug(
                    'Queuing request to change [%(backend)] %(item.type) [%(item.title)] play state to [%(play_state)].',
                    [
                        'backend' => $context->backendName,
                        'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                        ...$logContext,
                    ]
                );

                if (false === (bool)ag($context->options, Options::DRY_RUN)) {
                    $queue->add(
                        $this->http->request(
                            'GET',
                            (string)$url,
                            array_replace_recursive($context->backendHeaders, [
                                'user_data' => [
                                    'context' => $logContext + [
                                            'backend' => $context->backendName,
                                            'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                        ],
                                ]
                            ])
                        )
                    );
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    'Unhandled exception was thrown during handling of [%(backend)] %(item.type) [%(item.title)].',
                    [
                        'backend' => $context->backendName,
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
