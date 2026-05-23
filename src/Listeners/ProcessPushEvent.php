<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Backends\Common\Request;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\libs\Events\DataEvent;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\LoggerProxy;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\UserContext;
use App\Model\Events\EventListener;
use Monolog\Level;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

#[EventListener(self::NAME)]
final readonly class ProcessPushEvent
{
    public const string NAME = 'on_push';

    /**
     * Class constructor.
     *
     * @param iLogger $logger The logger object.
     */
    public function __construct(
        #[Inject(DirectMapper::class)]
        private iImport $mapper,
        private iLogger $logger,
        private QueueRequests $queue,
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');
    }

    public function __invoke(DataEvent $e): DataEvent
    {
        $writer = function (Level $level, string $message, array $context = []) use ($e) {
            $e->addLogEntry($level, $message, $context);
            $this->logger->log($level, $message, $context);
        };

        $e->stopPropagation();
        $this->queue->reset();

        $user = ag($e->getOptions(), Options::CONTEXT_USER, 'main');

        try {
            $userContext = get_user_context(user: $user, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $ex) {
            $writer(Level::Error, "Failed to load push user context for '{user}'.", [
                'event_name' => 'push.user_context.failed',
                'subsystem' => 'push',
                'operation' => 'load_user_context',
                'outcome' => 'failed',
                'user' => $user,
                ...exception_log($ex),
            ]);
            return $e;
        }

        if (null === ($item = $userContext->db->get(Container::get(iState::class)::fromArray($e->getData())))) {
            $writer(Level::Error, "Cannot push item '#{state_id}' for '{user}': item is missing or deleted.", [
                'event_name' => 'push.item.missing',
                'subsystem' => 'push',
                'operation' => 'load_item',
                'outcome' => 'failed',
                'reason' => 'missing_or_deleted',
                'user' => $user,
                'state_id' => ag($e->getData(), 'id', '?'),
            ]);
            return $e;
        }

        $writer(Level::Notice, "Processing push for '#{state_id}: {item_title}' from '{user}@{backend}'.", [
            'event_name' => 'push.item.processing',
            'subsystem' => 'push',
            'operation' => 'queue',
            'outcome' => 'started',
            'user' => $user,
            'state_id' => $item->id,
            'backend' => $item->via,
            'item_type' => $item->type,
            'item_title' => $item->getName(),
            'state' => $item->isWatched() ? 'played' : 'unplayed',
        ]);

        $options = $e->getOptions();
        $list = [];
        $supported = Config::get('supported', []);

        foreach ($userContext->config->getAll() as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if (true !== (bool) ag($backend, 'export.enabled')) {
                $writer(Level::Info, "Skipping push target '{user}@{backend}': export is disabled.", [
                    'event_name' => 'push.backend.skipped',
                    'subsystem' => 'push',
                    'operation' => 'queue',
                    'outcome' => 'skipped',
                    'reason' => 'export_disabled',
                    'user' => $user,
                    'backend' => $backendName,
                ]);
                continue;
            }

            if (!isset($supported[$type])) {
                $writer(Level::Error, "Skipping push target '{user}@{backend}': backend type '{type}' is unsupported.", [
                    'event_name' => 'push.backend.skipped',
                    'subsystem' => 'push',
                    'operation' => 'queue',
                    'outcome' => 'skipped',
                    'reason' => 'unsupported_type',
                    'type' => $type,
                    'user' => $user,
                    'backend' => $backendName,
                    'condition' => [
                        'expected' => implode(', ', array_keys($supported)),
                        'given' => $type,
                    ],
                ]);
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === is_valid_url($url)) {
                $writer(Level::Error, "Skipping push target '{user}@{backend}': URL '{url}' is invalid.", [
                    'event_name' => 'push.backend.skipped',
                    'subsystem' => 'push',
                    'operation' => 'queue',
                    'outcome' => 'skipped',
                    'reason' => 'invalid_url',
                    'user' => $user,
                    'backend' => $backendName,
                    'url' => $url ?? 'None',
                ]);
                continue;
            }

            $backend['name'] = $backendName;
            $list[$backendName] = $backend;
        }

        if (empty($list)) {
            $writer(Level::Error, "No eligible push targets found for '{user}'.", [
                'event_name' => 'push.queue.empty',
                'subsystem' => 'push',
                'operation' => 'queue',
                'outcome' => 'failed',
                'reason' => 'no_eligible_backends',
                'user' => $user,
                'backend_count' => 0,
            ]);
            return $e;
        }

        foreach ($list as $name => &$backend) {
            try {
                $opts = ag($backend, 'options', []);
                $backendLogger = LoggerProxy::create($writer);

                if (ag($options, Options::IGNORE_DATE)) {
                    $opts[Options::IGNORE_DATE] = true;
                }

                if (ag($options, Options::DRY_RUN)) {
                    $opts[Options::DRY_RUN] = true;
                }

                if (ag($options, Options::DEBUG_TRACE)) {
                    $opts[Options::DEBUG_TRACE] = true;
                }

                $backend['options'] = $opts;
                $backend['class'] = make_backend(backend: $backend, name: $name, options: [
                    iLogger::class => $backendLogger,
                    UserContext::class => $userContext,
                ])->setLogger($backendLogger);
                $backend['class']->push(entities: [$item->id => $item], queue: $this->queue);
            } catch (Throwable $e) {
                $writer(
                    Level::Error,
                    "Push queueing failed for '#{state_id}: {item_title}' on '{user}@{backend}'.",
                    [
                        'event_name' => 'push.item.failed',
                        'subsystem' => 'push',
                        'operation' => 'queue',
                        'outcome' => 'failed',
                        'user' => $user,
                        'backend' => $name,
                        'state_id' => $item->id,
                        'item_type' => $item->type,
                        'item_title' => $item->getName(),
                        ...exception_log($e),
                    ],
                );
            }
        }
        unset($backend);

        if (count($this->queue) < 1) {
            $writer(Level::Notice, "No play-state changes queued for '{user}'.", [
                'event_name' => 'push.queue.no_changes',
                'subsystem' => 'push',
                'operation' => 'queue',
                'outcome' => 'completed',
                'user' => $user,
                'queue_count' => 0,
            ]);
            return $e;
        }

        $writer(Level::Notice, "Processing push for '#{state_id}: {item_title}' from '{user}@{backend}'.", [
            'event_name' => 'push.item.processing',
            'subsystem' => 'push',
            'operation' => 'dispatch',
            'outcome' => 'started',
            'user' => $user,
            'state_id' => $item->id,
            'backend' => $item->via,
            'item_type' => $item->type,
            'item_title' => $item->getName(),
            'state' => $item->isWatched() ? 'played' : 'unplayed',
        ]);

        $http = Container::get(iHttp::class);
        assert($http instanceof iHttp, 'Expected HTTP client for push event queue dispatch.');

        send_requests(
            requests: $this->queue->getQueue(),
            client: $http,
            opts: [
                'ok' => static function (Request $request, ResponseInterface $response) use ($writer, $item, $user): array {
                    if (true === (bool) ag($request->options, 'user_data.' . Options::NO_LOGGING, false)) {
                        return [];
                    }

                    $context = ag($request->extras, 'context', []);
                    $context['user'] = $user;
                    $context['state_id'] = $item->id;
                    $context['status_code'] = $response->getStatusCode();

                    if (Status::OK !== Status::tryFrom($context['status_code'])) {
                        $writer(
                            Level::Error,
                            "Push update for '#{state_id}' on '{user}@{backend}' failed.",
                            [
                                ...$context,
                                'event_name' => 'push.request.failed',
                                'subsystem' => 'push',
                                'operation' => 'dispatch',
                                'outcome' => 'failed',
                                'http' => [
                                    'status_code' => $response->getStatusCode(),
                                ],
                            ],
                        );

                        return [];
                    }

                    $writer(
                        Level::Notice,
                        "Push update for '#{state_id}' on '{user}@{backend}' completed.",
                        [
                            ...$context,
                            'event_name' => 'push.request.completed',
                            'subsystem' => 'push',
                            'operation' => 'dispatch',
                            'outcome' => 'completed',
                            'http' => [
                                'status_code' => $response->getStatusCode(),
                            ],
                        ],
                    );

                    return [];
                },
                'error' => static function (Request $request, Throwable $ex) use ($writer, $item, $user): array {
                    if (true === (bool) ag($request->options, 'user_data.' . Options::NO_LOGGING, false)) {
                        return [];
                    }

                    $context = ag($request->extras, 'context', []);
                    $context['user'] = $user;
                    $context['state_id'] = $item->id;

                    $writer(
                        Level::Error,
                        "Push update for '#{state_id}' on '{user}@{backend}' failed.",
                        [
                            ...$context,
                            'event_name' => 'push.request.failed',
                            'subsystem' => 'push',
                            'operation' => 'dispatch',
                            'outcome' => 'failed',
                            ...exception_log($ex),
                        ],
                    );

                    return [];
                },
            ],
        );

        return $e;
    }
}
