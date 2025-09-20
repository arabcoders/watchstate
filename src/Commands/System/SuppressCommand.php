<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

#[Cli(command: self::ROUTE)]
final class SuppressCommand extends Command
{
    public const string ROUTE = 'system:suppress';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Log message Suppressor controller. [@Removed]')
            ->addOption('add', 'a', InputOption::VALUE_NONE, 'Add suppression rule.')
            ->addOption('edit', 'e', InputOption::VALUE_REQUIRED, 'Edit suppression rule.')
            ->addOption('delete', 'd', InputOption::VALUE_REQUIRED, 'Delete Suppression rule.')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Suppression rule type.')
            ->addOption('rule', 'r', InputOption::VALUE_REQUIRED, 'Suppression rule.')
            ->addOption('example', 'x', InputOption::VALUE_REQUIRED, 'Suppression rule example.')
            ->setHelp('No longer available, please use the WebUI.')->setHidden(true);
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $output->writeln('<error>This command is no longer available, please use the WebUI.</error>');
        return self::FAILURE;
    }
}
