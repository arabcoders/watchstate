<?php

declare(strict_types=1);

namespace App\Commands\Events;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

#[Cli(command: self::ROUTE)]
final class CacheCommand extends Command
{
    public const string ROUTE = 'events:cache';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Force cache invalidation for the events registrar.');
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        register_events(ignoreCache: true);

        return self::SUCCESS;
    }
}
