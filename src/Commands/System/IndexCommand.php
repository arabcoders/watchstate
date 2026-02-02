<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\UserContext;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

/**
 * Class IndexCommand
 *
 * This command ensures that the database has correct indexes.
 */
#[Cli(command: self::ROUTE)]
final class IndexCommand extends Command
{
    public const string ROUTE = 'system:index';

    public const string TASK_NAME = 'indexes';

    /**
     * Class constructor.
     *
     * @param iImport $mapper
     * @param iLogger $logger
     */
    public function __construct(
        #[Inject(DirectMapper::class)]
        private readonly iImport $mapper,
        private readonly iLogger $logger,
    ) {
        parent::__construct();
    }

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
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
                        'cmd' => trim(command_context()),
                        'route' => self::ROUTE,
                    ],
                ),
            );
    }

    /**
     * Run a command.
     *
     * @param iInput $input An instance of the InputInterface interface.
     * @param iOutput $output An instance of the OutputInterface interface.
     *
     * @return int The status code indicating the success or failure of the command execution.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        foreach (get_users_context(mapper: $this->mapper, logger: $this->logger) as $userContext) {
            $output->writeln(r("Ensuring user '{user}' database has correct indexes.", [
                'user' => $userContext->name,
            ]), iOutput::VERBOSITY_VERBOSE);

            $userContext->db->ensureIndex([
                UserContext::class => $userContext,
                Options::DRY_RUN => (bool) $input->getOption('dry-run'),
                'force-reindex' => (bool) $input->getOption('force-reindex'),
            ]);

            if ($input->getOption('force-reindex')) {
                $output->writeln(r("User '{user}' Database Indexes have been recreated successfully.", [
                    'user' => $userContext->name,
                ]));
            }
        }

        return self::SUCCESS;
    }
}
