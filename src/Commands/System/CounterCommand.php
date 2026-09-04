<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

#[Cli(command: self::ROUTE)]
final class CounterCommand extends Command
{
    public const string ROUTE = 'system:counter';

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Test worker output with a timed counter.')
            ->addArgument('target', InputArgument::OPTIONAL, 'Number to count to.', 10);
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $target = filter_var(
            $input->getArgument('target'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (false === $target) {
            $output->writeln('<error>Target must be a positive integer.</error>');
            return self::FAILURE;
        }

        for ($counter = 1; $counter <= $target; $counter++) {
            sleep(1);
            $output->writeln(r('[{counter}/{target}] Counter tick.', [
                'counter' => $counter,
                'target' => $target,
            ]));
        }

        return self::SUCCESS;
    }
}
