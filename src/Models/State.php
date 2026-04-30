<?php

declare(strict_types=1);

namespace App\Models;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Index;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Model\BaseModel;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Transformer\ArrayTransformer;
use arabcoders\database\Transformer\ScalarTransformer;
use arabcoders\database\Transformer\ScalarType;
use arabcoders\database\Transformer\Transform;

#[Table(name: 'state')]
final class State extends BaseModel
{
    #[Transform(ScalarTransformer::class, ScalarType::INT, nullable: true)]
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public ?int $id = null;

    #[Index(name: 'state_type')]
    #[Column(type: ColumnType::Text)]
    public string $type = '';

    #[Index(name: 'state_updated')]
    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $updated = 0;

    #[Index(name: 'state_watched')]
    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int, hasDefault: true, default: 0)]
    public int $watched = 0;

    #[Index(name: 'state_via')]
    #[Column(type: ColumnType::Text)]
    public string $via = '';

    #[Index(name: 'state_title')]
    #[Column(type: ColumnType::Text)]
    public string $title = '';

    #[Index(name: 'state_year')]
    #[Transform(ScalarTransformer::class, ScalarType::INT, nullable: true)]
    #[Column(type: ColumnType::Int, nullable: true)]
    public ?int $year = null;

    #[Index(name: 'state_season')]
    #[Transform(ScalarTransformer::class, ScalarType::INT, nullable: true)]
    #[Column(type: ColumnType::Int, nullable: true)]
    public ?int $season = null;

    #[Index(name: 'state_episode')]
    #[Transform(ScalarTransformer::class, ScalarType::INT, nullable: true)]
    #[Column(type: ColumnType::Int, nullable: true)]
    public ?int $episode = null;

    #[Transform(ArrayTransformer::class, nullable: true)]
    #[Column(type: ColumnType::Text, nullable: true)]
    public ?array $parent = null;

    #[Transform(ArrayTransformer::class, nullable: true)]
    #[Column(type: ColumnType::Text, nullable: true)]
    public ?array $guids = null;

    #[Transform(ArrayTransformer::class, nullable: true)]
    #[Column(type: ColumnType::Text, nullable: true)]
    public ?array $metadata = null;

    #[Transform(ArrayTransformer::class, nullable: true)]
    #[Column(type: ColumnType::Text, nullable: true)]
    public ?array $extra = null;

    #[Index(name: 'state_created_at')]
    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int, hasDefault: true, default: 0)]
    public int $created_at = 0;

    #[Index(name: 'state_updated_at')]
    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int, hasDefault: true, default: 0)]
    public int $updated_at = 0;
}
