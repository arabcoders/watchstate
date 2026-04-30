<?php

declare(strict_types=1);

namespace App\Models\Playlists;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Index;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Attributes\Schema\Unique;
use arabcoders\database\Model\BaseModel;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Transformer\ArrayTransformer;
use arabcoders\database\Transformer\ScalarTransformer;
use arabcoders\database\Transformer\ScalarType;
use arabcoders\database\Transformer\Transform;

#[Table(name: 'playlists')]
#[Unique(columns: ['backend', 'backend_id'])]
final class Playlist extends BaseModel
{
    #[Transform(ScalarTransformer::class, ScalarType::INT, nullable: true)]
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public ?int $id = null;

    #[Index(name: 'playlists_backend')]
    #[Column(type: ColumnType::Text)]
    public string $backend = '';

    #[Column(type: ColumnType::Text)]
    public string $backend_id = '';

    #[Index(name: 'playlists_title')]
    #[Column(type: ColumnType::Text)]
    public string $title = '';

    #[Column(type: ColumnType::Text, hasDefault: true, default: 'video')]
    public string $type = 'video';

    #[Column(type: ColumnType::Text, nullable: true)]
    public ?string $summary = null;

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int, hasDefault: true, default: 1)]
    public int $is_editable = 1;

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int, hasDefault: true, default: 0)]
    public int $is_smart = 0;

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int, hasDefault: true, default: 0)]
    public int $is_public = 0;

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int, hasDefault: true, default: 0)]
    public int $item_count = 0;

    #[Index(name: 'playlists_sync_id')]
    #[Column(type: ColumnType::Text, nullable: true)]
    public ?string $sync_id = null;

    #[Column(type: ColumnType::Text, hasDefault: true, default: '')]
    public string $content_hash = '';

    #[Index(name: 'playlists_remote_updated_at')]
    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int, hasDefault: true, default: 0)]
    public int $remote_updated_at = 0;

    #[Index(name: 'playlists_deleted_at')]
    #[Transform(ScalarTransformer::class, ScalarType::INT, nullable: true)]
    #[Column(type: ColumnType::Int, nullable: true)]
    public ?int $deleted_at = null;

    #[Transform(ArrayTransformer::class)]
    #[Column(type: ColumnType::Text, hasDefault: true, default: '{}')]
    public array $metadata = [];

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $created_at = 0;

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $updated_at = 0;

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $synced_at = 0;
}
