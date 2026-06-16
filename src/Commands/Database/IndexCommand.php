<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Exceptions\RuntimeException;
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
    public const string ROUTE = 'db:index';

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
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user. Default is all users.')
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
        try {
            $users = select_users($input->getOption('user'));
        } catch (RuntimeException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);

            return self::FAILURE;
        }

        foreach ($users as $user) {
            $userContext = get_user_context($user, $this->mapper, $this->logger);

            $db = $this->ensureDatabase($userContext);

            $this->logger->notice("Ensuring user '{user}' database has correct indexes.", [
                'user' => $userContext->name,
            ]);

            ensure_indexes($db->getDBLayer(), $this->logger, [
                UserContext::class => $userContext,
                Options::DRY_RUN => (bool) $input->getOption('dry-run'),
                'force-reindex' => (bool) $input->getOption('force-reindex'),
            ]);

            if ($input->getOption('force-reindex')) {
                $this->logger->notice("User '{user}' database indexes have been recreated.", [
                    'user' => $userContext->name,
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function ensureDatabase(UserContext $userContext): iDB
    {
        if ('main' === $userContext->name) {
            return ensure_migration((string) Config::get('database.file'));
        }

        return ensure_migration(get_user_db($userContext->name));
    }
}
