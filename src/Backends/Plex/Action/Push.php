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

            if (null === ag($metadata, iState::COLUMN_ID)) {
                $this->logger->warning(
                    message: "Ignoring '#{history.id}: {history.title}' for '{identity.user}@{identity.backend}'. No metadata was found.",
                    context: $logContext,
                );
                continue;
            }

            $logContext['remote']['id'] = ag($metadata, iState::COLUMN_ID);

            try {
                $url = $context->backendUrl->withPath('/library/metadata/' . ag($metadata, iState::COLUMN_ID));

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
                        message: "Failed during '{identity.user}@{identity.backend}' request for {history.type} '#{history.id}: {history.title}' metadata. {exception.message}",
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

                assert($entity instanceof iState, 'Expected state entity for Plex push response.');

                if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
                    if (Status::NOT_FOUND === Status::tryFrom($response->getStatusCode())) {
                        $this->logger->warning(
                            message: "Request for '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' metadata returned with (404: Not Found) status code.",
                            context: [...$logContext, 'response' => ['status_code' => $response->getStatusCode()]],
                        );
                    } else {
                        $this->logger->error(
                            message: "Request for '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' metadata returned with unexpected '{response.status_code}' status code.",
                            context: [...$logContext, 'response' => ['status_code' => $response->getStatusCode()]],
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
                        message: "Parsing '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' payload.",
                        context: [...$logContext, 'response' => ['body' => $body]],
                    );
                }

                $json = ag($body, 'MediaContainer.Metadata.0', []);

                if (empty($json)) {
                    $this->logger->error(
                        message: "Ignoring '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}'. Returned with unexpected body.",
                        context: [...$logContext, 'response' => ['body' => $body]],
                    );
                    continue;
                }

                $isWatched = 0 === (int) ag($json, 'viewCount', 0) ? 0 : 1;

                if ($entity->watched === $isWatched) {
                    $this->logger->info(
                        message: "Ignoring '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}'. Play state is identical.",
                        context: $logContext,
                    );
                    continue;
                }

                if (false === (bool) ag($context->options, Options::IGNORE_DATE, false)) {
                    $dateKey = 1 === $isWatched ? 'lastViewedAt' : 'addedAt';

                    if (null === ($date = ag($json, $dateKey))) {
                        $this->logger->error(
                            message: "Ignoring '{identity.user}@{identity.backend}' {history.type} '{history.title}'. No {date_key} is set on backend object.",
                            context: ['date_key' => $dateKey, ...$logContext, 'response' => ['body' => $json]],
                        );
                        continue;
                    }

                    $date = make_date($date);

                    $timeExtra = (int) ag($context->options, Options::EXPORT_ALLOWED_TIME_DIFF, 10);

                    if ($date->getTimestamp() >= ($entity->updated + $timeExtra)) {
                        $this->logger->notice(
                            message: "Ignoring '{identity.user}@{identity.backend}' {history.type} '{history.title}'. Database date is older than backend date.",
                            context: [
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

                $logContext['request']['url'] = $url;
                $requestContext = $logContext + ['play_state' => $entity->isWatched() ? 'Played' : 'Unplayed'];

                $this->logger->debug(
                    message: "Queuing request to change '{identity.user}@{identity.backend}' {history.type} '#{history.id}: {history.title}' play state to '{play_state}'.",
                    context: $requestContext,
                );

                if (false === (bool) ag($context->options, Options::DRY_RUN)) {
                    $queue->add(
                        new Request(
                            method: Method::GET,
                            url: $url,
                            options: $context->getHttpOptions(),
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
                        message: "Failed during '{identity.user}@{identity.backend}' push play state. {exception.message}",
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
}
