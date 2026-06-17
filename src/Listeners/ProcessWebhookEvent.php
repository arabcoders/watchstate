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
            $event->addLog($level, $message, $context);
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
            $this->write(
                $request,
                Level::Warning,
                'No backend client were able to parse the the request.',
                context: [
                    'headers' => $request->getHeaders(),
                    'payload' => $request->getParsedBody(),
                ],
                forceContext: true,
            );
            return;
        }

        $attr = $request->getAttributes();
        $isGeneric = (bool) ag($attr, 'webhook.generic', false);
        $userId = ag($attr, 'user.id');
        $uuid = ag($attr, 'backend.id');

        if (null === $uuid) {
            $this->write($request, Level::Notice, "Request payload didn't contain a backend unique id.");
            return;
        }

        if (false === $isGeneric && null === $userId) {
            $this->write($request, Level::Warning, "Request payload didn't contain a user id.");
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
                "Request from '{client}' didn't match any user/backend.",
                context: [
                    'client' => $client->getName(),
                    'headers' => $request->getHeaders(),
                    'payload' => $request->getParsedBody(),
                ],
                forceContext: true,
            );
            return;
        }

        $backends = $this->sortBackends($backends);

        if (true === (bool) ag($request->getAttributes(), 'webhook.noop', false)) {
            $this->write(
                $request,
                Level::Info,
                "Request from '{client}' treated as noop. No processing will be done.",
                context: [
                    'client' => $client->getName(),
                    'headers' => $request->getHeaders(),
                ],
                forceContext: false,
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
                message: "Failed to process webhook for '{user}@{backend}'. {msg}.",
                context: [
                    'user' => $mainBackend['userContext']->name,
                    'backend' => $mainBackend['backendName'],
                    'msg' => $e->getMessage(),
                    ...exception_log($e),
                ],
            );
            return;
        }

        if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
            $this->write(
                request: $request,
                level: Level::Warning,
                message: "Ignoring '{user}@{backend}' {item.type} '{item.title}'. No valid/supported external ids.",
                context: [
                    'user' => $mainBackend['userContext']->name,
                    'backend' => $entity->via,
                    'item' => [
                        'title' => $entity->getName(),
                        'type' => $entity->type,
                    ],
                ],
            );
            return;
        }

        if ((0 === (int) $entity->episode || null === $entity->season) && true === $entity->isEpisode()) {
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
                        'season' => (string) ($entity->season ?? 'None'),
                        'episode' => (string) ($entity->episode ?? 'None'),
                    ],
                ],
            );

            return;
        }

        if (count($backends) > 1) {
            $this->write(
                request: $request,
                level: Level::Info,
                message: "Request from '{client}' matched '{count}' user/backends.",
                context: [
                    'client' => $client->getName(),
                    'count' => count($backends),
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
                    message: "Failed to process '{user}@{backend}' {item.type} '{item.title}'. '{exception.message}' at '{exception.file}:{exception.line}'.",
                    context: [
                        'user' => $target['userContext']->name,
                        'backend' => $target['backendName'],
                        'item' => [
                            'title' => $entity->getName(),
                            'type' => $entity->type,
                        ],
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
        $importEnabled = true === (bool) ag($backend, 'import.enabled');

        if (true === $importEnabled) {
            if (true === ag_exists($backend, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                $backend = ag_delete($backend, 'options.' . Options::IMPORT_METADATA_ONLY);
                $userContext->config->delete("{$backendName}.options." . Options::IMPORT_METADATA_ONLY)->persist();
            }
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
                    message: "Ignoring '{user}@{backend}' {item.type} '{item.title}'. No valid/supported external ids.",
                    context: [
                        'user' => $userContext->name,
                        'backend' => $entity->via,
                        'item' => [
                            'title' => $entity->getName(),
                            'type' => $entity->type,
                        ],
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
                    message: "Ignoring '{user}@{backend}' {item.type} '{item.title}'. No episode/season number present.",
                    context: [
                        'user' => $userContext->name,
                        'backend' => $entity->via,
                        'item' => [
                            'title' => $entity->getName(),
                            'type' => $entity->type,
                            'season' => (string) ($entity->season ?? 'None'),
                            'episode' => (string) ($entity->episode ?? 'None'),
                        ],
                    ],
                );
            }

            return;
        }

        $opts = [
            'tainted' => $entity->isTainted(),
            Options::IMPORT_METADATA_ONLY => false === $importEnabled,
            Options::REQUEST_ID => ag($request->getServerParams(), 'X_REQUEST_ID'),
            Options::DEBUG_TRACE => $debugTrace,
            Options::IS_GENERIC => $isGeneric,
            Options::CONTEXT_USER => $userContext->name,
            Options::FAIL_FAST_ON_LOCK => true,
        ];

        if (true === (bool) $entity->getContext(Options::REPLAY_PROGRESS, false)) {
            $opts[Options::REPLAY_PROGRESS] = true;
        }

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
            ($this->writer)(Level::Error, $ex->getMessage());
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
                ($this->writer)(Level::Info, $ex->getMessage());
                return $event;
            }
        }

        if (null !== ($lastSync = ag($userContext->get($entity->via, []), 'import.lastSync'))) {
            $lastSync = make_date($lastSync);
        }

        ($this->writer)(Level::Notice, r("{prefix}Processing '{user}@{backend}' request '{title}'. {data}", [
            'backend' => $entity->via,
            'title' => $entity->getName(),
            'prefix' => true === $entity->isTainted() ? '[T] ' : '',
            'lastSync' => $lastSync,
            'user' => $userContext->name,
            'data' => array_to_string([
                'event' => ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_EVENT, '??'),
                'state' => $entity->isWatched() ? 'played' : 'unplayed',
                'progress' => $entity->hasPlayProgress() ? 'Yes' : 'No',
                'request_id' => ag($options, Options::REQUEST_ID, '-'),
            ]),
        ]));

        $isDebug = (bool) ag($options, Options::DEBUG_TRACE, false);
        if (true === (bool) ag($backend->getContext()->options, Options::DEBUG_TRACE, false)) {
            $isDebug = true;
        }

        $mapper = $userContext->mapper;

        if (true === $isDebug) {
            ($this->writer)(Level::Notice, 'Debug mode enabled.');
            $mapper = $mapper->setOptions(ag_set($mapper->getOptions(), Options::DEBUG_TRACE, true));
        }

        $logger = clone $this->logger;
        assert($logger instanceof Logger, 'Expected logger instance for request processing.');

        $handler = ProxyHandler::create(
            static function (string $_message, mixed $record) use ($event): void {
                if (false === $record instanceof \Monolog\LogRecord) {
                    return;
                }

                $event->addLog($record->level, $record->message, $record->context);
            },
            (bool) ag($options, Options::DEBUG_TRACE, false) ? Level::Debug : Level::Info,
        );

        $logger->pushHandler($handler);
        $mapper->setLogger($logger);
        $opts = [
            Options::IMPORT_METADATA_ONLY => (bool) ag($options, Options::IMPORT_METADATA_ONLY),
            Options::DISABLE_MARK_UNPLAYED => (bool) ag($options, Options::DISABLE_MARK_UNPLAYED),
            Options::FAIL_FAST_ON_LOCK => (bool) ag($options, Options::FAIL_FAST_ON_LOCK, false),
            Options::REPLAY_PROGRESS => (bool) ag($options, Options::REPLAY_PROGRESS, false),
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
        bool $forceContext = false,
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

        $forceContext = true === (Config::get('logs.context.force') || $forceContext);
        $msg = true === $forceContext ? r($message, $context) : $message;
        $this->event?->addLog($eventLevel, $msg, $forceContext ? [] : $context);
        $this->logger->log($level, $msg, $forceContext ? [] : $context);
    }

    /**
     * @param array<int,array{backendName:string,backend:array<string,mixed>,userContext:UserContext,client:iClient}> $backends
     *
     * @return array<int,array{backendName:string,backend:array<string,mixed>,userContext:UserContext,client:iClient}>
     */
    private function sortBackends(array $backends): array
    {
        $full = [];
        $metadata = [];

        foreach ($backends as $backend) {
            if (true !== (bool) ag($backend, 'backend.import.enabled')) {
                $metadata[] = $backend;
                continue;
            }

            $full[] = $backend;
        }

        return [...$full, ...$metadata];
    }
}
