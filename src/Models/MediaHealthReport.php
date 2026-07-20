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

#[Table(name: 'media_health_reports')]
final class MediaHealthReport extends BaseModel
{
    #[Transform(ScalarTransformer::class, ScalarType::INT, nullable: true)]
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public ?int $id = null;

    #[Index(name: 'media_health_reports_status')]
    #[Column(type: ColumnType::Text)]
    public string $status = '';

    #[Index(name: 'media_health_reports_generated_at')]
    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $generated_at = 0;

    #[Transform(ScalarTransformer::class, ScalarType::INT, nullable: true)]
    #[Column(type: ColumnType::Int, nullable: true)]
    public ?int $completed_at = null;

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int, hasDefault: true, default: 1)]
    public int $version = 1;

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int, hasDefault: true, default: 0)]
    public int $state_count = 0;

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int, hasDefault: true, default: 0)]
    public int $backend_count = 0;

    #[Transform(ArrayTransformer::class)]
    #[Column(type: ColumnType::Text, hasDefault: true, default: '{}')]
    public array $summary = [];

    #[Column(type: ColumnType::Text, nullable: true)]
    public ?string $error = null;
}
