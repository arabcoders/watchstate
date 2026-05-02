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

#[Table(name: 'playlist_items')]
#[Unique(name: 'playlist_items_position', columns: ['playlist_id', 'position'])]
final class PlaylistItem extends BaseModel
{
    #[Transform(ScalarTransformer::class, ScalarType::INT, nullable: true)]
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public ?int $id = null;

    #[Index(name: 'playlist_items_playlist_id')]
    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $playlist_id = 0;

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $position = 0;

    #[Index(name: 'playlist_items_state_id')]
    #[Transform(ScalarTransformer::class, ScalarType::INT, nullable: true)]
    #[Column(type: ColumnType::Int, nullable: true)]
    public ?int $state_id = null;

    #[Index(name: 'playlist_items_backend_item')]
    #[Column(type: ColumnType::Text, nullable: true)]
    public ?string $backend_item_id = null;

    #[Column(type: ColumnType::Text, nullable: true)]
    public ?string $backend_entry_id = null;

    #[Column(type: ColumnType::Text, nullable: true)]
    public ?string $item_type = null;

    #[Column(type: ColumnType::Text)]
    public string $title = '';

    #[Transform(ArrayTransformer::class)]
    #[Column(type: ColumnType::Text, hasDefault: true, default: '{}')]
    public array $metadata = [];

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $created_at = 0;

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $updated_at = 0;
}
