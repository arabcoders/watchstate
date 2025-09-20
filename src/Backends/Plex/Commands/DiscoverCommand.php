<?php

declare(strict_types=1);

namespace App\Backends\Plex\Commands;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class DiscoverCommand extends Command
{
    public const string ROUTE = 'plex:discover';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Discover servers linked to plex token. [@Removed]')
            ->addOption('with-tokens', 't', InputOption::VALUE_NONE, 'Include access tokens in response.')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addArgument('token', InputArgument::REQUIRED, 'Plex token')
            ->setHelp('No longer available, please use the WebUI.')->setHidden(true);
    }

    protected function runCommand(InputInterface $input, OutputInterface $output, null|array $rerun = null): int
    {
        $output->writeln('<error>This command is no longer available, please use the WebUI.</error>');
        return self::FAILURE;
    }
}
