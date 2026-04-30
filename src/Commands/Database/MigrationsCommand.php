<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Database\PackageMigrationFactory;
use arabcoders\database\Attributes\Migration as MigrationAttribute;
use arabcoders\database\Commands\MigrationCreator;
use arabcoders\database\Commands\MigrationPreview;
use arabcoders\database\Commands\MigrationService;
use arabcoders\database\Commands\MigrationSquasher;
use arabcoders\database\Connection;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Blueprint\TableBlueprint;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Migration\MigrationStateException;
use arabcoders\database\Schema\Migration\MigrationTemplate;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;
use PDO;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
class MigrationsCommand extends Command
{
    public const string ROUTE = 'db:migrations';

    public function __construct(
        private readonly PDO $pdo,
        private readonly PackageMigrationFactory $migrations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Migrations Management.')
            ->addOption('execute', 'x', InputOption::VALUE_NONE, 'Execute file-changing migration actions')
            ->addOption('create', 'c', InputOption::VALUE_REQUIRED, 'Create a new db migration')
            ->addOption('autogen', 'a', InputOption::VALUE_OPTIONAL, 'Autogenerate a db migration from models')
            ->addOption('drop-orphans', null, InputOption::VALUE_NONE, 'Allow dropping tables not mapped by models')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List db migrations')
            ->addOption('skip', null, InputOption::VALUE_REQUIRED, 'Mark migrations up to token as applied')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force migration actions and bypass safety checks/locks')
            ->addOption('repair', null, InputOption::VALUE_NONE, 'Repair stored migration checksums while running migration actions')
            ->addOption(
                'namespace',
                null,
                InputOption::VALUE_REQUIRED,
                'Namespace for generated migrations',
                'App\\Migration',
            )
            ->addOption(
                'attribute',
                null,
                InputOption::VALUE_REQUIRED,
                'Migration attribute class',
                MigrationAttribute::class,
            )
            ->addOption(
                'base',
                null,
                InputOption::VALUE_REQUIRED,
                'Base migration class',
                SchemaBlueprintMigration::class,
            )
            ->addOption(
                'connection',
                null,
                InputOption::VALUE_REQUIRED,
                'Connection class',
                Connection::class,
            )
            ->addOption(
                'blueprint',
                null,
                InputOption::VALUE_REQUIRED,
                'Blueprint class',
                Blueprint::class,
            )
            ->addOption(
                'table-blueprint',
                null,
                InputOption::VALUE_REQUIRED,
                'Table blueprint class',
                TableBlueprint::class,
            )
            ->addOption(
                'column-type',
                null,
                InputOption::VALUE_REQUIRED,
                'Column type enum class',
                ColumnType::class,
            )
            ->addOption(
                'use',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Extra use statements to include',
            )
            ->addOption(
                'squash',
                null,
                InputOption::VALUE_REQUIRED,
                'Squash migrations from token into latest migration (dry-run unless --execute is provided)',
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $create = $input->getOption('create');
        if (true === is_string($create) && '' !== trim($create)) {
            return $this->handleCreate($create, $input, $output);
        }

        if ($input->hasParameterOption(['--autogen', '-a'])) {
            $name = $input->getOption('autogen');
            $name = true === is_string($name) && '' !== trim($name) ? $name : 'schema';

            return $this->handleAutogen($name, $input, $output);
        }

        $service = $this->migrations->service($this->pdo, $this->migrationDirectory());

        $skip = $input->getOption('skip');
        if (true === is_string($skip) && '' !== trim($skip)) {
            return $this->handleSkip($service, $skip, $input, $output);
        }

        if (true === (bool) $input->getOption('list')) {
            return $this->handleList($service, $output);
        }

        $squash = $input->getOption('squash');
        if (true === is_string($squash) && '' !== trim($squash)) {
            return $this->handleSquash($squash, $input, $output);
        }

        return self::SUCCESS;
    }

    protected function migrationDirectory(): string
    {
        return $this->migrations->migrationDirectory();
    }

    private function handleCreate(string $name, InputInterface $input, OutputInterface $output): int
    {
        $creator = new MigrationCreator($this->migrationDirectory(), $this->buildTemplate($input));
        $draft = $creator->createBlank($name);
        $creator->persist($draft);

        $output->writeln(r('<info>Created migration {file}</info>', [
            'file' => after($draft->filePath, ROOT_PATH . '/'),
        ]));

        return self::SUCCESS;
    }

    private function handleAutogen(string $name, InputInterface $input, OutputInterface $output): int
    {
        $creator = new MigrationCreator($this->migrationDirectory(), $this->buildTemplate($input));

        try {
            $this->guardPendingMigrations();
        } catch (RuntimeException $e) {
            if (400 === $e->getCode()) {
                $output->writeln(r('<error>{message}</error>', [
                    'message' => $e->getMessage(),
                ]));

                return self::FAILURE;
            }

            throw $e;
        }

        try {
            $result = $creator->createAutogenWithOptions(
                name: $name,
                pdo: $this->pdo,
                modelPaths: $this->migrations->modelPaths(),
                options: $this->migrations->autogenOptions(
                    ignoreTables: ['migration_version', 'migration_lock'],
                    dropOrphans: (bool) $input->getOption('drop-orphans'),
                    dryRun: $this->isDryRun($input),
                ),
            );
        } catch (RuntimeException $e) {
            if (400 === $e->getCode()) {
                $output->writeln(r('<comment>{message}</comment>', [
                    'message' => $e->getMessage(),
                ]));

                return self::SUCCESS;
            }

            throw $e;
        }

        if ($result instanceof MigrationPreview) {
            $this->renderPreview($result, $output);

            return self::SUCCESS;
        }

        $creator->persist($result);
        $output->writeln(r('<info>Created migration {file}</info>', [
            'file' => after($result->filePath, ROOT_PATH . '/'),
        ]));

        return self::SUCCESS;
    }

    private function handleSkip(
        MigrationService $service,
        #[\SensitiveParameter]
        string $token,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $dryRun = $this->isDryRun($input);

        try {
            $result = $service->skipUpTo(
                $token,
                $dryRun,
                (bool) $input->getOption('force'),
                (bool) $input->getOption('repair'),
            );
        } catch (MigrationStateException $e) {
            $output->writeln(r('<error>{message}</error>', [
                'message' => $e->getMessage(),
            ]));

            return self::FAILURE;
        }

        if (empty($result->migrations)) {
            $output->writeln('<comment>No migrations found.</comment>');

            return self::SUCCESS;
        }

        $action = true === $dryRun ? 'Would mark' : 'Marked';
        $output->writeln(r('<info>{action} {count} migration(s).</info>', [
            'action' => $action,
            'count' => count($result->migrations),
        ]));

        return self::SUCCESS;
    }

    private function handleList(MigrationService $service, OutputInterface $output): int
    {
        $result = $service->list();

        if (empty($result->migrations)) {
            $output->writeln('<comment>No migrations found.</comment>');

            return self::SUCCESS;
        }

        if (!empty($result->lock['locked'])) {
            $output->writeln(r('<comment>Lock active by {holder}</comment>', [
                'holder' => (string) ($result->lock['holder'] ?? 'unknown'),
            ]));
        }

        foreach ($result->migrations as $migration) {
            $status = true === (bool) $migration['applied'] ? '[x]' : '[ ]';
            $line = r('{status} {id} {name}', [
                'status' => $status,
                'id' => $migration['id'],
                'name' => $migration['name'],
            ]);

            if (isset($migration['checksum_matches']) && false === $migration['checksum_matches']) {
                $line .= ' <error>(checksum mismatch)</error>';
            }

            $output->writeln($line);
        }

        return self::SUCCESS;
    }

    private function handleSquash(
        #[\SensitiveParameter]
        string $token,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $apply = (bool) $input->getOption('execute');
        $squasher = new MigrationSquasher($this->migrationDirectory());

        try {
            $result = $squasher->squash($token, $apply);
        } catch (RuntimeException $e) {
            $output->writeln(r('<error>{message}</error>', [
                'message' => $e->getMessage(),
            ]));

            return self::FAILURE;
        }

        $output->writeln(r('<info>Squashed {start}..{end} into {file}</info>', [
            'start' => $result['start'],
            'end' => $result['end'],
            'file' => after($result['latestFile'], ROOT_PATH . '/'),
        ]));

        if (true === $apply && !empty($result['deletedFiles'])) {
            foreach ($result['deletedFiles'] as $file) {
                $output->writeln(r('  <comment>Removed {file}</comment>', [
                    'file' => after($file, ROOT_PATH . '/'),
                ]));
            }

            return self::SUCCESS;
        }

        $output->writeln('<info>Squash preview (merged migration contents):</info>');
        $output->writeln($result['newContents']);
        $output->writeln('<comment>Dry-run: no files were modified. Re-run with --execute to make changes.</comment>');

        return self::SUCCESS;
    }

    private function buildTemplate(InputInterface $input): MigrationTemplate
    {
        $uses = $input->getOption('use');
        $extraUses = [];

        if (true === is_array($uses)) {
            foreach ($uses as $use) {
                if (false === is_string($use) || '' === trim($use)) {
                    continue;
                }

                $extraUses[] = $use;
            }
        }

        return new MigrationTemplate(
            namespace: (string) $input->getOption('namespace'),
            migrationAttributeClass: (string) $input->getOption('attribute'),
            baseMigrationClass: (string) $input->getOption('base'),
            connectionClass: (string) $input->getOption('connection'),
            blueprintClass: (string) $input->getOption('blueprint'),
            tableBlueprintClass: (string) $input->getOption('table-blueprint'),
            columnTypeClass: (string) $input->getOption('column-type'),
            extraUses: $extraUses,
        );
    }

    private function isDryRun(InputInterface $input): bool
    {
        return true !== $input->getOption('execute');
    }

    private function guardPendingMigrations(): void
    {
        foreach ($this->migrations->service($this->pdo, $this->migrationDirectory())->list()->migrations as $migration) {
            if (!empty($migration['applied'])) {
                continue;
            }

            throw new RuntimeException('Pending migrations exist. Apply them before autogen.', 400);
        }
    }

    private function renderPreview(MigrationPreview $preview, OutputInterface $output): void
    {
        $output->writeln('<info>Up SQL:</info>');
        foreach ($preview->up as $statement) {
            if ('' === trim($statement)) {
                continue;
            }

            $output->writeln('  ' . $statement . ';');
        }

        if (empty($preview->down)) {
            return;
        }

        $output->writeln('<info>Down SQL:</info>');
        foreach ($preview->down as $statement) {
            if ('' === trim($statement)) {
                continue;
            }

            $output->writeln('  ' . $statement . ';');
        }
    }
}
