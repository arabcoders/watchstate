<?php

declare(strict_types=1);

namespace App\Commands\Backend\Users;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const string ROUTE = 'backend:users:list';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Get backend users list. [@Removed]')
            ->addOption(
                'with-tokens',
                't',
                InputOption::VALUE_NONE,
                'Include access tokens in response.'
            )
            ->addOption('use-token', 'u', InputOption::VALUE_REQUIRED, 'Use this given token.')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Do not use cache.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend')
            ->setHelp('This command is no longer available, please use the WebUI.')->setHidden(true);
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>This command is no longer available, please use the WebUI.</error>');
        return self::FAILURE;
    }
}
