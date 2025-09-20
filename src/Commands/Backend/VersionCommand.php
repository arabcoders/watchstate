<?php

declare(strict_types=1);

namespace App\Commands\Backend;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
class VersionCommand extends Command
{
    public const string ROUTE = 'backend:version';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Get backend product version. [@Removed]')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->setHelp('No longer available, please use the WebUI.')->setHidden(true);
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>This command is no longer available, please use the WebUI.</error>');
        return self::FAILURE;
    }
}
