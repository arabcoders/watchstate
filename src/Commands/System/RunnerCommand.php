<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Monolog\Level;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Throwable;

/**
 * Class TasksCommand
 *
 * Automates the runs of scheduled tasks.
 */
#[Cli(command: self::ROUTE)]
final class RunnerCommand extends Command
{
    public const string ROUTE = 'system:runner';

    public function __construct(private iLogger $logger)
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Daemon to run scheduled tasks.');
    }

    /**
     * Make sure the command is not running in parallel.
     *
     * @param iInput $input The input interface object.
     * @param iOutput $output The output interface object.
     *
     * @return int The status code of the command execution.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        return $this->single(function () use ($output) {
            try {
                while (true) {
                    $output->writeln('Sleeping', iOutput::VERBOSITY_DEBUG);
                    sleep(60);
                    $output->writeln('Running tasks', iOutput::VERBOSITY_DEBUG);
                    $out = RunCommand(TasksCommand::ROUTE, ['--run', '--save-log'], asArray: true, opts: [
                        'timeout' => 3600 * 4,
                    ]);
                    foreach ($out as $line) {
                        $output->writeln($line);
                    }
                }
            } catch (Throwable $e) {
                fwrite(STDERR, $e->getMessage());
            }

            return self::SUCCESS;
        }, $output, [iLogger::class => $this->logger, Level::class => Level::Error]);
    }
}
