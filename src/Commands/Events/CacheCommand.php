<?php

declare(strict_types=1);

namespace App\Commands\Events;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class CacheCommand extends Command
{
    public const string ROUTE = 'events:cache';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Force cache invalidation for the events registrar.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        register_events(ignoreCache: true);

        return self::SUCCESS;
    }
}
