<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Backends\Common\ClientInterface as iClient;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Events\DataEvent;
use App\Libs\Exceptions\HttpException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\LoggerProxy;
use App\Libs\Extends\ProxyHandler;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use App\Libs\Uri;
use App\Libs\UserContext;
use App\Model\Events\EventListener;
use Closure;
use Monolog\Level;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

#[EventListener(self::NAME)]
#[EventListener(self::REQUEST_NAME)]
final class ProcessWebhookEvent
{
    public const string NAME = 'on_webhook';
    public const string REQUEST_NAME = 'process_request';

    use APITraits;

    private ?DataEvent $event = null;
    private ?Closure $writer = null;

    /**
     * Class constructor.
     *
     * @param iImport $mapper Import mapper.
     * @param iLogger $logger Application logger.
     */
    public function __construct(
        #[Inject(DirectMapper::class)]
        private readonly iImport $mapper,
        private readonly iLogger $logger,
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');
    }

    /**
     * Process queued webhook request data.
     *
     * @param DataEvent $event Queued event.
     *
     * @return DataEvent Processed event.
     */
    public function __invoke(DataEvent $event): DataEvent
    {
        $event->stopPropagation();
        $this->event = $event;

        $this->writer = function (Level $level, string $message, array $context = []) use ($event): void {
            $event->addLogEntry($level, $message, $context);
            $this->logger->log($level, $message, $context);
        };

        try {
            if (self::REQUEST_NAME === $event->getEvent()->event) {
                return $this->processState($event, $event->getData(), $event->getOptions());
            }

            $this->process($this->request($event->getData()));
        } finally {
            $this->event = null;
        }

        return $event;
    }

    private function request(array $data): iRequest
    {
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
        $post = ag($data, 'post');

        $request = $creator->fromArrays(
            server: ag($data, 'server', []),
            get: ag($data, 'get', []),
            post: true === is_array($post) ? $post : null,
            cookie: ag($data, 'cookie', []),
            files: ag($data, 'files', []),
            body: (string) ag($data, 'body', ''),
        );

        if (null !== $post) {
            $request = $request->withParsedBody($post);
        }

        return $request;
    }

    private function process(iRequest $request): void
    {
        $client = null;
        $usersContext = get_users_context(mapper: $this->mapper, logger: $this->logger);

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
            $payload = $request->getParsedBody();
            $this->write(
                $request,
                Level::Warning,
                'Webhook request from {request.ip} could not be parsed by any backend client.',
                context: [
                    'event_name' => 'webhook.request.unparsed',
                    'subsystem' => 'webhook',
                    'operation' => 'request',
                    'outcome' => 'ignored',
                    'reason' => 'unsupported_payload',
                    'backend_client_count' => count($usersContext['main']->config->getAll()),
                    'payload_keys' => true === is_array($payload) ? array_keys($payload) : [],
                ],
            );
            return;
        }

        $attr = $request->getAttributes();
        $isGeneric = (bool) ag($attr, 'webhook.generic', false);
        $userId = ag($attr, 'user.id');
        $uuid = ag($attr, 'backend.id');

        if (null === $uuid) {
            $payload = $request->getParsedBody();
            $this->write($request, Level::Notice, 'Webhook request from {request.ip} is missing backend identifier.', [
                'event_name' => 'webhook.request.missing_backend_id',
                'subsystem' => 'webhook',
                'operation' => 'request',
                'outcome' => 'ignored',
                'reason' => 'missing_backend_id',
                'payload_keys' => true === is_array($payload) ? array_keys($payload) : [],
            ]);
            return;
        }

        if (false === $isGeneric && null === $userId) {
            $payload = $request->getParsedBody();
            $this->write($request, Level::Warning, 'Webhook request from {request.ip} is missing user identifier.', [
                'event_name' => 'webhook.request.missing_user_id',
                'subsystem' => 'webhook',
                'operation' => 'request',
                'outcome' => 'ignored',
                'reason' => 'missing_user_id',
                'backend_uuid' => (string) $uuid,
                'payload_keys' => true === is_array($payload) ? array_keys($payload) : [],
            ]);
            return;
        }

        $backends = [];

