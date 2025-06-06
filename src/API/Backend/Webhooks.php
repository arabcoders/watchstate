<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\API\Webhook\Index as WebhookURL;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use App\Libs\Uri;
use App\Listeners\ProcessRequestEvent;
use App\Model\Events\EventsTable;
use DateInterval;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;

final class Webhooks
{
    use APITraits;

    private Logger $logfile;

    public function __construct(
        #[Inject(DirectMapper::class)]
        private readonly iImport $mapper,
        private readonly iLogger $logger,
        LogSuppressor $suppressor,
        private readonly iCache $cache,
    ) {
        $this->logfile = new Logger(name: 'webhook', processors: [new LogMessageProcessor()]);

        $level = Config::get('webhook.debug') ? Level::Debug : Level::Info;

        if (null !== ($logfile = Config::get('webhook.logfile'))) {
            $this->logfile = $this->logfile->pushHandler(
                $suppressor->withHandler(new StreamHandler($logfile, $level, true))
            );
        }

        if (true === inContainer()) {
            $this->logfile->pushHandler($suppressor->withHandler(new StreamHandler('php://stderr', $level, true)));
        }
    }

    /**
     * Receive a webhook request from a backend.
     *
     * @param iRequest $request The incoming request object.
     * @param string $name backend name.
     *
     * @return iResponse The response object.
     * @throws InvalidArgumentException
     */
    #[Route(['POST', 'PUT'], Index::URL . '/{name:ubackend}/webhook[/]', name: 'backend.webhook')]
    public function __invoke(iRequest $request, string $name): iResponse
    {
        try {
            if (true === str_contains($name, '@')) {
                [$user, $ubackend] = explode('@', $name, 2);
            } else {
                $user = 'main';
                $ubackend = $name;
            }

            $userContext = getUserContext(user: $user, mapper: $this->mapper, logger: $this->logger);

            $backend = $this->getBackends(name: $ubackend, userContext: $userContext);

            if (empty($backend)) {
                throw new RuntimeException(r("Backend '{user}@{backend}' {backends} not found.", [
                    'user' => $user,
                    'backend' => $ubackend,
                ]));
            }

            $backend = array_pop($backend);

            $client = $this->getClient(name: $ubackend, userContext: $userContext);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $oldAPIEndpointKey = r('ows_{user}_{backend}', ['user' => $userContext->name, 'backend' => $ubackend]);
        if (false === $this->cache->has($oldAPIEndpointKey)) {
            $this->logger->warning(
                "Request from '{user}@{backend}' to the old webhook endpoint. switch to the new endpoint as this will be removed in the future.",
                [
                    'backend' => $ubackend,
                    'user' => $userContext->name,
                    'old_endpoint' => parseConfigValue(Index::URL) . "/{$name}/webhook",
                    'new_endpoint' => parseConfigValue(WebhookURL::URL),
                ]
            );
            $this->cache->set($oldAPIEndpointKey, true, new DateInterval('PT1H'));
        }

        if (true === Config::get('webhook.dumpRequest')) {
            saveRequestPayload(clone $request);
        }

        $request = $client->processRequest($request);
        $attr = $request->getAttributes();

        $debugTrace = true === (bool)ag($backend, 'options.' . Options::DEBUG_TRACE);

        if (null !== ($userId = ag($backend, 'user', null)) && true === (bool)ag($backend, 'webhook.match.user')) {
            if (null === ($requestUser = ag($attr, 'user.id'))) {
                $message = "Request payload didn't contain a user id. Backend requires a user check.";
                $this->write($request, Level::Info, $message);
                return api_error($message, Status::BAD_REQUEST);
            }

            if (false === hash_equals((string)$userId, (string)$requestUser)) {
                $message = r("Request user id '{req_user}' does not match configured value '{config_user}'.", [
                    'req_user' => $requestUser ?? 'NOT SET',
                    'config_user' => $userId,
                ]);
                $this->write($request, $debugTrace ? Level::Notice : Level::Debug, $message);
                return api_error($message, Status::BAD_REQUEST);
            }
        }

        if (null !== ($uuid = ag($backend, 'uuid', null)) && true === (bool)ag($backend, 'webhook.match.uuid')) {
            if (null === ($requestBackendId = ag($attr, 'backend.id'))) {
                $message = "Request payload didn't contain the backend unique id.";
                $this->write($request, Level::Info, $message);
                return api_error($message, Status::BAD_REQUEST);
            }

            if (false === hash_equals((string)$uuid, (string)$requestBackendId)) {
                $message = r("Request backend unique id '{req_uid}' does not match backend uuid '{config_uid}'.", [
                    'req_uid' => $requestBackendId ?? 'NOT SET',
                    'config_uid' => $uuid,
                ]);
                $this->write($request, $debugTrace ? Level::Notice : Level::Debug, $message);
                return api_error($message, Status::BAD_REQUEST);
            }
        }

        if (true === (bool)ag($backend, 'import.enabled')) {
            if (true === ag_exists($backend, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                $backend = ag_delete($backend, 'options.' . Options::IMPORT_METADATA_ONLY);
            }
        }

        $metadataOnly = true === (bool)ag($backend, 'options.' . Options::IMPORT_METADATA_ONLY);

        if (true !== $metadataOnly && true !== (bool)ag($backend, 'import.enabled')) {
            $response = api_response(Status::NOT_ACCEPTABLE);
            $this->write($request, Level::Warning, "Import are disabled for '{user}@{backend}'.", context: [
                'user' => $userContext->name,
                'backend' => $client->getName(),
            ], forceContext: true);

            return $response;
        }

        $entity = $client->parseWebhook($request);

        if (true === (bool)ag($backend, 'options.' . Options::DUMP_PAYLOAD)) {
            saveWebhookPayload($entity, $request);
        }

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $this->write(
                request: $request,
                level: Level::Info,
                message: "Ignoring '{user}@{backend}' {item.type} '{item.title}'. No valid/supported external ids.",
                context: [
                    'user' => $userContext->name,
                    'backend' => $entity->via,
                    'item' => [
                        'title' => $entity->getName(),
                        'type' => $entity->type,
                    ],
                ]
            );

            return api_response(Status::NOT_MODIFIED);
        }

        if ((0 === (int)$entity->episode || null === $entity->season) && $entity->isEpisode()) {
            $this->write(
                request: $request,
                level: Level::Notice,
                message: "Ignoring '{user}@{backend}' {item.type} '{item.title}'. No episode/season number present.",
                context: [
                    'user' => $userContext->name,
                    'backend' => $entity->via,
                    'item' => [
                        'title' => $entity->getName(),
                        'type' => $entity->type,
                        'season' => (string)($entity->season ?? 'None'),
                        'episode' => (string)($entity->episode ?? 'None'),
                    ]
                ]
            );

            return api_response(Status::NOT_MODIFIED);
        }

        $itemId = r('{type}://{id}:{tainted}@{backend}/{user}', [
            'user' => $userContext->name,
            'type' => $entity->type,
            'backend' => $entity->via,
            'tainted' => $entity->isTainted() ? 'tainted' : 'untainted',
            'id' => ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '??'),
        ]);

        queueEvent(ProcessRequestEvent::NAME, $entity->getAll(), [
            'unique' => true,
            EventsTable::COLUMN_REFERENCE => $itemId,
            EventsTable::COLUMN_OPTIONS => [
                'tainted' => $entity->isTainted(),
                Options::IMPORT_METADATA_ONLY => $metadataOnly,
                Options::REQUEST_ID => ag($request->getServerParams(), 'X_REQUEST_ID'),
                Options::DEBUG_TRACE => $debugTrace,
            ],
            Options::CONTEXT_USER => $userContext->name,
        ]);

        $this->write(
            request: $request,
            level: Level::Info,
            message: "Queuing '{user}@{backend}' {tainted} request for {item.type} '{item.title}'. {data}.",
            context: [
                'user' => $userContext->name,
                'backend' => $entity->via,
                'tainted' => $entity->isTainted() ? 'tainted' : 'untainted',
                'item' => [
                    'type' => $entity->type,
                    'title' => $entity->getName(),
                ],
                'data' => arrayToString([
                    'event' => ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_EVENT, '?'),
                    'played' => $entity->isWatched(),
                    'has_progress' => $entity->hasPlayProgress(),
                    'request_id' => ag($request->getServerParams(), 'X_REQUEST_ID', '-'),
                    'queue_id' => $itemId,
                    'progress' => $entity->hasPlayProgress() ? $entity->getPlayProgress() : null,
                ]),
            ]
        );

