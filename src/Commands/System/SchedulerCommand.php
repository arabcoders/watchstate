<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Stream;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Throwable;

/**
 * Class TasksCommand
 *
 * Automates the runs of scheduled tasks.
 */
#[Cli(command: self::ROUTE)]
final class SchedulerCommand extends Command
{
    public const string ROUTE = 'system:scheduler';

    private string $pidFile = '/tmp/ws-job-runner.pid';

    public function __construct()
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
        $this
            ->setName(self::ROUTE)
            ->addOption('pid-file', 'p', InputOption::VALUE_REQUIRED, 'PID file.', $this->pidFile)
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
        $pidFile = $input->getOption('pid-file');
        $info = is_scheduler_running($pidFile);

        if (true === $info['status']) {
            $output->writeln('Scheduler is already running with PID: ' . $info['pid'], iOutput::VERBOSITY_NORMAL);
            return self::SUCCESS;
        }

        try {
            $stream = Stream::make($pidFile, 'w+');
            $stream->write((string) getmypid());
            $stream->close();

            while (true) {
                $output->writeln('Sleeping', iOutput::VERBOSITY_DEBUG);
                sleep(60);

                $output->writeln('Running tasks', iOutput::VERBOSITY_DEBUG);
                $out = run_command(TasksCommand::ROUTE, ['--run', '--save-log'], asArray: true, opts: [
                    'timeout' => 3600 * 4,
                ]);

                foreach ($out as $line) {
                    $output->writeln($line, iOutput::VERBOSITY_DEBUG);
                }
            }
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage());
        } finally {
            if (file_exists($pidFile)) {
                unlink($pidFile);
            }
        }

        return self::SUCCESS;
    }
}
