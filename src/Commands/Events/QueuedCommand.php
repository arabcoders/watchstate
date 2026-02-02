<?php

declare(strict_types=1);

namespace App\Commands\Events;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus as Status;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class QueuedCommand extends Command
{
    public const string ROUTE = 'events:queued';

    public function __construct(
        private readonly EventsRepository $repo,
    ) {
        parent::__construct(null);
    }

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Show all.')
            ->setDescription('Show queued events.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filter = [
            EventsTable::COLUMN_STATUS => Status::PENDING->value,
        ];

        if ($input->getOption('all')) {
            $filter = [];
        }

        $events = $this->repo->findAll($filter);

        $mode = $input->getOption('output');

        if ('table' === $mode) {
            $list = [];

            foreach ($events as $event) {
                $list[] = [
                    'id' => $event->id,
                    'event' => $event->event,
                    'added' => $event->created_at,
                    'status' => ucfirst(strtolower($event->status->name)),
                    'Dispatched' => $event->updated_at ?? 'N/A',
                ];
            }

            $keys = $list;
        } else {
            $keys = array_map(static fn($event) => $event->getAll(), $events);
        }

        $this->displayContent($keys, $output, $mode);

        return self::SUCCESS;
    }
}
