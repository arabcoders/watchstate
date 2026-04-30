<?php

declare(strict_types=1);

namespace App\Libs\Database;

use App\Libs\Database\StateIndexSchema;
use arabcoders\database\Commands\MigrationAutogenOptions;
use arabcoders\database\Commands\MigrationOperationResult;
use arabcoders\database\Commands\MigrationProbeResult;
use arabcoders\database\Commands\MigrationRequest;
use arabcoders\database\Commands\MigrationService;
use arabcoders\database\Schema\SchemaIntrospectOptions;
use PDO;

final class PackageMigrationFactory
{
    /**
     * @return array<int,string>
     */
    public function modelPaths(): array
    {
        return [ROOT_PATH . '/src/Models'];
    }

    public function migrationDirectory(): string
    {
        return ROOT_PATH . '/src/Migration';
    }

    /**
     * @param array<int,string> $ignoreTables
     */
    public function autogenOptions(
        array $ignoreTables = ['migration_version', 'migration_lock'],
        bool $dropOrphans = false,
        bool $dryRun = false,
    ): MigrationAutogenOptions {
        return new MigrationAutogenOptions(
            introspect: new SchemaIntrospectOptions(ignoreTables: $ignoreTables),
            dropOrphans: $dropOrphans,
            dryRun: $dryRun,
            augmenters: [new StateIndexSchema()],
        );
    }

    public function service(PDO $pdo, ?string $migrationDirectory = null): MigrationService
    {
        return new MigrationService($pdo, $migrationDirectory ?? $this->migrationDirectory());
    }

    public function probe(
        PDO $pdo,
        string $direction = 'up',
        int $steps = 0,
        bool $force = false,
        bool $repair = false,
    ): MigrationProbeResult {
        return $this->service($pdo)->probe(new MigrationRequest(
            direction: $direction,
            dryRun: true,
            steps: $steps,
            force: $force,
            repair: $repair,
        ));
    }

    public function migrate(
        PDO $pdo,
        string $direction = 'up',
        bool $dryRun = false,
        int $steps = 0,
        bool $force = false,
        bool $repair = false,
    ): MigrationOperationResult {
        return $this->service($pdo)->migrate(new MigrationRequest(
            direction: $direction,
            dryRun: $dryRun,
            steps: $steps,
            force: $force,
            repair: $repair,
        ));
    }

    public function isMigrated(PDO $pdo): bool
    {
        return false === $this->probe($pdo)->needed;
    }
}
