<?php

declare(strict_types=1);

namespace App\API\Webhook;

use App\Backends\Common\ClientInterface as iClient;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\HttpException;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use App\Libs\Uri;
use App\Libs\UserContext;
use App\Listeners\ProcessRequestEvent;
use App\Model\Events\EventsTable;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

final class Index
{
    public const string URL = '%{api.prefix}/webhook';

    use APITraits;

    private Logger $logfile;

    public function __construct(
        #[Inject(DirectMapper::class)]
        private readonly iImport $mapper,
        private readonly iLogger $logger,
        LogSuppressor $suppressor,
    ) {
        $this->logfile = new Logger(name: 'webhook', processors: [new LogMessageProcessor()]);

        $level = Config::get('webhook.debug') ? Level::Debug : Level::Info;

        if (null !== ($logfile = Config::get('webhook.logfile'))) {
            $this->logfile->pushHandler(
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
     *
     * @return iResponse The response object.
     */
    #[Route(['POST', 'PUT'], Index::URL . '[/]', name: 'webhook.receive')]
    public function __invoke(iRequest $request): iResponse
    {
        if (true === Config::get('webhook.dumpRequest')) {
            saveRequestPayload(clone $request);
        }

        $client = null;
        $usersContext = getUsersContext(mapper: $this->mapper, logger: $this->logger);

        // -- Use main user backends to infer the payload content type.
        foreach (array_keys($usersContext['main']->config->getAll()) as $backendName) {
            $_client = $this->getClient(name: $backendName, userContext: $usersContext['main']);
            $request2 = $_client->processRequest($request);
            if (true !== ag_exists($request2->getAttributes(), 'backend')) {
                continue;
            }
            $client = $_client;
            $request = $request2;
            break;
        }

        if (null === $client) {
            $message = "No backend client were able to parse the the request.";
            $this->write($request, Level::Info, $message, context: [
                'headers' => $request->getHeaders(),
                'payload' => $request->getParsedBody(),
            ], forceContext: true);
            return api_error($message, Status::BAD_REQUEST);
        }

        $attr = $request->getAttributes();
        $isGeneric = (bool)ag($attr, 'webhook.generic', false);
        $userId = ag($attr, 'user.id');
        $uuid = ag($attr, 'backend.id');

        if (null === $uuid) {
            $message = "Request payload didn't contain a backend unique id.";
            $this->write($request, Level::Info, $message);
            return api_error($message, Status::BAD_REQUEST);
        }

        if (false === $isGeneric && null === $userId) {
            $message = "Request payload didn't contain a user id.";
            $this->write($request, Level::Info, $message);
            return api_error($message, Status::BAD_REQUEST);
        }

        $backends = [];

        // -- Now we need to match the request down to the user and backend.
        foreach ($usersContext as $userContext) {
            foreach ($userContext->config->getAll() as $backendName => $backendData) {
                if ((string)$uuid !== (string)ag($backendData, 'uuid')) {
                    continue;
                }

                if (false === $isGeneric && (string)$userId !== (string)ag($backendData, 'user')) {
                    continue;
                }

                $backends[] = [
                    'backendName' => $backendName,
                    'backend' => $backendData,
                    'userContext' => $userContext,
                    'client' => $this->getClient(name: $backendName, userContext: $userContext),
                ];
            }
        }

        if (count($backends) < 1) {
            $message = "Request from '{client}' didn't match any user/backend.";
            $this->write($request, Level::Info, $message, context: [
                'client' => $client->getName(),
                'headers' => $request->getHeaders(),
                'payload' => $request->getParsedBody(),
            ], forceContext: true);
            return api_error($message, Status::BAD_REQUEST);
        }

        $mainBackend = $backends[0];
        try {
            if (1 === count($backends)) {
                return $this->create_item(
                    userContext: $mainBackend['userContext'],
                    backendName: $mainBackend['backendName'],
                    client: $mainBackend['client'],
                    request: $request,
                    isGeneric: $isGeneric
                );
            }

            $client = $mainBackend['client'];
            assert($client instanceof iClient, 'Instance of iClient is expected here.');
            $entity = $client->parseWebhook($request, [Options::IS_GENERIC => $isGeneric]);
        } catch (Throwable $e) {
            $this->write(
                request: $request,
                level: Level::Error,
                message: "Failed to process webhook for '{user}@{backend}'. {msg}.",
                context: [
                    'user' => $mainBackend['userContext']->name,
                    'backend' => $mainBackend['backendName'],
                    'msg' => $e->getMessage(),
                    ...exception_log($e),
                ]
            );
            return api_response(Status::NOT_MODIFIED);
        }

        if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
            $this->write(
                request: $request,
                level: Level::Info,
                message: "Ignoring '{user}@{backend}' {item.type} '{item.title}'. No valid/supported external ids.",
                context: [
                    'user' => $mainBackend['userContext']->name,
                    'backend' => $entity->via,
                    'item' => [
                        'title' => $entity->getName(),
                        'type' => $entity->type,
                    ],
                ]
            );
            return api_response(Status::NOT_MODIFIED);
        }

        if ((0 === (int)$entity->episode || null === $entity->season) && true === $entity->isEpisode()) {
            $this->write(
                request: $request,
                level: Level::Notice,
                message: "Ignoring '{user}@{backend}' {item.type} '{item.title}'. No episode/season number present.",
                context: [
                    'user' => $mainBackend['userContext']->name,
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

        foreach ($backends as $target) {
            $backend = $target['userContext']->config->get($target['backendName']);
            $perUserRequest = $request->withAttribute(
                'backend',
                ag_sets(ag($request->getAttributes(), 'backend', []), [
                    'id' => ag($backend, 'uuid'),
                    'name' => $target['backendName'],
                ])
            )->withAttribute(
                'user',
                ag_sets(ag($request->getAttributes(), 'backend', []), [
                    'id' => ag($backend, 'user'),
                    'name' => $target['userContext']->name,
                ])
            );

            try {
                $this->create_item(
                    userContext: $target['userContext'],
                    backendName: $target['backendName'],
                    client: $target['client'],
                    request: $perUserRequest,
                    isGeneric: $isGeneric
                );
            } catch (Throwable $e) {
                $this->write(
                    request: $perUserRequest,
                    level: Level::Error,
                    message: "Failed to process '{user}@{backend}' {item.type} '{item.title}'. '{error.message}' at '{error.file}:{error.line}'. {trace}",
                    context: [
                        'user' => $target['userContext']->name,
                        'backend' => $target['backendName'],
                        'trace' => json_encode($e->getTrace(), flags: JSON_UNESCAPED_SLASHES),
                        'item' => [
                            'title' => $entity->getName(),
                            'type' => $entity->type,
                        ],
                        'msg' => $e->getMessage(),
                        ...exception_log($e),
                    ]
                );
            }
        }

        return api_response(Status::OK);
    }

    private function create_item(
        UserContext $userContext,
        string $backendName,
        iClient $client,
        iRequest $request,
        bool $isGeneric = false
    ): iResponse {
        $backend = $userContext->config->get($backendName);

        $debugTrace = true === (bool)ag($backend, 'options.' . Options::DEBUG_TRACE);

        if (true === ($importEnabled = (bool)ag($backend, 'import.enabled'))) {
            if (true === ag_exists($backend, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                $backend = ag_delete($backend, 'options.' . Options::IMPORT_METADATA_ONLY);
                $userContext->config->delete("{$backendName}.options." . Options::IMPORT_METADATA_ONLY)->persist();
            }
        }

        $metadataOnly = true === (bool)ag($backend, 'options.' . Options::IMPORT_METADATA_ONLY);

        if (true !== $metadataOnly && true !== $importEnabled) {
            $response = api_response(Status::NOT_ACCEPTABLE);
            if (true === $isGeneric) {
                return $response;
            }

            $this->write($request, Level::Warning, "Import are disabled for '{user}@{backend}'.", context: [
                'user' => $userContext->name,
                'backend' => $client->getName(),
            ], forceContext: true);

            return $response;
        }

        try {
            // -- Maybe the user doesn't have access to the item, so an http exception may be thrown.
            // -- ignore it if the request is generic.
            $entity = $client->parseWebhook($request, [Options::IS_GENERIC => $isGeneric]);
        } catch (HttpException $e) {
            if (true === $isGeneric) {
                return api_response(Status::NOT_MODIFIED);
            }
            throw $e;
        }

        if (true === (bool)ag($backend, 'options.' . Options::DUMP_PAYLOAD)) {
            saveWebhookPayload($entity, $request);
        }

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $response = api_response(Status::NOT_MODIFIED);
            if (true === $isGeneric) {
                return $response;
            }

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

            return $response;
        }

        if ((0 === (int)$entity->episode || null === $entity->season) && $entity->isEpisode()) {
            $response = api_response(Status::NOT_MODIFIED);
            if (true === $isGeneric) {
                return $response;
            }

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

            return $response;
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
                Options::IS_GENERIC => $isGeneric,
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
                    'generic' => $isGeneric,
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

        $message = "[G] {$message}";

        if (true === (Config::get('logs.context') || $forceContext)) {
            $this->logfile->log($level, $message, $context);
        } else {
            $this->logfile->log($level, r($message, $context));
        }
    }
}