        return api_response(Status::OK);
    }

    /**
     * Write a log entry to the access log.
     *
     * @param iRequest $request The incoming request object.
     * @param int|string|Level $level The log level or priority.
     * @param string $message The log message.
     * @param array $context Additional data/context for the log entry.
     */
    private function write(
        iRequest $request,
        int|string|Level $level,
        string $message,
        array $context = [],
        bool $forceContext = false
    ): void {
        $params = $request->getServerParams();

        $uri = new Uri((string)ag($params, 'REQUEST_URI', '/'));

        if (false === empty($uri->getQuery())) {
            $query = [];
            parse_str($uri->getQuery(), $query);
            if (true === ag_exists($query, 'apikey')) {
                $query['apikey'] = 'api_key_removed';
                $uri = $uri->withQuery(http_build_query($query));
            }
        }

        $context = array_replace_recursive([
            'request' => [
                'method' => $request->getMethod(),
                'id' => ag($params, 'X_REQUEST_ID'),
                'ip' => getClientIp($request),
                'agent' => ag($params, 'HTTP_USER_AGENT'),
                'uri' => (string)$uri,
            ],
        ], $context);

        if (($attributes = $request->getAttributes()) && count($attributes) >= 1) {
            $context['attributes'] = $attributes;
        }

        if (true === (Config::get('logs.context') || $forceContext)) {
            $this->logfile->log($level, $message, $context);
        } else {
            $this->logfile->log($level, r($message, $context));
        }
    }
}
