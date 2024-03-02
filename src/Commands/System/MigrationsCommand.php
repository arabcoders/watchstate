<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Database\DatabaseInterface as iDB;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class MigrationsCommand
 *
 * Database migrations runner.
 */
#[Cli(command: self::ROUTE)]
final class MigrationsCommand extends Command
{
    public const ROUTE = 'system:db:migrations';

    /**
     * Class Constructor.
     *
     * @param iDB $db The database connection object.
     *
     */
    public function __construct(private iDB $db)
    {
        parent::__construct();
    }

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Run database migrations.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Start migrations from start.')
            ->setHelp(
                <<<HELP

                This command execute <notice>database schema migrations</notice> to make sure the database is up-to-date.
                You do not need to run this command unless told by the team.
                This is done automatically on container startup.

                HELP
            );
    }

    /**
     * Make sure the command is not running in parallel.
     *
     * @param InputInterface $input The input object containing the command data.
     * @param OutputInterface $output The output object for displaying command output.
     *
     * @return int The exit code of the command execution.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->single(fn(): int => $this->process($input), $output);
    }

    /**
     * Run the command to migrate the database.
     *
     * @param InputInterface $input The input object representing the command inputs.
     *
     * @return int The exit code of the command execution.
     */
    protected function process(InputInterface $input): int
    {
        $opts = [];

        if ($input->getOption('force')) {
            $opts['fresh'] = true;
        }

        return $this->db->migrations(iDB::MIGRATE_UP, $opts);
    }
}
