<?php

declare(strict_types=1);

namespace App\Commands\Backend\Library;

use App\API\Backend\Mismatched;
use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class MismatchCommand extends Command
{
    public const string ROUTE = 'backend:library:mismatch';
    private const int CUTOFF = 50;

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Find possible mis-matched item in a libraries. [@Removed]')
            ->addOption(
                'percentage',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Acceptable percentage.',
                Mismatched::DEFAULT_PERCENT
            )
            ->addOption(
                'method',
                'm',
                InputOption::VALUE_OPTIONAL,
                r('Comparison method. Can be [{list}].', ['list' => implode(', ', Mismatched::METHODS)]),
                Mismatched::METHODS[0]
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_OPTIONAL,
                'Request timeout in seconds.',
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
