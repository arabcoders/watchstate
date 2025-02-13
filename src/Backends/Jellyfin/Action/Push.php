<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinClient as JFC;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Extends\Date;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
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
     * @param iHttp $http The HTTP client.
     * @param iLogger $logger The logger.
     */
    public function __construct(protected readonly iHttp $http, protected readonly iLogger $logger)
    {
    }

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
        DateTimeInterface|null $after = null
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

            if (null === ag($metadata, iState::COLUMN_ID, null)) {
                $this->logger->warning(
                    message: "{action}: Ignoring '{item.title}' for '{client}: {user}@{backend}'. No metadata was found.",
                    context: $logContext
                );
                continue;
            }

            $logContext['remote']['id'] = ag($metadata, iState::COLUMN_ID);

            try {
                $url = $context->backendUrl->withPath(
                    r('/Users/{user_id}/items/{item_id}', [
                        'user_id' => $context->backendUser,
                        'item_id' => ag($metadata, iState::COLUMN_ID),
                    ])
                )->withQuery(
                    http_build_query([
                        'fields' => implode(',', JFC::EXTRA_FIELDS),
                        'enableUserData' => 'true',
                        'enableImages' => 'false',
                    ])
                );

                $logContext['remote']['url'] = (string)$url;

                $this->logger->debug(
                    message: "{action}: Requesting '{client}: {user}@{backend}' {item.type} '{item.title}' metadata.",
                    context: $logContext
                );

                $requests[] = $this->http->request(
                    method: Method::GET->value,
                    url: (string)$url,
                    options: array_replace_recursive($context->backendHeaders, [
                        'user_data' => [
                            'id' => $key,
                            'context' => $logContext,
                        ]
                    ])
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "{action}: Exception '{error.kind}' unhandled during '{client}: {user}@{backend}' request for {item.type} '{item.title}' metadata. {error.message} at '{error.file}:{error.line}'.",
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

        $logContext = null;

        foreach ($requests as $response) {
            $logContext = ag($response->getInfo('user_data'), 'context', []);

            try {
                if (null === ($id = ag($response->getInfo('user_data'), 'id'))) {
                    $this->logger->error(
                        message: "{action}: Unable to get entity object id for '{client}: {user}@{backend}'.",
                        context: $logContext
                    );
                    continue;
                }

                $entity = $entities[$id];

                assert($entity instanceof iState);

                if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
                    if (Status::NOT_FOUND === Status::tryFrom($response->getStatusCode())) {
                        $this->logger->warning(
                            message: "{action}: Request for '{client}: {user}@{backend}' {item.type} '{item.title}' metadata returned with (404: Not Found) status code.",
                            context: [
                                ...$logContext,
                                'status_code' => $response->getStatusCode(),
                            ]
                        );
                    } else {
                        $this->logger->error(
                            message: "{action}: Request for '{client}: {user}@{backend}' {item.type} '{item.title}' metadata returned with unexpected '{status_code}' status code.",
                            context: [
                                'status_code' => $response->getStatusCode(),
                                ...$logContext
                            ]
                        );
                    }

                    continue;
                }

                $json = json_decode(
                    json: $response->getContent(),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                );

                if ($context->trace) {
                    $this->logger->debug(
                        message: "{action}: Parsing '{client}: {user}@{backend}' {item.type} '{item.title}' payload.",
                        context: [
                            ...$logContext,
                            'response' => ['body' => $json],
                        ]
                    );
                }

                $isWatched = (int)(bool)ag($json, 'UserData.Played', false);

                if ($entity->watched === $isWatched) {
                    $this->logger->info(
                        message: "{action}: Ignoring '{client}: {user}@{backend}' {item.type} '{item.title}'. Play state is identical.",
                        context: $logContext,
                    );
                    continue;
                }

                if (false === (bool)ag($context->options, Options::IGNORE_DATE, false)) {
                    $dateKey = 1 === $isWatched ? 'UserData.LastPlayedDate' : 'DateCreated';
                    if (null === ($date = ag($json, $dateKey))) {
                        $this->logger->error(
                            message: "{action}: Ignoring '{client}: {user}@{backend}' {item.type} '{item.title}'. No {date_key} is set on backend object.",
                            context: [
                                'date_key' => $dateKey,
                                ...$logContext,
                                'response' => [
                                    'body' => $json,
                                ],
                            ]
                        );
                        continue;
                    }

                    $date = makeDate($date);

                    $timeExtra = (int)(ag($context->options, Options::EXPORT_ALLOWED_TIME_DIFF, 10));

                    if ($date->getTimestamp() >= ($timeExtra + $entity->updated)) {
                        $this->logger->notice(
                            message: "{action}: Ignoring '{client}: {user}@{backend}' {item.type} '{item.title}'. Database date is older than backend date.",
                            context: [
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

                $url = $context->backendUrl->withPath(
                    r('/Users/{user_id}/PlayedItems/{item_id}', [
                        'user_id' => $context->backendUser,
                        'item_id' => ag($json, 'Id')
                    ])
                );

                if ($context->clientName === JFC::CLIENT_NAME) {
                    $url = $url->withQuery(
                        http_build_query([
                            'DatePlayed' => makeDate($entity->updated)->format(Date::ATOM)
                        ])
                    );
                }

                $logContext['remote']['url'] = (string)$url;

                $this->logger->debug(
                    message: "{action}: Queuing request to change '{client}: {user}@{backend}' {item.type} '{item.title}' play state to '{play_state}'.",
                    context: [
                        ...$logContext,
                        'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                    ]
                );

                if (false === (bool)ag($context->options, Options::DRY_RUN, false)) {
                    $queue->add(
                        $this->http->request(
                            method: ($entity->isWatched() ? Method::POST : Method::DELETE)->value,
                            url: (string)$url,
                            options: array_replace_recursive($context->backendHeaders, [
                                'user_data' => [
                                    'context' => $logContext + [
                                            'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                                        ],
                                ],
                            ])
                        )
                    );
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' parsing '{library.title}: {segment.number}/{segment.of}' response. {error.message} at '{error.file}:{error.line}'.",
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
