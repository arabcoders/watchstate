<?php

declare(strict_types=1);

namespace App\Libs\Database;

use App\Libs\UserContext;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Dialect\SchemaDialectFactory;
use arabcoders\database\Schema\SchemaDiffer;
use arabcoders\database\Schema\SchemaIntrospector;
use arabcoders\database\Schema\SchemaNormalizer;
use arabcoders\database\Schema\SchemaSqlRenderer;
use PDO;

final class ExternalIndexDiff
{
    /**
     * @return array<int,string>
     */
    public function upsertSql(PDO $pdo, StateIndexSchema $schema, ?UserContext $userContext = null): array
    {
        $dialect = SchemaDialectFactory::fromPdo($pdo);
        $normalizer = new SchemaNormalizer();
        $current = $this->currentExternalTable($pdo, $schema);
        $targetTable = $this->copyTable($current);

        foreach ($schema->definitions($userContext) as $index) {
            $targetTable->addIndex($index);
        }

        $database = new SchemaDefinition();
        $database->addTable($current);

        $target = new SchemaDefinition();
        $target->addTable($targetTable);

        $database = $normalizer->normalize($database, $dialect);
        $target = $normalizer->normalize($target, $dialect);

        return new SchemaSqlRenderer($dialect)->render(
            new SchemaDiffer()->diff($database, $target),
        )->up;
    }

    /**
     * @return array<int,string>
     */
    public function rebuildSql(PDO $pdo, StateIndexSchema $schema, ?UserContext $userContext = null): array
    {
        $dialect = SchemaDialectFactory::fromPdo($pdo);
        $current = $this->currentExternalTable($pdo, $schema);
        $queries = [];

        foreach ($current->getIndexes() as $index) {
            $sql = $dialect->dropIndexSql($current->name, $index);
            $queries = [...$queries, ...(is_array($sql) ? $sql : [$sql])];
        }

        foreach ($schema->definitions($userContext) as $index) {
            $sql = $dialect->addIndexSql($current->name, $index);
            $queries = [...$queries, ...(is_array($sql) ? $sql : [$sql])];
        }

        return $queries;
    }

    private function currentExternalTable(PDO $pdo, StateIndexSchema $schema): TableDefinition
    {
        $table = new TableDefinition('state');
        $introspected = new SchemaIntrospector($pdo)
            ->introspect()
            ->getTable($table->name);

        if (null === $introspected) {
            return $table;
        }

        foreach ($introspected->getIndexes() as $index) {
            if (!$schema->manages($index->name)) {
                continue;
            }

            $table->addIndex($index);
        }

        return $table;
    }

    private function copyTable(TableDefinition $source): TableDefinition
    {
        $copy = new TableDefinition($source->name);

        foreach ($source->getIndexes() as $index) {
            $copy->addIndex(new IndexDefinition(
                name: $index->name,
                columns: $index->columns,
                unique: $index->unique,
                type: $index->type,
                algorithm: $index->algorithm,
                where: $index->where,
                expression: $index->expression,
            ));
        }

        return $copy;
    }
}
