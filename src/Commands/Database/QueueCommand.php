<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
class QueueCommand extends Command
{
    public const string ROUTE = 'db:queue';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('add', 'a', InputOption::VALUE_REQUIRED, 'Add record id to push queue.')
            ->addOption('remove', 'r', InputOption::VALUE_REQUIRED, 'Remove record id from push queue.')
            ->setDescription('Show webhook queued events. [@Removed]')
            ->setHelp('This command is no longer available, please use the WebUI.')->setHidden(true);
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>This command is no longer available, please use the WebUI.</error>');
        return self::FAILURE;
    }
}
