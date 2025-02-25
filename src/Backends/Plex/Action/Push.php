<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
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
use Throwable;

final class Push
{
    use CommonTrait;

    private string $action = 'plex.push';

    private iHttp $http;

    public function __construct(iHttp $http, protected iLogger $logger)
    {
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
        iDate|null $after = null
    ): Response {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->action($context, $entities, $queue, $after),
            action: $this->action
        );
    }

    private function action(
        Context $context,
        array $entities,
        QueueRequests $queue,
        iDate|null $after = null
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

            if (null === ag($metadata, iState::COLUMN_ID)) {
                $this->logger->warning(
                    message: "{action}: Ignoring '#{item.id}: {item.title}' for '{client}: {user}@{backend}'. No metadata was found.",
                    context: $logContext
                );
                continue;
            }

            $logContext['remote']['id'] = ag($metadata, iState::COLUMN_ID);

            try {
                $url = $context->backendUrl->withPath('/library/metadata/' . ag($metadata, iState::COLUMN_ID));

                $logContext['remote']['url'] = (string)$url;

                $this->logger->debug(
                    message: "{action}: Requesting '{client}: {user}@{backend}' {item.type} '#{item.id}: {item.title}' metadata.",
                    context: $logContext
                );

                $requests[] = $this->http->request(
                    method: Method::GET,
                    url: (string)$url,
                    options: array_replace_recursive($context->backendHeaders, [
                        'user_data' => ['id' => $key, 'context' => $logContext]
                    ]),
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "{action}: Exception '{error.kind}' unhandled during '{client}: {user}@{backend}' request for {item.type} '#{item.id}: {item.title}' metadata. {error.message} at '{error.file}:{error.line}'.",
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
                            message: "{action}: Request for '{client}: {user}@{backend}' {item.type} '#{item.id}: {item.title}' metadata returned with (404: Not Found) status code.",
                            context: [...$logContext, 'status_code' => $response->getStatusCode()]
                        );
                    } else {
                        $this->logger->error(
                            message: "{action}: Request for '{client}: {user}@{backend}' {item.type} '#{item.id}: {item.title}' metadata returned with unexpected '{status_code}' status code.",
                            context: [...$logContext, 'status_code' => $response->getStatusCode()]
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
                        message: "{action}: Parsing '{client}: {user}@{backend}' {item.type} '#{item.id}: {item.title}' payload.",
                        context: [...$logContext, 'response' => ['body' => $body]]
                    );
                }

                $json = ag($body, 'MediaContainer.Metadata.0', []);

                if (empty($json)) {
                    $this->logger->error(
                        message: "{action}: Ignoring '{client}: {user}@{backend}' {item.type} '#{item.id}: {item.title}'. Returned with unexpected body.",
                        context: [...$logContext, 'response' => ['body' => $body]]
                    );
                    continue;
                }

                $isWatched = 0 === (int)ag($json, 'viewCount', 0) ? 0 : 1;

                if ($entity->watched === $isWatched) {
                    $this->logger->info(
                        message: "{action}: Ignoring '{client}: {user}@{backend}' {item.type} '#{item.id}: {item.title}'. Play state is identical.",
                        context: $logContext,
                    );
                    continue;
                }

                if (false === (bool)ag($context->options, Options::IGNORE_DATE, false)) {
                    $dateKey = 1 === $isWatched ? 'lastViewedAt' : 'addedAt';

                    if (null === ($date = ag($json, $dateKey))) {
                        $this->logger->error(
                            message: "{action}: Ignoring '{client}: {user}@{backend}' {item.type} '{item.title}'. No {date_key} is set on backend object.",
                            context: ['date_key' => $dateKey, ...$logContext, 'response' => ['body' => $json]]
                        );
                        continue;
                    }

                    $date = makeDate($date);

                    $timeExtra = (int)(ag($context->options, Options::EXPORT_ALLOWED_TIME_DIFF, 10));

                    if ($date->getTimestamp() >= ($entity->updated + $timeExtra)) {
                        $this->logger->notice(
                            message: "{action}: Ignoring '{client}: {user}@{backend}' {item.type} '{item.title}'. Database date is older than backend date.",
                            context: [
                                ...$logContext,
                                'comparison' => [
                                    'database' => makeDate($entity->updated),
                                    'backend' => $date,
                                    'difference' => $date->getTimestamp() - $entity->updated,
                                    'extra_margin' => [Options::EXPORT_ALLOWED_TIME_DIFF => $timeExtra],
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
                                'key' => ag($json, 'ratingKey')
                            ]
                        )
                    );

                $logContext['remote']['url'] = $url;

                $this->logger->debug(
                    message: "{action}: Queuing request to change '{client}: {user}@{backend}' {item.type} '#{item.id}: {item.title}' play state to '{play_state}'.",
                    context: [...$logContext, 'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed']
                );

                if (false === (bool)ag($context->options, Options::DRY_RUN)) {
                    $queue->add(
                        $this->http->request(
                            method: Method::GET,
                            url: (string)$url,
                            options: array_replace_recursive($context->backendHeaders, [
                                'user_data' => [
                                    'context' => $logContext + [
                                            'play_state' => $entity->isWatched() ? 'Played' : 'Unplayed'
                                        ],
                                ]
                            ])
                        )
                    );
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    ...lw(
                        message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' push play state. {error.message} at '{error.file}:{error.line}'.",
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
