<?php

declare(strict_types=1);

namespace App\Migration;

use arabcoders\database\Attributes\Migration;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Blueprint\TableBlueprint;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;

#[Migration(id: '260909155258', name: 'backend_report')]
final class Migration_260909155258 extends SchemaBlueprintMigration
{
    public function change(Blueprint $blueprint): void
    {
        $blueprint->createTable('backend_reports', static function (TableBlueprint $table): void {
            $table->column('id', ColumnType::Int)->primary()->autoIncrement();
            $table->column('identity', ColumnType::Text);
            $table->column('status', ColumnType::Text);
            $table->column('generated_at', ColumnType::Int);
            $table->column('completed_at', ColumnType::Int)->nullable();
            $table->column('version', ColumnType::Int)->default(0);
            $table->column('backend_count', ColumnType::Int)->default(0);
            $table->column('summary', ColumnType::Text)->default('{}');
            $table->column('error', ColumnType::Text)->nullable();
            $table->index('identity', 'backend_reports_identity');
            $table->index('status', 'backend_reports_status');
            $table->index('generated_at', 'backend_reports_generated_at');
        });
    }
}
