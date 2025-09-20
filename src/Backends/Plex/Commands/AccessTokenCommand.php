<?php

declare(strict_types=1);

namespace App\Backends\Plex\Commands;

use App\Command;
use App\Commands\Backend\Users\ListCommand;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class AccessTokenCommand extends Command
{
    public const string ROUTE = 'plex:accesstoken';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Generate Access tokens for plex backend users. [@Removed')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->addArgument(
                'uuid',
                InputArgument::REQUIRED,
                'User UUID as seen via [<cmd>' . ListCommand::ROUTE . '</cmd>] command.'
            )
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('external-user', 'E', InputOption::VALUE_NONE, 'The user is an external user.')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Ignore cache.')
            ->addOption('use-token', 'u', InputOption::VALUE_REQUIRED, 'Override backend token with this one.')
            ->setHelp('No longer available.')->setHidden(true);
    }

    protected function runCommand(InputInterface $input, OutputInterface $output, null|array $rerun = null): int
    {
        $output->writeln('<error>This command is no longer available.</error>');
        return self::FAILURE;
    }
}
