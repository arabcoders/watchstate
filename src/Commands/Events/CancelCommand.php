<?php

declare(strict_types=1);

namespace App\Commands\Events;

use App\Libs\Attributes\Route\Cli;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class CancelCommand extends AbstractEventCommand
{
    public const string ROUTE = 'events:cancel';

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Cancel an event.')
            ->addArgument('id', InputArgument::REQUIRED, 'Event UUID, short id, or latest')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Cancel without confirmation prompt.')
            ->addOption('clear-logs', null, InputOption::VALUE_NONE, 'Clear logs while cancelling the event.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $id = $this->resolveEventId((string) $input->getArgument('id'), $output);
        if (null === $id) {
            return self::FAILURE;
        }

        if (!(bool) $input->getOption('force')) {
            $confirmed = $this->confirm($input, $output, r('Cancel event [{id}]? [y/N] ', ['id' => $id]));
            if (!$confirmed) {
                $output->writeln('<comment>Aborted.</comment>');
                return self::SUCCESS;
            }
        }

        $response = api_request(Method::PATCH, r('/system/events/{id}', ['id' => $id]), [
            'status' => 4,
            'reset_logs' => (bool) $input->getOption('clear-logs'),
        ]);

        if (Status::OK !== $response->status) {
            return $this->apiError($output, $response);
        }

        $output->writeln(r('<info>Event [{id}] cancelled.</info>', ['id' => $id]));

        return self::SUCCESS;
    }
}
