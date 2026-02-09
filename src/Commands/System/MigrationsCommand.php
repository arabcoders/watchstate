<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

/**
 * Class MigrationsCommand
 *
 * Database migrations runner.
 */
#[Cli(command: self::ROUTE)]
final class MigrationsCommand extends Command
{
    public const string ROUTE = 'system:db:migrations';

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
     * Configures the command.
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Run database migrations.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Start migrations from start.')
            ->setHelp(
                <<<HELP

                    This command execute <notice>database schema migrations</notice> to make sure the database is up-to-date.
                    You do not need to run this command unless told by the team.
                    This is done automatically on container startup.

                    HELP,
            );
    }

    /**
     * Make sure the command is not running in parallel.
     *
     * @param iInput $input The input object containing the command data.
     * @param iOutput $output The output object for displaying command output.
     *
     * @return int The exit code of the command execution.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output);
    }

    /**
     * Run the command to migrate the database.
     *
     * @param iInput $input The input object representing the command inputs.
     *
     * @return int The exit code of the command execution.
     */
    protected function process(iInput $input, iOutput $output): int
    {
        $opts = [];

        if ($input->getOption('force')) {
            $opts['fresh'] = true;
        }

        foreach (get_users_context(mapper: $this->mapper, logger: $this->logger) as $userContext) {
            $output->writeln(r("Running database migrations for '{user}' database.", [
                'user' => $userContext->name,
            ]), iOutput::VERBOSITY_VERBOSE);

            $userContext->db->migrations(iDB::MIGRATE_UP, $opts);
        }

        return self::SUCCESS;
    }
}
