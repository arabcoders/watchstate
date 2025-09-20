<?php

declare(strict_types=1);

namespace App\Commands\Backend\Library;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class UnmatchedCommand extends Command
{
    public const string ROUTE = 'backend:library:unmatched';

    private const int CUTOFF = 30;

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Find Item in backend library that does not have external ids. [@Removed]')
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_OPTIONAL,
                'Increase request timeout.',
                Config::get('http.default.options.timeout')
            )
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('cutoff', null, InputOption::VALUE_REQUIRED, 'Increase title cutoff', self::CUTOFF)
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'backend Library id.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->setHelp('This command is no longer available, please use the WebUI.')->setHidden(true);
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>This command is no longer available, please use the WebUI.</error>');
        return self::FAILURE;
    }
}
