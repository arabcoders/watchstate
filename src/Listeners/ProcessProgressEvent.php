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
use App\Libs\Exceptions\Backends\NotImplementedException;
use App\Libs\Exceptions\Backends\UnexpectedVersionException;
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
final readonly class ProcessProgressEvent
{
    public const string NAME = 'on_progress';

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

    public function __invoke(DataEvent $event): DataEvent
    {
        $writer = function (Level $level, string $message, array $context = []) use ($event) {
            $event->addLogEntry($level, $message, $context);
            $this->logger->log($level, $message, $context);
        };
        $eventWriter = static function (Level $level, string $message, array $context = []) use ($event): void {
            $event->addLogEntry($level, $message, $context);
        };

        $event->stopPropagation();

        $this->queue->reset();

        $user = ag($event->getOptions(), Options::CONTEXT_USER, 'main');

        try {
            $userContext = get_user_context(user: $user, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $ex) {
            $writer(Level::Error, "Failed to load progress user context for '{user}'.", [
                'event_name' => 'progress.user_context.failed',
                'subsystem' => 'progress',
                'operation' => 'load_user_context',
                'outcome' => 'failed',
                'user' => $user,
                ...exception_log($ex),
            ]);
            return $event;
        }

        $options = $event->getOptions();
        $forceMetadataChange = true === (bool) ag($options, Options::FORCE_REPLACE_METADATA, false);
        $hasProgressValue = ag_exists($options, Options::STATE_PROGRESS_VALUE);

        if (null === ($item = $userContext->db->get(Container::get(iState::class)::fromArray($event->getData())))) {
            $writer(Level::Error, "Cannot update progress for '#{item_id}' and '{user}': item is not referenced locally.", [
                'event_name' => 'progress.item.missing',
                'subsystem' => 'progress',
                'operation' => 'load_item',
                'outcome' => 'failed',
                'reason' => 'missing_local_reference',
                'user' => $userContext->name,
                'item_id' => ag($event->getData(), 'id', '?'),
            ]);
            return $event;
        }

        if ($item->isWatched()) {
            $allowUpdate = (int) Config::get('progress.threshold', 0);
            $minThreshold = (int) Config::get('progress.minThreshold', 86_400);
            if (false === ($allowUpdate >= $minThreshold && time() > ($item->updated + $allowUpdate))) {
                $writer(
                    level: Level::Info,
                    message: "Skipping progress update for '#{item_id}: {item_title}': item is already watched.",
                    context: [
                        'event_name' => 'progress.item.skipped',
                        'subsystem' => 'progress',
                        'operation' => 'queue',
                        'outcome' => 'skipped',
                        'reason' => 'item_watched',
                        'item_id' => $item->id,
                        'item_title' => $item->getName(),
                        'user' => $userContext->name,
                        'progress' => $item->getPlayProgress(),
                        'comparison' => $allowUpdate > $minThreshold
                            ? array_to_string([
                                'threshold' => $allowUpdate,
                                'now' => ['secs' => time(), 'time' => make_date(time())],
                                'updated' => ['secs' => $item->updated, 'time' => make_date($item->updated)],
                                'diff' => time() - ($item->updated + $allowUpdate),
                            ]) : 'watch progress sync for played items is disabled.',
                    ],
                );
                return $event;
            }
        }

        if (false === $hasProgressValue && false === $item->hasPlayProgress()) {
            $writer(Level::Info, "No progress updates queued for '{user}'.", [
                'event_name' => 'progress.queue.empty',
                'subsystem' => 'progress',
                'operation' => 'queue',
                'outcome' => 'completed',
                'reason' => 'no_progress_to_export',
                'item_id' => $item->id,
                'item_title' => $item->title,
                'user' => $userContext->name,
                'queue_count' => 0,
            ]);
            return $event;
        }

        $progressValue = true === $hasProgressValue
            ? (int) ag($options, Options::STATE_PROGRESS_VALUE, 0)
            : $item->getPlayProgress();
        $progress = format_duration($progressValue);

        $list = [];

        $supported = Config::get('supported', []);

        foreach ($userContext->config->getAll() as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if (true !== (bool) ag($backend, 'export.enabled')) {
                $writer(Level::Notice, "Skipping progress target '{user}@{backend}': export is disabled.", [
                    'event_name' => 'progress.backend.skipped',
                    'subsystem' => 'progress',
                    'operation' => 'queue',
                    'outcome' => 'skipped',
                    'reason' => 'export_disabled',
                    'backend' => $backendName,
                    'user' => $userContext->name,
                ]);
                continue;
            }

            if (!isset($supported[$type])) {
                $writer(Level::Error, "Skipping progress target '{user}@{backend}': backend type '{type}' is unsupported.", [
                    'event_name' => 'progress.backend.skipped',
                    'subsystem' => 'progress',
                    'operation' => 'queue',
                    'outcome' => 'skipped',
                    'reason' => 'unsupported_type',
                    'type' => $type,
                    'backend' => $backendName,
                    'condition' => [
                        'expected' => implode(', ', array_keys($supported)),
                        'given' => $type,
                    ],
                    'user' => $userContext->name,
                ]);
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === is_valid_url($url)) {
                $writer(Level::Error, "Skipping progress target '{user}@{backend}': URL '{url}' is invalid.", [
                    'event_name' => 'progress.backend.skipped',
                    'subsystem' => 'progress',
                    'operation' => 'queue',
                    'outcome' => 'skipped',
                    'reason' => 'invalid_url',
                    'backend' => $backendName,
                    'url' => $url ?? 'None',
                    'user' => $userContext->name,
                ]);
                continue;
            }

            $backend['name'] = $backendName;
            $list[$backendName] = $backend;
        }

        if (empty($list)) {
            $writer(Level::Warning, "No progress updates queued for '{user}'.", [
                'event_name' => 'progress.queue.empty',
                'subsystem' => 'progress',
                'operation' => 'queue',
                'outcome' => 'failed',
                'reason' => 'no_eligible_backends',
                'user' => $userContext->name,
                'queue_count' => 0,
            ]);
            return $event;
        }

        foreach ($list as $name => &$backend) {
            try {
                $opts = ag($backend, 'options', []);
                $backendLogger = LoggerProxy::create($eventWriter);

                if (ag($options, Options::IGNORE_DATE)) {
                    $opts[Options::IGNORE_DATE] = true;
                }

                if (ag($options, Options::DRY_RUN)) {
                    $opts[Options::DRY_RUN] = true;
                }

                if (ag($options, Options::DEBUG_TRACE)) {
                    $opts[Options::DEBUG_TRACE] = true;
                }

                if (true === $forceMetadataChange) {
                    $opts[Options::FORCE_REPLACE_METADATA] = true;
                }

                if (true === $hasProgressValue) {
                    $opts[Options::STATE_PROGRESS_VALUE] = $progressValue;
                }

                $backend['options'] = $opts;
                $backend['class'] = make_backend(backend: $backend, name: $name, options: [
                    iLogger::class => $backendLogger,
                    UserContext::class => $userContext,
                ])->setLogger($backendLogger);
                $backend['class']->progress(entities: [$item->id => $item], queue: $this->queue);
            } catch (UnexpectedVersionException|NotImplementedException $ex) {
                $writer(
                    Level::Notice,
                    "Skipping progress update for '#{item_id}: {item_title}' on '{user}@{backend}': backend feature is unavailable.",
                    [
                        'event_name' => 'progress.item.skipped',
                        'subsystem' => 'progress',
                        'operation' => 'queue',
                        'outcome' => 'skipped',
                        'reason' => 'feature_unavailable',
                        'user' => $userContext->name,
                        'backend' => $name,
                        'item_id' => $item->id,
                        'item_type' => $item->type,
                        'item_title' => $item->getName(),
                        ...exception_log($ex),
                    ],
                );
            } catch (Throwable $ex) {
                $writer(
                    Level::Error,
                    "Failed to queue progress update for '#{item_id}: {item_title}' on '{user}@{backend}'.",
                    [
                        'event_name' => 'progress.item.failed',
                        'subsystem' => 'progress',
                        'operation' => 'queue',
                        'outcome' => 'failed',
                        'item_id' => $item->id,
                        'backend' => $name,
                        'item_type' => $item->type,
                        'item_title' => $item->getName(),
                        'user' => $userContext->name,
                        ...exception_log($ex),
                    ],
                );
            }
        }

        unset($backend);

        if (count($this->queue) < 1) {
            $writer(Level::Notice, "No progress updates queued for '{user}'.", [
                'event_name' => 'progress.queue.empty',
                'subsystem' => 'progress',
                'operation' => 'queue',
                'outcome' => 'completed',
                'reason' => 'no_updates_queued',
                'user' => $userContext->name,
                'queue_count' => 0,
            ]);
            return $event;
        }

        $writer(Level::Notice, "Processing progress '{progress}' for '#{item_id}: {item_title}' from '{user}@{backend}'.", [
            'event_name' => 'progress.item.processing',
            'subsystem' => 'progress',
            'operation' => 'dispatch',
            'outcome' => 'started',
            'item_id' => $item->id,
            'backend' => $item->via,
            'user' => $userContext->name,
            'item_type' => $item->type,
            'item_title' => $item->getName(),
            'progress' => $progress,
            'progress_seconds' => $progressValue / 1000,
        ]);

        $http = Container::get(iHttp::class);
        assert($http instanceof iHttp, 'Expected HTTP client for progress event queue dispatch.');

        send_requests(
            requests: $this->queue->getQueue(),
            client: $http,
            opts: [
                'ok' => static function (Request $request, ResponseInterface $response) use (
                    $eventWriter,
                    $writer,
                    $options,
                    $userContext,
                    $item,
                    $progress,
                ): array {
                    if (true === (bool) ag($request->options, 'user_data.' . Options::NO_LOGGING, false)) {
                        return [];
                    }

                    $context = ag($request->extras, 'context', []);
                    $context['user'] = $userContext->name;
                    $context['item_id'] = ag($context, 'item.id', $item->id);
                    unset($context['id']);
                    $context['progress'] = ag($context, 'item.progress', $progress);
                    $statusCode = $response->getStatusCode();

                    if (true === (bool) ag($options, 'trace')) {
                        $writer(Level::Debug, "Processing progress response for '#{item_id}' from '{user}@{backend}'.", [
                            'event_name' => 'progress.request.response',
                            'subsystem' => 'progress',
                            'operation' => 'dispatch',
                            'outcome' => 'received',
                            'item_id' => $context['item_id'],
                            'http' => [
                                'url' => ag($context, 'remote.url', '??'),
                                'status_code' => $statusCode,
                            ],
                            'headers' => $response->getHeaders(false),
                            'response' => $response->getContent(false),
                            ...$context,
                        ]);
                    }

                    if (false === in_array(Status::tryFrom($statusCode), [Status::OK, Status::NO_CONTENT], true)) {
                        $eventWriter(
                            Level::Error,
                            "Progress update for '#{item_id}' on '{user}@{backend}' failed.",
                            [
                                ...$context,
                                'event_name' => 'progress.request.failed',
                                'subsystem' => 'progress',
                                'operation' => 'dispatch',
                                'outcome' => 'failed',
                                'http' => [
                                    'status_code' => $statusCode,
                                ],
                            ],
                        );

                        return [];
                    }

                    $eventWriter(
                        Level::Notice,
                        "Progress update for '#{item_id}' on '{user}@{backend}' completed.",
                        [
                            ...$context,
                            'event_name' => 'progress.request.completed',
                            'subsystem' => 'progress',
                            'operation' => 'dispatch',
                            'outcome' => 'completed',
                            'http' => [
                                'status_code' => $statusCode,
                            ],
                        ],
                    );

                    return [];
                },
                'error' => static function (Request $request, Throwable $ex) use ($eventWriter, $userContext, $item, $progress): array {
                    if (true === (bool) ag($request->options, 'user_data.' . Options::NO_LOGGING, false)) {
                        return [];
                    }

                    $context = ag($request->extras, 'context', []);

                    $eventWriter(
                        Level::Error,
                        "Progress update for '#{item_id}' on '{user}@{backend}' failed.",
                        [
                            ...$context,
                            'event_name' => 'progress.request.failed',
                            'subsystem' => 'progress',
                            'operation' => 'dispatch',
                            'outcome' => 'failed',
                            'item_id' => ag($context, 'item.id', $item->id),
                            'user' => $userContext->name,
                            'progress' => ag($context, 'item.progress', $progress),
                            ...exception_log($ex),
                        ],
                    );

                    return [];
                },
            ],
        );

        return $event;
    }
}
