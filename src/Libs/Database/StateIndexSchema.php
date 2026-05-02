<?php

declare(strict_types=1);

namespace App\Libs\Database;

use App\Libs\Config;
use App\Libs\Guid;
use App\Libs\UserContext;
use arabcoders\database\Schema\AutogenSchemaAugmenterInterface;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Dialect\SchemaDialectInterface;
use PDO;

final class StateIndexSchema implements AutogenSchemaAugmenterInterface
{
    private const string TABLE = 'state';

    private const string COLUMN_PARENT = 'parent';

    private const string COLUMN_GUIDS = 'guids';

    private const string COLUMN_METADATA = 'metadata';

    /**
     * @var array<string>
     */
    private const array BACKEND_INDEXES = [
        'id',
        'show',
        'library',
        'multi',
    ];

    /**
     * @return array<string,IndexDefinition>
     */
    public function definitions(?UserContext $userContext = null): array
    {
        $indexes = [];

        foreach (array_keys(Guid::getSupported()) as $subKey) {
            foreach ([self::COLUMN_PARENT, self::COLUMN_GUIDS] as $column) {
                $name = sprintf('state_%s_%s', $column, $subKey);
                $indexes[$name] = new IndexDefinition(
                    name: $name,
                    columns: [],
                    unique: false,
                    type: 'index',
                    expression: sprintf("JSON_EXTRACT(%s,'$.%s')", $column, $subKey),
                );
            }
        }

        foreach (array_keys($this->servers($userContext)) as $backend) {
            foreach (self::BACKEND_INDEXES as $subKey) {
                $name = sprintf('state_%s_%s_%s', self::COLUMN_METADATA, $backend, $subKey);
                $indexes[$name] = new IndexDefinition(
                    name: $name,
                    columns: [],
                    unique: false,
                    type: 'index',
                    expression: sprintf("JSON_EXTRACT(%s,'$.%s.%s')", self::COLUMN_METADATA, $backend, $subKey),
                );
            }
        }

        return $indexes;
    }

    public function augmentTargetSchema(
        SchemaDefinition $targetSchema,
        SchemaDefinition $databaseSchema,
        SchemaDialectInterface $dialect,
        PDO $pdo,
    ): void {
        $targetTable = $targetSchema->getTable(self::TABLE);
        $databaseTable = $databaseSchema->getTable(self::TABLE);

        if (null === $targetTable || null === $databaseTable) {
            return;
        }

        foreach ($databaseTable->getIndexes() as $index) {
            if (!$this->manages($index->name)) {
                continue;
            }

            $targetTable->addIndex($index);
        }
    }

    public function manages(string $name): bool
    {
        return (
            str_starts_with($name, 'state_parent_')
            || str_starts_with($name, 'state_guids_')
            || str_starts_with($name, 'state_metadata_')
        );
    }

    private function servers(?UserContext $userContext = null): array
    {
        if (null !== $userContext) {
            return $userContext->config->getAll();
        }

        return Config::get('servers', []);
    }
}
