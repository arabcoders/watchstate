<?php

declare(strict_types=1);

namespace App\Commands\Events;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Events\DataEvent;
use App\Model\Events\Event;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus as Status;
use Psr\EventDispatcher\EventDispatcherInterface as iDispatcher;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Throwable;

#[Cli(command: self::ROUTE)]
final class DispatchCommand extends Command
{
    public const string TASK_NAME = 'Dispatch';

    public const string ROUTE = 'events:dispatch';

    public function __construct(
        private readonly iDispatcher $dispatcher,
        private readonly EventsRepository $repo,
        private iLogger $logger,
    ) {
        parent::__construct(null);
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('id', 'i', InputOption::VALUE_REQUIRED, 'Force run this event.')
            ->addOption('reset', 'r', InputOption::VALUE_NONE, 'Reset event logs.')
            ->setDescription('Run queued events.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        registerEvents();

        $id = $input->getOption('id');
        if (null !== $id) {
            if (null === ($event = $this->repo->findById($id))) {
                $this->logger->error(r("Event with id '{id}' not found.", ['id' => $id]));
                return self::FAILURE;
            }

            if ($input->getOption('reset')) {
                $event->logs = [];
            }

            $this->runEvent($event);

            return self::SUCCESS;
        }

        return $this->runEvents();
    }

    protected function runEvents(): int
    {
        $events = $this->repo->findAll([EventsTable::COLUMN_STATUS => Status::PENDING->value]);
        if (count($events) < 1) {
            $this->logger->debug('No pending queued events found.');
            return self::SUCCESS;
        }

        assert($this->dispatcher instanceof EventDispatcher);

        foreach ($events as $event) {
            if (null === ($newState = $this->repo->findOne($event->id))) {
                $this->logger->notice("The event '{id}' was deleted while the dispatcher was running", [
                    'id' => $event->id
                ]);
                continue;
            }

            if ($newState->status !== Status::PENDING) {
                $this->logger->notice(
                    "The event '{id}' was changed to '{status}' while the dispatcher was running. Ignoring event.",
                    [
                        'id' => $event->id,
                        'status' => $newState->status->name,
                    ]
                );
                continue;
            }

            $this->runEvent($event);
        }

        return self::SUCCESS;
    }

    private function runEvent(Event $event): void
    {
        try {
            $message = "Dispatching Event: '{event}' queued at '{date}'.";
            $log_data = [
                'event' => $event->event,
                'date' => makeDate($event->created_at),
            ];

            $event->logs[] = r($message, $log_data);

            if (count($event->event_data) > 0) {
                $log_data['data'] = $event->event_data;
            }

            $this->logger->info($message, $log_data);

            $event->status = Status::RUNNING;
            $event->updated_at = (string)makeDate();
            $event->attempts += 1;
            $this->repo->save($event);

            $ref = new DataEvent($event);
            $this->dispatcher->dispatch($ref, $event->event);

            $event->status = Status::SUCCESS;
            $event->updated_at = (string)makeDate();
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
            $event->updated_at = (string)makeDate();
            $this->repo->save($event);

            $this->logger->error($errorLog);
        }
    }
}
