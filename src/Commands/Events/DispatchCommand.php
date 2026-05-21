<?php

declare(strict_types=1);

namespace App\Commands\Events;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Events\DataEvent;
use App\Libs\Extends\JsonlFormatter;
use App\Libs\Extends\ProxyHandler;
use App\Libs\Options;
use App\Model\Events\Event;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus as Status;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Psr\EventDispatcher\EventDispatcherInterface as iDispatcher;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Throwable;

use function exception_log;

#[Cli(command: self::ROUTE)]
final class DispatchCommand extends Command
{
    public const string TASK_NAME = 'dispatch';

    public const string ROUTE = 'events:dispatch';

    private const string CACHE_KEY = 'events';

    private const string CACHE_UNLOAD_ATTEMPTS = 'unload_attempts';

    private const int MAX_UNLOAD_ATTEMPTS = 5;

    /**
     * @param iDispatcher&EventDispatcher $dispatcher
     * @param EventsRepository $repo
     * @param iCache $cache
     * @param iLogger $logger
     */
    public function __construct(
        private readonly iDispatcher $dispatcher,
        private readonly EventsRepository $repo,
        private readonly iCache $cache,
        private iLogger $logger,
    ) {
        parent::__construct(null);
    }

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->addOption('id', 'i', InputOption::VALUE_REQUIRED, 'Force run this event.')
            ->addOption('reset', 'r', InputOption::VALUE_NONE, 'Reset event logs.')
            ->addOption('limit', 'L', InputOption::VALUE_REQUIRED, 'How many events to run at per run.', 15)
            ->setDescription('Run queued events.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->unloadEvents();

        register_events();

        $debug = $input->getOption('debug') || Config::get('debug.enabled');
        $visibleLevel = true === (bool) $input->getOption('debug') ? Level::Debug : $this->toLevel($output);

        if (null !== ($id = $input->getOption('id'))) {
            if (null === ($event = $this->repo->findById($id))) {
                $this->logger->error("Event with id '{event_id}' not found.", ['event_id' => $id]);
                return self::FAILURE;
            }

            if ($input->getOption('reset')) {
                $event->logs = [];
            }

            $this->runEvent($event, visibleLevel: $visibleLevel, debug: $debug);

            return self::SUCCESS;
        }

        return $this->runEvents(visibleLevel: $visibleLevel, limit: (int) $input->getOption('limit'), debug: $debug);
    }

    protected function runEvents(Level $visibleLevel, int $limit, bool $debug = false): int
    {
        $repo = $this->repo
            ->setSort(EventsTable::COLUMN_CREATED_AT)
            ->setAscendingOrder()
            ->setPerpage($limit)
            ->findAll([EventsTable::COLUMN_STATUS => Status::PENDING->value]);

        $events = [];
        $now = time();

        foreach ($repo as $event) {
            $delay = ag($event->options, Options::DELAY_BY, 0);
            $created = $event->created_at->getTimestamp() + $delay;

            if ($created > $now) {
                $this->logger->debug(
                    "Event '{event_id}: {event}' is delayed by '{delay}s' seconds. Waiting for '{wait}s' seconds.",
                    [
                        'event_id' => (string) $event->id,
                        'event' => $event->event,
                        'delay' => $delay,
                        'wait' => $created - $now,
                    ],
                );
                continue;
            }

            $events[] = $event;
        }

        if (count($events) < 1) {
            $this->logger->debug('No pending events found.', [
                'event_name' => 'events.dispatch.none_pending',
                'subsystem' => 'events.dispatch',
                'operation' => 'dispatch',
                'outcome' => 'completed',
                'queue_count' => 0,
                'visible_level' => strtolower($visibleLevel->name),
            ]);
            return self::SUCCESS;
        }

        assert($this->dispatcher instanceof EventDispatcher, 'Expected EventDispatcher in dispatch command.');

        foreach ($events as $event) {
            if (null === ($newState = $this->repo->findById($event->id))) {
                $this->logger->notice("The event '{event_id}' was deleted while the dispatcher was running", [
                    'event_id' => (string) $event->id,
                ]);
                continue;
            }

            if ($newState->status !== Status::PENDING) {
                $this->logger->notice(
                    "The event '{event_id}' was changed to '{status}' while the dispatcher was running. Ignoring event.",
                    [
                        'event_id' => (string) $event->id,
                        'status' => $newState->status->name,
                    ],
                );
                continue;
            }

            $this->runEvent($event, visibleLevel: $visibleLevel, debug: $debug);
        }

        return self::SUCCESS;
    }

