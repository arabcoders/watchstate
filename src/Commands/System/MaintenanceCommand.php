<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Database\DatabaseInterface as iDB;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class MaintenanceCommand
 *
 * Runs maintenance tasks on the database.
 */
#[Cli(command: self::ROUTE)]
final class MaintenanceCommand extends Command
{
    public const ROUTE = 'system:db:maintenance';

    /**
     * Class constructor.
     *
     * @param iDB $db The database connection object.
     */
    public function __construct(private iDB $db)
    {
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Run maintenance tasks on database.')
            ->setHelp(
                <<<HELP

                This command runs <notice>maintenance</notice> tasks on database to make sure the database is in optimal state.
                You do not need to run this command unless told by the team.
                This is done automatically on container startup.

                HELP
            );
    }

    /**
     * Runs the command.
     *
     * @param InputInterface $input The input interface object.
     * @param OutputInterface $output The output interface object.
     *
     * @return int Returns the exit code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $this->db->maintenance();

        return self::SUCCESS;
    }
}
