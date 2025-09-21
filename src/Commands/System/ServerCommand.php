<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class ServerCommand extends Command
{
    public const string ROUTE = 'system:server';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Start minimal http server. [@Removed')
            ->addOption('interface', 'i', InputOption::VALUE_REQUIRED, 'Bind to interface.', '0.0.0.0')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Bind to port.', 8080)
            ->addOption('threads', 't', InputOption::VALUE_REQUIRED, 'How many threads to use.', 1)
            ->setHelp('No longer available.')->setHidden(true);
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>This command is no longer available.</error>');
        return self::FAILURE;
    }
}