    private function runEvent(Event $event, Level $visibleLevel, bool $debug = false): void
    {
        $capturedRecords = [];
        $capturedHandlers = null;

        try {
            $startedAt = make_date();
            $logData = [
                'event_id' => (string) $event->id,
                'queued_event' => $event->event,
                'queued_at' => make_date($event->created_at)->format(DATE_ATOM),
                'attempt' => $event->attempts + 1,
                'event_name' => 'events.dispatch.started',
                'subsystem' => 'events.dispatch',
                'operation' => 'dispatch',
                'outcome' => 'started',
            ];

            $event->logs[] = new JsonlFormatter()->formatValues(
                channel: 'event',
                level: Level::Notice,
                message: r("Dispatching queued event '{queued_event}' from {queued_at}.", $logData),
                context: $logData,
            );

            $event->status = Status::RUNNING;
            $event->updated_at = (string) make_date();
            $event->attempts += 1;
            if (true === $debug) {
                $event->options[Options::DEBUG_TRACE] = true;
            }
            $this->repo->save($event);

            $logCount = count($event->logs);
            $ref = new DataEvent($event);
            $capturedHandlers = $this->captureLogger($capturedRecords);

            try {
                $this->dispatcher->dispatch($ref, $event->event);
            } finally {
                $this->restoreLogger($capturedHandlers);
            }

            if (Status::RUNNING !== $ref->getStatus()) {
                $event->status = $ref->getStatus();
            } else {
                $event->status = Status::SUCCESS;
            }

            $event->updated_at = (string) make_date();

            if ($this->isVisible(array_slice($event->logs, $logCount), $visibleLevel)) {
                $this->logger->notice("Dispatching queued event '{queued_event}' from {queued_at}.", $logData);
            }

            $this->replayRecords($capturedHandlers, $capturedRecords);

            $duration = max(0, make_date($event->updated_at)->getTimestamp() - $startedAt->getTimestamp());

            $event->logs[] = new JsonlFormatter()->formatValues(
                channel: 'event',
                level: Level::Notice,
                message: r("Dispatched queued event '{queued_event}' in {duration_seconds}s.", [
                    'queued_event' => $event->event,
                    'duration_seconds' => $duration,
                ]),
                context: [
                    'event_id' => (string) $event->id,
                    'queued_event' => $event->event,
                    'duration_seconds' => $duration,
                    'attempt' => $event->attempts,
                    'status' => strtolower($event->status->name),
                    'event_name' => 'events.dispatch.completed',
                    'subsystem' => 'events.dispatch',
                    'operation' => 'dispatch',
                    'outcome' => 'completed',
                ],
            );

            $this->repo->save($event);
        } catch (Throwable $e) {
            $this->restoreLogger($capturedHandlers);
            $this->replayRecords($capturedHandlers, $capturedRecords);

            $errorLog = "Failed to dispatch queued event '{queued_event}'.";
            $errorContext = [
                'event_id' => (string) $event->id,
                'queued_event' => $event->event,
                'attempt' => $event->attempts,
                'event_name' => 'events.dispatch.failed',
                'subsystem' => 'events.dispatch',
                'operation' => 'dispatch',
                'outcome' => 'failed',
                ...exception_log($e),
            ];

            $event->logs[] = new JsonlFormatter()->formatValues(
                channel: 'event',
                level: Level::Error,
                message: r($errorLog, $errorContext),
                context: $errorContext,
            );
            $event->status = Status::FAILED;
            $event->updated_at = (string) make_date();
            $this->repo->save($event);

            $this->logger->error($errorLog, $errorContext);
        }
    }

    private function captureLogger(array &$records): ?array
    {
        if (false === $this->logger instanceof MonologLogger) {
            return null;
        }

        $handlers = $this->logger->getHandlers();
        $this->logger->setHandlers([
            ProxyHandler::create(static function (string $_message, mixed $record) use (&$records): void {
                if ($record instanceof LogRecord) {
                    $records[] = $record;
                }
            }, Level::Debug),
        ]);

        return $handlers;
    }

    private function restoreLogger(?array $handlers): void
    {
        if (null === $handlers || false === $this->logger instanceof MonologLogger) {
            return;
        }

        $this->logger->setHandlers($handlers);
    }

