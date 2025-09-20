<?php

declare(strict_types=1);

namespace App\Backends\Plex\Commands;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

#[Cli(command: self::ROUTE)]
final class CheckTokenCommand extends Command
{
    public const string ROUTE = 'plex:check_token';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Check if given plex token is valid. [@Removed]')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addArgument('token', InputArgument::REQUIRED, 'Plex token')
            ->setHelp('No longer available, please use the WebUI.')->setHidden(true);
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $output->writeln('<error>This command is no longer available, please use the WebUI.</error>');
        return self::FAILURE;
    }
}
