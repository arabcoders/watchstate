<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

/**
 * Class MaintenanceCommand
 *
 * Runs maintenance tasks on the database.
 */
#[Cli(command: self::ROUTE)]
final class MaintenanceCommand extends Command
{
    public const string ROUTE = 'system:db:maintenance';

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
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Run maintenance tasks on database.')
            ->setHelp(
                <<<HELP

                    This command runs <notice>maintenance</notice> tasks on database to make sure the database is in optimal state.
                    You do not need to run this command unless told by the team.
                    This is done automatically on container startup.

                    HELP,
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
            $output->writeln(r("Optimizing user '{user}' database.", [
                'user' => $userContext->name,
            ]), iOutput::VERBOSITY_VERBOSE);

            $userContext->db->maintenance();
        }

        return self::SUCCESS;
    }
}
