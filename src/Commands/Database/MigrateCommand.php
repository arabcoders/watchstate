<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Database\PackageMigrationFactory;
use App\Libs\Database\PdoFactory;
use App\Libs\Exceptions\RuntimeException;
use arabcoders\database\Commands\MigrationRequest;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[Cli(command: self::ROUTE)]
final class MigrateCommand extends Command
{
    public const string ROUTE = 'db:migrate';

    public function __construct(
        private readonly PdoFactory $pdoFactory,
        private readonly PackageMigrationFactory $migrations,
        private readonly iLogger $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Run package database migrations for main and per-user databases.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user. Default all users.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force migration run and bypass safety checks/locks')
            ->addOption('repair', null, InputOption::VALUE_NONE, 'Repair stored migration checksums before running')
            ->addOption('execute', 'x', InputOption::VALUE_NONE, 'Execute migrations')
            ->addOption('steps', null, InputOption::VALUE_OPTIONAL, 'Number of migrations to apply/rollback', 0)
            ->addArgument('direction', InputArgument::OPTIONAL, 'Migration path (up/down).', 'up');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $direction = strtolower((string) $input->getArgument('direction'));
        if (false === in_array($direction, ['up', 'down'], true)) {
            throw new RuntimeException('Only up/down migration path available.');
        }

        try {
            $targets = array_map(fn(string $user): array => [
                'name' => $user,
                'pdo' => $this->pdoFactory->createForFile(
                    'main' === $user ? (string) Config::get('database.file') : get_user_db($user),
                ),
            ], select_users($input->getOption('user')));
        } catch (RuntimeException $e) {
            $output->writeln(r('<error>{message}</error>', [
                'message' => $e->getMessage(),
            ]));

            return self::FAILURE;
        }

        $request = new MigrationRequest(
            direction: $direction,
            dryRun: true !== $input->getOption('execute'),
            steps: (int) $input->getOption('steps'),
            force: (bool) $input->getOption('force'),
            repair: (bool) $input->getOption('repair'),
        );

        foreach ($targets as $target) {
            try {
                $result = $this->migrations->service($target['pdo'])->migrate($request);
            } catch (Throwable $e) {
                $output->writeln(r('<error>{target}: {message}</error>', [
                    'target' => $target['name'],
                    'message' => $e->getMessage(),
                ]));

                return self::FAILURE;
            }

            if (empty($result->migrations)) {
                $output->writeln(r('<comment>{target}: No migration is needed.</comment>', [
                    'target' => $target['name'],
                ]));
                continue;
            }

            $action = true === $request->dryRun ? 'Would apply' : 'Applied';
            if ('down' === $direction) {
                $action = true === $request->dryRun ? 'Would rollback' : 'Rolled back';
            }

            $output->writeln(r('<info>{target}: {action} {count} migration(s).</info>', [
                'target' => $target['name'],
                'action' => $action,
                'count' => count($result->migrations),
            ]));

            if (false === $request->dryRun && 'up' === $direction) {
                ensure_indexes($target['pdo'], $this->logger);
            }

            if (true === $request->dryRun && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                foreach ($this->migrations->service($target['pdo'])->buildDryRunSql($direction, $result->migrations) as $entry) {
                    $output->writeln(r('  <comment>{id} {name}</comment>', [
                        'id' => $entry['id'],
                        'name' => $entry['name'],
                    ]));

                    foreach ($entry['statements'] as $statement) {
                        if ('' === trim($statement)) {
                            continue;
                        }

                        $output->writeln('    ' . $statement . ';');
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