        // -- Now we need to match the request down to the user and backend.
        foreach ($usersContext as $userContext) {
            foreach ($userContext->config->getAll() as $backendName => $backendData) {
                if ((string) $uuid !== (string) ag($backendData, 'uuid')) {
                    continue;
                }

                if (false === $isGeneric && (string) $userId !== (string) ag($backendData, 'user')) {
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
            $this->write(
                $request,
                Level::Info,
                'Webhook request from {client} did not match any configured user/backend.',
                context: [
                    'event_name' => 'webhook.request.no_match',
                    'subsystem' => 'webhook',
                    'operation' => 'request',
                    'outcome' => 'ignored',
                    'reason' => 'no_config_match',
                    'client' => $client->getType(),
                    'backend_uuid' => (string) $uuid,
                    'user_id' => null === $userId ? null : (string) $userId,
                    'payload_keys' => true === is_array($request->getParsedBody()) ? array_keys($request->getParsedBody()) : [],
                ],
            );
            return;
        }

        if (true === (bool) ag($request->getAttributes(), 'webhook.noop', false)) {
            $this->write(
                $request,
                Level::Info,
                'Webhook request from {client} treated as noop: {reason_label}.',
                context: [
                    'event_name' => 'webhook.request.noop',
                    'subsystem' => 'webhook',
                    'operation' => 'request',
                    'outcome' => 'ignored',
                    'client' => $client->getType(),
                    'backend_uuid' => (string) $uuid,
                    'user_id' => null === $userId ? null : (string) $userId,
                    'reason' => 'noop',
                    'reason_label' => 'request flagged as noop',
                ],
            );
            return;
        }

        $mainBackend = $backends[0];
        try {
            if (1 === count($backends)) {
                $this->create_item(
                    userContext: $mainBackend['userContext'],
                    backendName: $mainBackend['backendName'],
                    client: $mainBackend['client'],
                    request: $request,
                    isGeneric: $isGeneric,
                );
                return;
            }

            $client = $mainBackend['client'];
            assert($client instanceof iClient, 'Instance of iClient is expected here.');
            $entity = $client->parseWebhook($request, [Options::IS_GENERIC => $isGeneric]);
        } catch (Throwable $e) {
            $this->write(
                request: $request,
                level: $this->level($e),
                message: "Failed to process webhook item from '{user}@{backend}'.",
                context: [
                    'event_name' => 'webhook.item.failed',
                    'subsystem' => 'webhook',
                    'operation' => 'process',
                    'outcome' => 'failed',
                    'user' => $mainBackend['userContext']->name,
                    'backend' => $mainBackend['backendName'],
                    'client' => $mainBackend['client']->getType(),
                    ...exception_log($e),
                ],
            );
            return;
        }

        if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
            $this->write(
                request: $request,
                level: Level::Warning,
                message: "Ignoring webhook item '{item_title}' from '{user}@{backend}': no supported external ids.",
                context: [
                    'event_name' => 'webhook.item.ignored',
                    'subsystem' => 'webhook',
                    'operation' => 'process',
                    'outcome' => 'ignored',
                    'reason' => 'no_supported_external_ids',
                    'user' => $mainBackend['userContext']->name,
                    'backend' => $entity->via,
                    'client' => $mainBackend['client']->getType(),
                    'item_id' => (string) ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '?'),
                    'item_type' => $entity->type,
                    'item_title' => $entity->getName(),
                    'guid_count' => count($entity->getGuids()),
                ],
            );
            return;
        }

        if ((0 === (int) $entity->episode || null === $entity->season) && true === $entity->isEpisode()) {
            $this->write(
                request: $request,
                level: Level::Notice,
                message: "Ignoring webhook item '{item_title}' from '{user}@{backend}': episode or season number is missing.",
                context: [
                    'event_name' => 'webhook.item.ignored',
                    'subsystem' => 'webhook',
                    'operation' => 'process',
                    'outcome' => 'ignored',
                    'reason' => 'missing_episode_metadata',
                    'user' => $mainBackend['userContext']->name,
                    'backend' => $entity->via,
                    'client' => $mainBackend['client']->getType(),
                    'item_id' => (string) ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '?'),
                    'item_type' => $entity->type,
                    'item_title' => $entity->getName(),
                    'season' => (string) ($entity->season ?? 'None'),
                    'episode' => (string) ($entity->episode ?? 'None'),
                ],
            );

