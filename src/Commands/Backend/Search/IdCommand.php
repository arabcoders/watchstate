<?php

declare(strict_types=1);

namespace App\Commands\Backend\Search;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class IdCommand extends Command
{
    public const string ROUTE = 'backend:search:id';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Get backend metadata related to specific id. [@Removed]')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->addArgument('id', InputArgument::REQUIRED, 'Backend item id.')
            ->setHelp('This command is no longer available, please use the WebUI.')->setHidden(true);
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>This command is no longer available, please use the WebUI.</error>');
        return self::FAILURE;
    }
}
