<?php

declare(strict_types=1);

namespace App\Commands\Backend\Ignore;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const string ROUTE = 'backend:ignore:list';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user.', 'main')
            ->addOption(
                'select-backend',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Select backend.'
            )
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filter based on type.')
            ->addOption('db', 'd', InputOption::VALUE_REQUIRED, 'Filter based on db.')
            ->addOption('id', 'i', InputOption::VALUE_REQUIRED, 'Filter based on id.')
            ->setDescription('List Ignored external ids. [@Removed]')
            ->setHelp('No longer available, please use the WebUI.')->setHidden(true);
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>This command is no longer available, please use the WebUI.</error>');
        return self::FAILURE;
    }
}
