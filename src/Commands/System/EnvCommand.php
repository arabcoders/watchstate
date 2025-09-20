<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

#[Cli(command: self::ROUTE)]
final class EnvCommand extends Command
{
    public const string ROUTE = 'system:env';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Manage Environment Variables. [@Removed]')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Key to update.')
            ->addOption('set', 'e', InputOption::VALUE_REQUIRED, 'Value to set.')
            ->addOption('delete', 'd', InputOption::VALUE_NONE, 'Delete key.')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List All Supported keys.')
            ->addOption('expose', 'x', InputOption::VALUE_NONE, 'Expose Hidden values.')
            ->setHidden(true);
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $output->writeln('<error>This command is no longer available, please use the WebUI.</error>');
        return self::FAILURE;
    }
}
