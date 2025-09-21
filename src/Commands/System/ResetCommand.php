<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

#[Cli(command: self::ROUTE)]
final class ResetCommand extends Command
{
    public const string ROUTE = 'system:reset';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Reset the system state. [@Removed]')
            ->setHelp('No longer available, please use the WebUI.')
            ->setHidden(true);
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $output->writeln('<error>This command is no longer available, please use the WebUI.</error>');
        return self::FAILURE;
    }
}
