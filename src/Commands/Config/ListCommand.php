<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use DateTimeInterface;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

#[Cli(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const string ROUTE = 'config:list';

    public function __construct(
        #[Inject(DirectMapper::class)]
        private iImport $mapper,
        private iLogger $logger
    ) {
        parent::__construct();
    }

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('List user backends.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user.', '')
            ->setHelp('This command list your configured backends.');
    }

    /**
     * Runs the command.
     *
     * @param iInput $input The input interface.
     * @param iOutput $output The output interface.
     *
     * @return int The command exit code.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        $user = $input->getOption('user');

        try {
            $usersContext = getUsersContext(mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $e) {
            $output->writeln(r('<error>ERROR:</error> {message}', [
                'user' => $user,
                'message' => $e->getMessage(),
            ]));
            return self::FAILURE;
        }

        if ('' !== ($user = $input->getOption('user'))) {
            $usersContext = array_filter($usersContext, fn($k) => $k === $user, ARRAY_FILTER_USE_KEY);
            if (count($usersContext) < 1) {
                $output->writeln(r('<error>ERROR:</error> {message}', [
                    'message' => r("User '{user}' not found.", ['user' => $user]),
                ]));
                return self::FAILURE;
            }
        }

        $list = [];

        foreach ($usersContext as $username => $userContext) {
            foreach ($userContext->config->getAll() as $name => $backend) {
                $import = 'Disabled';

                if (true === (bool)ag($backend, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                    $import = 'Metadata only';
                }

                if (true === (bool)ag($backend, 'import.enabled')) {
                    $import = 'Play state & Metadata';
                }

                $importLastRun = ($date = ag($backend, 'import.lastSync')) ? makeDate($date) : 'No record';
                $exportLastRun = ($date = ag($backend, 'export.lastSync')) ? makeDate($date) : 'No record';

                $list[] = [
                    'User' => $username,
                    'Name' => $name,
                    'Type' => ucfirst(ag($backend, 'type')),
                    'Import' => $import,
                    'Export' => true === (bool)ag($backend, 'export.enabled') ? 'Enabled' : 'Disabled',
                    'LastImportDate' => ($importLastRun instanceof DateTimeInterface) ? $importLastRun->format(
                        'Y-m-d H:i:s T'
                    ) : $importLastRun,
                    'LastExportDate' => ($exportLastRun instanceof DateTimeInterface) ? $exportLastRun->format(
                        'Y-m-d H:i:s T'
                    ) : $exportLastRun,
                ];
            }
        }

        $this->displayContent($list, $output, $input->getOption('output'));

        return self::SUCCESS;
    }
}
