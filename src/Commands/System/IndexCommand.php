<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Options;
use App\Libs\Routable;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class IndexCommand
 *
 * This command ensures that the database has correct indexes.
 */
#[Routable(command: self::ROUTE)]
final class IndexCommand extends Command
{
    public const ROUTE = 'system:index';

    public const TASK_NAME = 'indexes';

    /**
     * Class constructor.
     *
     * @param iDB $db An instance of the iDB class.
     */
    public function __construct(private iDB $db)
    {
        parent::__construct();
    }

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Ensure database has correct indexes.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit changes.')
            ->addOption('force-reindex', 'f', InputOption::VALUE_NONE, 'Drop existing indexes, and re-create them.')
            ->setHelp(
                r(
                    <<<HELP

                    This command check the status of your database indexes, and update any missed indexes.
                    You usually should not run command manually as it's run during container startup process.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to recreate the indexes?</question>

                    You can drop the current indexes and rebuild them by using the following command

                    {cmd} <cmd>{route}</cmd> <flag>--force-reindex</flag>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE
                    ]
                )
            );
    }

    /**
     * Run a command.
     *
     * @param InputInterface $input An instance of the InputInterface interface.
     * @param OutputInterface $output An instance of the OutputInterface interface.
     *
     * @return int The status code indicating the success or failure of the command execution.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $this->db->ensureIndex([
            Options::DRY_RUN => (bool)$input->getOption('dry-run'),
            'force-reindex' => (bool)$input->getOption('force-reindex'),
        ]);

        return self::SUCCESS;
    }
}
