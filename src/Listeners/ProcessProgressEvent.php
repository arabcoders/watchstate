<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Libs\Attributes\DI\Inject;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\libs\Events\DataEvent;
use App\Libs\Exceptions\Backends\NotImplementedException;
use App\Libs\Exceptions\Backends\UnexpectedVersionException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\UserContext;
use App\Model\Events\EventListener;
use Monolog\Level;
use Psr\Log\LoggerInterface as iLogger;
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
            $event->addLog($level->getName() . ': ' . r($message, $context));
            $this->logger->log($level, $message, $context);
        };

        $event->stopPropagation();

        $this->queue->reset();

        $user = ag($event->getOptions(), Options::CONTEXT_USER, 'main');

        try {
            $userContext = get_user_context(user: $user, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $ex) {
            $writer(Level::Error, $ex->getMessage());
            return $event;
        }

        $options = $event->getOptions();

        if (null === ($item = $userContext->db->get(Container::get(iState::class)::fromArray($event->getData())))) {
            $writer(Level::Error, "'{user}' item '{id}' is not referenced locally yet.", [
                'user' => $userContext->name,
                'id' => ag($event->getData(), 'id', '?'),
            ]);
            return $event;
        }

        if ($item->isWatched()) {
            $allowUpdate = (int) Config::get('progress.threshold', 0);
            $minThreshold = (int) Config::get('progress.minThreshold', 86_400);
            if (false === ($allowUpdate >= $minThreshold && time() > ($item->updated + $allowUpdate))) {
                $writer(
                    level: Level::Info,
                    message: "'{user}' item - '#{id}: {title}' is marked as watched. Not updating watch progress. {comp}",
                    context: [
                        'id' => $item->id,
                        'title' => $item->getName(),
                        'user' => $userContext->name,
                        'comp' => $allowUpdate > $minThreshold
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

        if (false === $item->hasPlayProgress()) {
            $writer(Level::Info, "'{user}' item '#{id}: {title}' has no watch progress to export.", [
                'id' => $item->id,
                'title' => $item->title,
                'user' => $userContext->name,
            ]);
            return $event;
        }

        $progress = format_duration($item->getPlayProgress());

        $list = [];

        $supported = Config::get('supported', []);

        foreach ($userContext->config->getAll() as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if (true !== (bool) ag($backend, 'export.enabled')) {
                $writer(Level::Notice, "Ignoring '{user}@{backend}'. Export is disabled.", [
                    'backend' => $backendName,
                    'user' => $userContext->name,
                ]);
                continue;
            }

            if (!isset($supported[$type])) {
                $writer(Level::Error, "Ignoring '{user}@{backend}'. Invalid type '{type}'.", [
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
                $writer(Level::Error, "Ignoring '{user}@{backend}'. Invalid URL '{url}'.", [
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
            $writer(Level::Warning, 'There are no backends to send the events to.');
            return $event;
        }

        foreach ($list as $name => &$backend) {
            try {
                $opts = ag($backend, 'options', []);

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
                    UserContext::class => $userContext,
                ]);
                $backend['class']->progress(entities: [$item->id => $item], queue: $this->queue);
            } catch (UnexpectedVersionException|NotImplementedException $ex) {
                $writer(
                    Level::Notice,
                    "This feature is not available for '{user}@{backend}'. '{error.message}' at '{error.file}:{error.line}'.",
                    [
                        'user' => $userContext->name,
                        'backend' => $name,
                        ...exception_log($ex),
                    ],
                );
            } catch (Throwable $ex) {
                $writer(
                    Level::Error,
                    "Exception '{error.kind}' was thrown unhandled during '{user}@{backend}' request to sync '#{id}: {title}' progress. '{error.message}' at '{error.file}:{error.line}'.",
                    [
                        'id' => $item->id,
                        'backend' => $name,
                        'title' => $item->getName(),
                        'user' => $userContext->name,
                        ...exception_log($ex),
                    ],
                );
            }
        }

        unset($backend);

        if (count($this->queue) < 1) {
            $writer(Level::Notice, "Backend clients didn't queue items to be updated.");
            return $event;
        }

        $writer(Level::Notice, "Processing '{user}@{backend}' - '#{id}: {title}' watch progress '{progress}' event.", [
            'id' => $item->id,
            'backend' => $item->via,
            'user' => $userContext->name,
            'title' => $item->getName(),
            'progress' => $progress,
        ]);

        foreach ($this->queue->getQueue() as $response) {
            $context = ag($response->getInfo('user_data'), 'context', []);
            $context['user'] = $userContext->name;
            $context['id'] = ag($context, 'item.id', $item->id);
            $context['progress'] = ag($context, 'item.progress', $progress);

            try {
                $statusCode = $response->getStatusCode();

                if (true === (bool) ag($options, 'trace')) {
                    $writer(Level::Debug, "Processing '{user}@{backend}' - '#{id}: {item.title}' response.", [
                        'id' => $context['id'],
                        'url' => ag($context, 'remote.url', '??'),
                        'status_code' => $statusCode,
                        'headers' => $response->getHeaders(false),
                        'response' => $response->getContent(false),
                        ...$context,
                    ]);
                }

                if (
                    false === in_array(
                        Status::tryFrom($statusCode),
                        [Status::OK, Status::NO_CONTENT],
                        true,
                    )
                ) {
                    $writer(
                        level: Level::Error,
                        message: "Request to change '{user}@{backend}' - '#{id}: {item.title}' watch progress returned with unexpected '{status_code}' status code.",
                        context: [
                            ...$context,
                            'id' => $context['id'],
                            'status_code' => $statusCode,
                        ],
                    );
                    continue;
                }

                $writer(
                    level: Level::Notice,
                    message: "Updated '{user}@{backend}' '#{id}: {item.title}' watch progress to '{progress}'.",
                    context: [
                        ...$context,
                        'id' => $context['id'],
                        'progress' => $context['progress'],
                        'status_code' => $statusCode,
                    ],
                );
            } catch (Throwable $ex) {
                $writer(
                    level: Level::Error,
                    message: "Exception '{error.kind}' was thrown unhandled during '{user}@{backend}' request to change watch progress of {item.type} '#{id}: {item.title}'. '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'id' => $item->id,
                        ...exception_log($ex),
                        ...$context,
                    ],
                );
            }
        }

        return $event;
    }
}
