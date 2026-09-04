<?php

declare(strict_types=1);

namespace App\Migration;

use arabcoders\database\Attributes\Migration;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Blueprint\TableBlueprint;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;

#[Migration(
    id: '260720140746',
    name: 'media_health',
    squashedFrom: '260430182926',
    squashedChecksum: '7b304c1dfd92be4f97ba5b19ec3997216698648e1412878ffb5836b53eb1a510',
)]
final class Migration_260720140746 extends SchemaBlueprintMigration
{
    public function change(Blueprint $blueprint): void
    {
        $blueprint->createTable('events', static function (TableBlueprint $table): void {
            $table->column('id', ColumnType::Text)->primary();
            $table->column('status', ColumnType::Int)->default(0);
            $table->column('reference', ColumnType::Text)->nullable();
            $table->column('event', ColumnType::Text);
            $table->column('event_data', ColumnType::Text)->default('{}');
            $table->column('options', ColumnType::Text)->default('{}');
            $table->column('attempts', ColumnType::Int)->default(0);
            $table->column('logs', ColumnType::Text)->default('{}');
            $table->column('created_at', ColumnType::Int);
            $table->column('updated_at', ColumnType::Int)->nullable();
            $table->index('status', 'events_status');
            $table->index('reference', 'events_reference');
            $table->index('event', 'events_event');
        });
        $blueprint->createTable('playlists', static function (TableBlueprint $table): void {
            $table->column('id', ColumnType::Int)->primary()->autoIncrement();
            $table->column('backend', ColumnType::Text);
            $table->column('backend_id', ColumnType::Text);
            $table->column('title', ColumnType::Text);
            $table->column('type', ColumnType::Text)->default('video');
            $table->column('summary', ColumnType::Text)->nullable();
            $table->column('is_editable', ColumnType::Int)->default(1);
            $table->column('is_smart', ColumnType::Int)->default(0);
            $table->column('is_public', ColumnType::Int)->default(0);
            $table->column('item_count', ColumnType::Int)->default(0);
            $table->column('sync_id', ColumnType::Text)->nullable();
            $table->column('content_hash', ColumnType::Text)->default('');
            $table->column('remote_updated_at', ColumnType::Int)->default(0);
            $table->column('deleted_at', ColumnType::Int)->nullable();
            $table->column('metadata', ColumnType::Text)->default('{}');
            $table->column('created_at', ColumnType::Int);
            $table->column('updated_at', ColumnType::Int);
            $table->column('synced_at', ColumnType::Int);
            $table->index('backend', 'playlists_backend');
            $table->index('title', 'playlists_title');
            $table->index('sync_id', 'playlists_sync_id');
            $table->index('remote_updated_at', 'playlists_remote_updated_at');
            $table->index('deleted_at', 'playlists_deleted_at');
            $table->unique(['backend', 'backend_id']);
        });
        $blueprint->createTable('playlist_items', static function (TableBlueprint $table): void {
            $table->column('id', ColumnType::Int)->primary()->autoIncrement();
            $table->column('playlist_id', ColumnType::Int);
            $table->column('position', ColumnType::Int);
            $table->column('state_id', ColumnType::Int)->nullable();
            $table->column('backend_item_id', ColumnType::Text)->nullable();
            $table->column('backend_entry_id', ColumnType::Text)->nullable();
            $table->column('item_type', ColumnType::Text)->nullable();
            $table->column('title', ColumnType::Text);
            $table->column('metadata', ColumnType::Text)->default('{}');
            $table->column('created_at', ColumnType::Int);
            $table->column('updated_at', ColumnType::Int);
            $table->index('playlist_id', 'playlist_items_playlist_id');
            $table->index('state_id', 'playlist_items_state_id');
            $table->index('backend_item_id', 'playlist_items_backend_item');
            $table->unique(['playlist_id', 'position'], 'playlist_items_position');
        });
        $blueprint->createTable('state', static function (TableBlueprint $table): void {
            $table->column('id', ColumnType::Int)->primary()->autoIncrement();
            $table->column('type', ColumnType::Text);
            $table->column('updated', ColumnType::Int);
            $table->column('watched', ColumnType::Int)->default(0);
            $table->column('via', ColumnType::Text);
            $table->column('title', ColumnType::Text);
            $table->column('year', ColumnType::Int)->nullable();
            $table->column('season', ColumnType::Int)->nullable();
            $table->column('episode', ColumnType::Int)->nullable();
            $table->column('parent', ColumnType::Text)->nullable();
            $table->column('guids', ColumnType::Text)->nullable();
            $table->column('metadata', ColumnType::Text)->nullable();
            $table->column('extra', ColumnType::Text)->nullable();
            $table->column('created_at', ColumnType::Int)->default(0);
            $table->column('updated_at', ColumnType::Int)->default(0);
            $table->index('type', 'state_type');
            $table->index('updated', 'state_updated');
            $table->index('watched', 'state_watched');
            $table->index('via', 'state_via');
            $table->index('title', 'state_title');
            $table->index('year', 'state_year');
            $table->index('season', 'state_season');
            $table->index('episode', 'state_episode');
            $table->index('created_at', 'state_created_at');
            $table->index('updated_at', 'state_updated_at');
        });
        $blueprint->createTable('media_health_reports', static function (TableBlueprint $table): void {
            $table->column('id', ColumnType::Int)->primary()->autoIncrement();
            $table->column('status', ColumnType::Text);
            $table->column('generated_at', ColumnType::Int);
            $table->column('completed_at', ColumnType::Int)->nullable();
            $table->column('version', ColumnType::Int)->default(1);
            $table->column('state_count', ColumnType::Int)->default(0);
            $table->column('backend_count', ColumnType::Int)->default(0);
            $table->column('summary', ColumnType::Text)->default('{}');
            $table->column('error', ColumnType::Text)->nullable();
            $table->index('status', 'media_health_reports_status');
            $table->index('generated_at', 'media_health_reports_generated_at');
        });
        $blueprint->createTable('media_health_report_items', static function (TableBlueprint $table): void {
            $table->column('id', ColumnType::Int)->primary()->autoIncrement();
            $table->column('report_id', ColumnType::Int);
            $table->column('state_id', ColumnType::Int);
            $table->column('type', ColumnType::Text);
            $table->column('title', ColumnType::Text);
            $table->column('year', ColumnType::Int)->nullable();
            $table->column('season', ColumnType::Int)->nullable();
            $table->column('episode', ColumnType::Int)->nullable();
            $table->column('status', ColumnType::Text);
            $table->column('severity', ColumnType::Int);
            $table->column('confidence', ColumnType::Int);
            $table->column('backend_count', ColumnType::Int);
            $table->column('expected_backend_count', ColumnType::Int);
            $table->column('reasons', ColumnType::Text)->default('[]');
            $table->column('signals', ColumnType::Text)->default('{}');
            $table->index('report_id', 'media_health_report_items_report_id');
            $table->index('state_id', 'media_health_report_items_state_id');
            $table->index('type', 'media_health_report_items_type');
            $table->index('title', 'media_health_report_items_title');
            $table->index('status', 'media_health_report_items_status');
            $table->index('severity', 'media_health_report_items_severity');
        });
    }
}
