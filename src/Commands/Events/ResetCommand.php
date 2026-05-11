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
final class ResetCommand extends AbstractEventCommand
{
    public const string ROUTE = 'events:reset';

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Reset an event back to pending.')
            ->addArgument('id', InputArgument::REQUIRED, 'Event UUID, short id, or latest')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Reset without confirmation prompt.')
            ->addOption('keep-logs', null, InputOption::VALUE_NONE, 'Preserve logs instead of clearing them.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $id = $this->resolveEventId((string) $input->getArgument('id'), $output);
        if (null === $id) {
            return self::FAILURE;
        }

        if (!(bool) $input->getOption('force')) {
            $confirmed = $this->confirm($input, $output, r('Reset event [{id}]? [y/N] ', ['id' => $id]));
            if (!$confirmed) {
                $output->writeln('<comment>Aborted.</comment>');
                return self::SUCCESS;
            }
        }

        $response = api_request(Method::PATCH, r('/system/events/{id}', ['id' => $id]), [
            'status' => 0,
            'reset_logs' => false === (bool) $input->getOption('keep-logs'),
        ]);

        if (Status::OK !== $response->status) {
            return $this->apiError($output, $response);
        }

        $output->writeln(r('<info>Event [{id}] reset to pending.</info>', ['id' => $id]));

        return self::SUCCESS;
    }
}
