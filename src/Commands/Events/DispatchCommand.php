<?php

declare(strict_types=1);

namespace App\Commands\Events;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Events\DataEvent;
use App\Libs\Options;
use App\Model\Events\Event;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus as Status;
use Psr\EventDispatcher\EventDispatcherInterface as iDispatcher;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Throwable;

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
            ->setDescription('Run queued events.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->unloadEvents();

        register_events();

        $debug = $input->getOption('debug') || Config::get('debug.enabled');

        if (null !== ($id = $input->getOption('id'))) {
            if (null === ($event = $this->repo->findById($id))) {
                $this->logger->error(r("Event with id '{id}' not found.", ['id' => $id]));
                return self::FAILURE;
            }

            if ($input->getOption('reset')) {
                $event->logs = [];
            }

            $this->runEvent($event, debug: $debug);

            return self::SUCCESS;
        }

        return $this->runEvents(debug: $debug);
    }

    protected function runEvents(bool $debug = false): int
    {
        $repo = $this->repo
            ->setSort(EventsTable::COLUMN_CREATED_AT)
            ->setAscendingOrder()
            ->findAll([EventsTable::COLUMN_STATUS => Status::PENDING->value]);

        $events = [];
        $now = time();

        foreach ($repo as $event) {
            $delay = ag($event->options, Options::DELAY_BY, 0);
            $created = $event->created_at->getTimestamp() + $delay;

            if ($created > $now) {
                $this->logger->debug(
                    "Event '{id}: {event}' is delayed by '{delay}s' seconds. Waiting for '{wait}s' seconds.",
                    [
                        'id' => $event->id,
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
            if ('-v' === env('WS_CRON_DISPATCH_ARGS', '-v')) {
                $this->logger->debug('No pending queued events found.');
            }
            return self::SUCCESS;
        }

        assert($this->dispatcher instanceof EventDispatcher, 'Expected EventDispatcher in dispatch command.');

        foreach ($events as $event) {
            if (null === ($newState = $this->repo->findById($event->id))) {
                $this->logger->notice("The event '{id}' was deleted while the dispatcher was running", [
                    'id' => $event->id,
                ]);
                continue;
            }

            if ($newState->status !== Status::PENDING) {
                $this->logger->notice(
                    "The event '{id}' was changed to '{status}' while the dispatcher was running. Ignoring event.",
                    [
                        'id' => $event->id,
                        'status' => $newState->status->name,
                    ],
                );
                continue;
            }

            $this->runEvent($event);
        }

        return self::SUCCESS;
    }

    private function runEvent(Event $event, bool $debug = false): void
    {
        try {
            $message = "Dispatching Event: '{event}' queued at '{date}'.";
            $log_data = [
                'event' => $event->event,
                'date' => make_date($event->created_at),
            ];

            $event->logs[] = r($message, $log_data);

            if (count($event->event_data) > 0) {
                $log_data['data'] = $event->event_data;
            }

            $this->logger->info($message, $log_data);

            $event->status = Status::RUNNING;
            $event->updated_at = (string) make_date();
            $event->attempts += 1;
            if (true === $debug) {
                $event->options[Options::DEBUG_TRACE] = true;
            }
            $this->repo->save($event);

            $ref = new DataEvent($event);
            $this->dispatcher->dispatch($ref, $event->event);

            if (Status::RUNNING !== $ref->getStatus()) {
                $event->status = $ref->getStatus();
            } else {
                $event->status = Status::SUCCESS;
            }

            $event->updated_at = (string) make_date();
            $event->logs[] = r("Event '{event}' was dispatched.", ['event' => $event->event]);

            $this->repo->save($event);
        } catch (Throwable $e) {
            $errorLog = r("Failed to dispatch event: '{event}'. {error}", [
                'event' => ag($event, 'event'),
                'error' => $e->getMessage(),
            ]);

            $event->logs[] = $errorLog;
            array_push($event->logs, ...$e->getTrace());
            $event->status = Status::FAILED;
            $event->updated_at = (string) make_date();
            $this->repo->save($event);

            $this->logger->error($errorLog, ['trace' => $e->getTrace()]);
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
                    $this->logger->info("Queued '{event}' event. it was saved to cache due to failure to persist it.", $payload);
                } catch (Throwable) {
                    $attempts = max(0, (int) ag($payload, 'opts.' . self::CACHE_UNLOAD_ATTEMPTS, 0)) + 1;
                    $payload['opts'][self::CACHE_UNLOAD_ATTEMPTS] = $attempts;

                    if ($attempts < self::MAX_UNLOAD_ATTEMPTS) {
                        $remaining[] = $payload;
                        $this->logger->error("Failed to re-queue '{event}' event.", $payload);
                        continue;
                    }

                    $this->logger->error(
                        "Failed to re-queue '{event}' event after '{attempts}' unload attempts. Dropping cached event.",
                        array_replace($payload, ['attempts' => $attempts]),
                    );
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
