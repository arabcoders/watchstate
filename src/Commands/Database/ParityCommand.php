<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class ParityCommand extends Command
{
    public const string ROUTE = 'db:parity';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Inspect database records for conflicting records. [@Removed]')
            ->addOption('min', 'm', InputOption::VALUE_OPTIONAL, 'min backends', 2)
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit returned results.', 1000)
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Limit returned results.', 1)
            ->addOption('prune', 'd', InputOption::VALUE_NONE, 'Remove all matching records from db.')
            ->setHelp('This command is no longer available, please use the WebUI.')->setHidden(true);
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>This command is no longer available, please use the WebUI.</error>');
        return self::FAILURE;
    }
}
