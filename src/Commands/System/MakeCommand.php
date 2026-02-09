<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Database\DatabaseInterface as iDB;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class MakeCommand
 *
 * This class represents a command to create a database schema migration file.
 */
#[Cli(command: self::ROUTE)]
final class MakeCommand extends Command
{
    public const string ROUTE = 'system:db:make';

    /**
     * Class Constructor.
     *
     * @param iDB $db The iDB object used for database operations.
     */
    public function __construct(
        private iDB $db,
    ) {
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Create database schema migration file.')
            ->addArgument('filename', InputArgument::REQUIRED, 'Migration name.')
            ->setHelp(
                r(
                    <<<HELP

                        This command creates a <notice>migration file</notice> for database schema.
                        This is mostly used for people who develop features for this tool.

                        By default, migration files stored at [<value>{migrationPath}</value>].

                        The migration file name must be in [<value>in_english</value>] without spaces and in lower case.

                        HELP,
                    [
                        'migrationPath' => after(realpath(__DIR__ . '/../../../migrations'), ROOT_PATH),
                    ],
                ),
            );
    }

    /**
     * Executes a command.
     *
     * @param InputInterface $input The input object containing command arguments and options.
     * @param OutputInterface $output The output object used for displaying messages.
     *
     * @return int The exit code of the command execution. Returns "SUCCESS" constant value.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $file = $this->db->makeMigration($input->getArgument('filename'));

        $output->writeln(r(text: "<info>Created new migration file at '{file}'.</info>", context: [
            'file' => after(realpath($file), ROOT_PATH),
        ]));

        return self::SUCCESS;
    }
}
