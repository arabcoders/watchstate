<?php

declare(strict_types=1);

namespace App\Commands\Backend\Ignore;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

#[Cli(command: self::ROUTE)]
final class ManageCommand extends Command
{
    public const string ROUTE = 'backend:ignore:manage';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Add/remove a ignore rule. [@Removed]')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user.', 'main')
            ->addOption('remove', 'r', InputOption::VALUE_NONE, 'Remove rule from ignore list.')
            ->addArgument('rule', InputArgument::REQUIRED, 'rule')
            ->setHelp('No longer available, please use the WebUI.')->setHidden(true);
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $output->writeln('<error>This command is no longer available, please use the WebUI.</error>');
        return self::FAILURE;
    }
}