            return;
        }

        if (count($backends) > 1) {
            $this->write(
                request: $request,
                level: Level::Info,
                message: 'Webhook request from {client} matched {count} configured user/backends.',
                context: [
                    'event_name' => 'webhook.request.matched_multiple',
                    'subsystem' => 'webhook',
                    'operation' => 'request',
                    'outcome' => 'matched',
                    'client' => $client->getType(),
                    'count' => count($backends),
                    'backend_uuid' => (string) $uuid,
                    'user_id' => null === $userId ? null : (string) $userId,
                ],
            );
        }

        foreach ($backends as $target) {
            $backend = $target['userContext']->config->get($target['backendName']);
            $perUserRequest = $request->withAttribute(
                'backend',
                ag_sets(ag($request->getAttributes(), 'backend', []), [
                    'id' => ag($backend, 'uuid'),
                    'name' => $target['backendName'],
                ]),
            )->withAttribute(
                'user',
                ag_sets(ag($request->getAttributes(), 'user', []), [
                    'id' => ag($backend, 'user'),
                    'name' => $target['userContext']->name,
                ]),
            );

            try {
                $this->create_item(
                    userContext: $target['userContext'],
                    backendName: $target['backendName'],
                    client: $target['client'],
                    request: $perUserRequest,
                    isGeneric: $isGeneric,
                );
            } catch (Throwable $e) {
                $this->write(
                    request: $perUserRequest,
                    level: $this->level($e),
                    message: "Failed to process webhook item '{item_title}' from '{user}@{backend}'.",
                    context: [
                        'event_name' => 'webhook.item.failed',
                        'subsystem' => 'webhook',
                        'operation' => 'process',
                        'outcome' => 'failed',
                        'user' => $target['userContext']->name,
                        'backend' => $target['backendName'],
                        'client' => $target['client']->getType(),
                        'item_id' => (string) ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '?'),
                        'item_type' => $entity->type,
                        'item_title' => $entity->getName(),
                        ...exception_log($e),
                    ],
                );
            }
        }
    }

    private function level(Throwable $e): Level
    {
        if ($e instanceof HttpException && Status::OK->value === $e->getCode()) {
            return Level::Info;
        }

        return Level::Error;
    }

    private function create_item(
        UserContext $userContext,
        string $backendName,
        iClient $client,
        iRequest $request,
        bool $isGeneric = false,
    ): void {
        $backend = $userContext->config->get($backendName);

        $debugTrace = true === (bool) ag($backend, 'options.' . Options::DEBUG_TRACE);

        if (true === ($importEnabled = (bool) ag($backend, 'import.enabled'))) {
            if (true === ag_exists($backend, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                $backend = ag_delete($backend, 'options.' . Options::IMPORT_METADATA_ONLY);
                $userContext->config->delete("{$backendName}.options." . Options::IMPORT_METADATA_ONLY)->persist();
            }
        }

        $metadataOnly = true === (bool) ag($backend, 'options.' . Options::IMPORT_METADATA_ONLY);

        if (true !== $metadataOnly && true !== $importEnabled) {
            if (false === $isGeneric) {
                $this->write(
                    $request,
                    Level::Warning,
                    "Ignoring webhook for '{user}@{backend}': import is disabled.",
                    context: [
                        'user' => $userContext->name,
                        'backend' => $backendName,
                        'client' => $client->getType(),
                        'event_name' => 'webhook.backend.import_disabled',
                        'subsystem' => 'webhook',
                        'operation' => 'process',
                        'outcome' => 'ignored',
                        'reason' => 'import_disabled',
                    ],
                );
            }

            return;
        }

        try {
            // -- Maybe the user doesn't have access to the item, so an http exception may be thrown.
            // -- ignore it if the request is generic.
            $entity = $client->parseWebhook($request, [Options::IS_GENERIC => $isGeneric]);
        } catch (HttpException $e) {
            if (true === $isGeneric) {
                return;
            }
            throw $e;
        }

        if (true === (bool) ag($backend, 'options.' . Options::DUMP_PAYLOAD)) {
            save_webhook_payload($entity, $request);
        }

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            if (false === $isGeneric) {
                $this->write(
                    request: $request,
                    level: Level::Warning,
                    message: "Ignoring webhook item '{item_title}' from '{user}@{backend}': no supported external ids.",
                    context: [
                        'event_name' => 'webhook.item.ignored',
                        'subsystem' => 'webhook',
                        'operation' => 'process',
                        'outcome' => 'ignored',
                        'reason' => 'no_supported_external_ids',
                        'user' => $userContext->name,
                        'backend' => $entity->via,
                        'client' => $client->getType(),
                        'item_id' => (string) ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '?'),
                        'item_title' => $entity->getName(),
                        'item_type' => $entity->type,
                        'guid_count' => count($entity->getGuids()),
                    ],
                );
            }

            return;
        }

        if ((0 === (int) $entity->episode || null === $entity->season) && $entity->isEpisode()) {
            if (false === $isGeneric) {
                $this->write(
                    request: $request,
                    level: Level::Notice,
                    message: "Ignoring webhook item '{item_title}' from '{user}@{backend}': episode or season number is missing.",
                    context: [
                        'event_name' => 'webhook.item.ignored',
                        'subsystem' => 'webhook',
                        'operation' => 'process',
                        'outcome' => 'ignored',
                        'reason' => 'missing_episode_metadata',
                        'user' => $userContext->name,
                        'backend' => $entity->via,
                        'client' => $client->getType(),
                        'item_id' => (string) ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '?'),
                        'item_title' => $entity->getName(),
                        'item_type' => $entity->type,
                        'season' => (string) ($entity->season ?? 'None'),
                        'episode' => (string) ($entity->episode ?? 'None'),
                    ],
                );
            }

            return;
        }

        $opts = [
            'tainted' => $entity->isTainted(),
            Options::IMPORT_METADATA_ONLY => $metadataOnly,
            Options::REQUEST_ID => ag($request->getServerParams(), 'X_REQUEST_ID'),
            Options::DEBUG_TRACE => $debugTrace,
            Options::IS_GENERIC => $isGeneric,
            Options::CONTEXT_USER => $userContext->name,
            Options::FAIL_FAST_ON_LOCK => true,
        ];

        if (null === $this->event) {
            return;
        }

        $this->processState($this->event, $entity->getAll(), $opts);
    }

    private function processState(DataEvent $event, array $data, array $options): DataEvent
    {
        $user = ag($options, Options::CONTEXT_USER, 'main');
        $isGeneric = true === (bool) ag($options, Options::IS_GENERIC, false);

        try {
            $userContext = get_user_context(user: $user, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $ex) {
            ($this->writer)(Level::Error, "Failed to load webhook user context for '{user}'.", [
                'event_name' => 'webhook.request.user_context_failed',
                'subsystem' => 'webhook',
                'operation' => 'request',
                'outcome' => 'failed',
                'user' => $user,
                ...exception_log($ex),
            ]);
            return $event;
        }

        $entity = Container::get(iState::class)::fromArray($data)
            ->setIsTainted((bool) ag($options, 'tainted', false));

        $backend = make_backend(backend: $userContext->config->get($entity->via), name: $entity->via, options: [
            iLogger::class => LoggerProxy::create($this->writer),
            UserContext::class => $userContext,
        ]);

        // -- revalidate the metadata based on user context.
        if (true === $isGeneric) {
            try {
                $backend->getMetadata(ag($entity->getMetadata($entity->via), iState::COLUMN_ID));
            } catch (Throwable $ex) {
                ($this->writer)(Level::Info, "Ignoring webhook item '{item_title}' from '{user}@{backend}': metadata is unavailable.", [
                    'event_name' => 'webhook.item.ignored',
                    'subsystem' => 'webhook',
                    'operation' => 'process',
                    'outcome' => 'ignored',
                    'reason' => 'metadata_unavailable',
                    'user' => $userContext->name,
                    'backend' => $entity->via,
                    'item_id' => (string) ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '?'),
                    'item_type' => $entity->type,
                    'item_title' => $entity->getName(),
                    ...exception_log($ex),
                ]);
                return $event;
            }
        }

        if (null !== ($lastSync = ag($userContext->get($entity->via, []), 'import.lastSync'))) {
            $lastSync = make_date($lastSync);
        }

        $isDebug = (bool) ag($options, Options::DEBUG_TRACE, false);
        if (true === (bool) ag($userContext->config->get("{$entity->via}.options"), Options::DEBUG_TRACE, false)) {
            $isDebug = true;
        }

        ($this->writer)(Level::Notice, "Processing webhook {item_type} '{item_title}' from '{user}@{backend}'.", [
            'event_name' => 'webhook.item.processing',
            'subsystem' => 'webhook',
            'operation' => 'process',
            'outcome' => 'started',
            'backend' => $entity->via,
            'client' => (string) ag($userContext->config->get($entity->via), 'type', $entity->via),
            'user' => $userContext->name,
            'item_id' => (string) ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '?'),
            'item_title' => $entity->getName(),
            'item_type' => $entity->type,
            'backend_item_id' => ag($entity->getMetadata($entity->via), iState::COLUMN_ID),
            'request_id' => ag($options, Options::REQUEST_ID, '-'),
            'state' => $entity->isWatched() ? 'played' : 'unplayed',
            'progress' => true === $entity->hasPlayProgress(),
            'webhook_event' => ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_EVENT, '??'),
            'last_sync' => $lastSync,
            'debug' => $isDebug,
            'tainted' => $entity->isTainted(),
        ]);

        $mapper = $userContext->mapper;

        if (true === $isDebug) {
            ($this->writer)(Level::Notice, "Webhook debug tracing enabled for '{user}@{backend}'.", [
                'event_name' => 'webhook.item.debug_enabled',
                'subsystem' => 'webhook',
                'operation' => 'process',
                'outcome' => 'started',
                'user' => $userContext->name,
                'backend' => $entity->via,
                'item_id' => (string) ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '?'),
                'item_title' => $entity->getName(),
                'item_type' => $entity->type,
                'debug' => true,
            ]);
            $mapper = $mapper->setOptions(ag_set($mapper->getOptions(), Options::DEBUG_TRACE, true));
        }

        $logger = clone $this->logger;
        assert($logger instanceof Logger, 'Expected logger instance for request processing.');

        $handler = ProxyHandler::create($event->addLog(...), Level::Info);
        $logger->pushHandler($handler);
        $mapper->setLogger($logger);
        $opts = [
            Options::IMPORT_METADATA_ONLY => (bool) ag($options, Options::IMPORT_METADATA_ONLY),
            Options::DISABLE_MARK_UNPLAYED => (bool) ag($options, Options::DISABLE_MARK_UNPLAYED),
            Options::FAIL_FAST_ON_LOCK => (bool) ag($options, Options::FAIL_FAST_ON_LOCK, false),
            Options::STATE_UPDATE_EVENT => static fn(iState $state) => queue_push(
                entity: $state,
                userContext: $userContext,
            ),
            Options::LOG_TO_WRITER => $this->writer,
            Options::AFTER => $lastSync,
        ];

        if (true === $isDebug) {
            $opts[Options::DEBUG_TRACE] = true;
        }

        $mapper->add($entity, $opts)->commit();
        $handler->close();

        return $event;
    }

    /**
     * Write a log entry to the event log.
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
    ): void {
        $params = $request->getServerParams();

        $uri = new Uri((string) ag($params, 'REQUEST_URI', '/'));

        if (false === empty($uri->getQuery())) {
            $query = [];
            parse_str($uri->getQuery(), $query);
            if (true === ag_exists($query, 'apikey')) {
                // @mago-expect lint:no-literal-password
                $query['apikey'] = '....';
                $uri = $uri->withQuery(http_build_query($query));
            }
        }

        $context = array_replace_recursive([
            'request' => [
                'method' => $request->getMethod(),
                'id' => ag($params, 'X_REQUEST_ID'),
                'ip' => get_client_ip($request),
                'agent' => ag($params, 'HTTP_USER_AGENT'),
                'uri' => (string) $uri,
            ],
        ], $context);

        if (($attributes = $request->getAttributes()) && count($attributes) >= 1) {
            $context['attributes'] = $attributes;
        }

        try {
            $eventLevel = Logger::toMonologLevel($level);
        } catch (Throwable) {
            $eventLevel = Level::Notice;
        }

        $this->event?->addLogEntry($eventLevel, $message, $context);

        $this->logger->log($level, $message, $context);
    }
}