    private function replayRecords(?array $handlers, array $records): void
    {
        if (null === $handlers) {
            return;
        }

        foreach ($records as $record) {
            if (false === $record instanceof LogRecord) {
                continue;
            }

            foreach ($handlers as $handler) {
                if (false === $handler instanceof HandlerInterface) {
                    continue;
                }

                if (true === $handler->handle($record)) {
                    break;
                }
            }
        }
    }

    private function toLevel(OutputInterface $output): Level
    {
        return match ($output->getVerbosity()) {
            OutputInterface::VERBOSITY_QUIET => Level::Error,
            OutputInterface::VERBOSITY_NORMAL => Level::Warning,
            OutputInterface::VERBOSITY_VERBOSE => Level::Notice,
            OutputInterface::VERBOSITY_VERY_VERBOSE => Level::Info,
            default => Level::Debug,
        };
    }

    private function isVisible(array $logs, Level $visibleLevel): bool
    {
        $count = 0;

        foreach ($logs as $log) {
            $level = $this->eventLevel($log);

            if (null === $level) {
                continue;
            }

            if ($level->value >= $visibleLevel->value) {
                $count++;
            }
        }

        return $count > 0;
    }

    private function eventLevel(mixed $log): ?Level
    {
        if (false === is_string($log)) {
            return null;
        }

        $log = trim($log);

        if ('' === $log) {
            return null;
        }

        if (true === JsonlFormatter::isJsonlRecord($log)) {
            $payload = json_decode($log, true);

            if (true === is_array($payload)) {
                $level = trim(strtoupper((string) ag($payload, 'level', '')));

                if ('' === $level) {
                    return null;
                }

                try {
                    return MonologLogger::toMonologLevel($level);
                } catch (Throwable) {
                    return null;
                }
            }
        }

        $levelRegex = '/^(?:\[[^\]]+]\s*)?(?:[a-z0-9_.-]+\.)?(?<level>EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG):\s*/i';

        if (1 !== preg_match($levelRegex, $log, $matches)) {
            return null;
        }

        try {
            return MonologLogger::toMonologLevel(strtoupper($matches['level']));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * This method will re-queue events that we failed to save, due to database lock issues
     * which is quite common issue as we mainly use sqlite as our database.
     */
    private function unloadEvents(): void
    {
        try {
            $events = $this->cache->get(self::CACHE_KEY, []);
            if (count($events) < 1) {
                return;
            }

            $remaining = [];

            foreach ($events as $eventData) {
                $payload = [
                    'event' => (string) ag($eventData, 'event', ''),
                    'data' => (array) ag($eventData, 'data', []),
                    'opts' => (array) ag($eventData, 'opts', []),
                ];

                try {
                    queue_event($payload['event'], $payload['data'], $payload['opts']);
                    $this->logger->info("Requeued cached event '{queued_event}'.", [
                        'queued_event' => $payload['event'],
                        'attempt' => (int) ag($payload, 'opts.' . self::CACHE_UNLOAD_ATTEMPTS, 0),
                        'event_name' => 'events.cache.requeued',
                        'subsystem' => 'events.cache',
                        'operation' => 'requeue',
                        'outcome' => 'queued',
                    ]);
                } catch (Throwable) {
                    $attempts = max(0, (int) ag($payload, 'opts.' . self::CACHE_UNLOAD_ATTEMPTS, 0)) + 1;
                    $payload['opts'][self::CACHE_UNLOAD_ATTEMPTS] = $attempts;
                    $logContext = [
                        'queued_event' => $payload['event'],
                        'attempt' => $attempts,
                        'event_name' => 'events.cache.dropped',
                        'subsystem' => 'events.cache',
                        'operation' => 'requeue',
                        'outcome' => 'failed',
                    ];

                    if ($attempts < self::MAX_UNLOAD_ATTEMPTS) {
                        $remaining[] = $payload;
                        $this->logger->warning("Failed to requeue cached event '{queued_event}'; will retry.", [
                            ...$logContext,
                            'reason' => 'requeue_failed',
                        ]);
                        continue;
                    }

                    $this->logger->error("Dropped cached event '{queued_event}' after repeated requeue failures.", [
                        ...$logContext,
                        'reason' => 'max_attempts_reached',
                    ]);
                }
            }

            if (count($remaining) > 0) {
                $this->cache->set(self::CACHE_KEY, $remaining);
                return;
            }

            $this->cache->delete(self::CACHE_KEY);
        } catch (Throwable) {
        }
    }
}
