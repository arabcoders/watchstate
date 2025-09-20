<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

#[Cli(command: self::ROUTE)]
final class TinkerCommand extends Command
{
    public const string ROUTE = 'system:tinker';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('execute', 'e', InputOption::VALUE_OPTIONAL, 'Execute the given code using Tinker')
            ->addArgument('include', InputArgument::IS_ARRAY, 'Include file(s) before starting tinker')
            ->setDescription('A Interactive shell to manually write scripts. [@Removed]')
            ->setHidden(true);
    }

    protected function execute(iInput $input, iOutput $output): int
    {
        $output->writeln('<error>This command is no longer available</error>');
        return self::FAILURE;
    }
}
