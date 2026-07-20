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

#[Table(name: 'media_health_report_items')]
final class MediaHealthReportItem extends BaseModel
{
    #[Transform(ScalarTransformer::class, ScalarType::INT, nullable: true)]
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public ?int $id = null;

    #[Index(name: 'media_health_report_items_report_id')]
    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $report_id = 0;

    #[Index(name: 'media_health_report_items_state_id')]
    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $state_id = 0;

    #[Index(name: 'media_health_report_items_type')]
    #[Column(type: ColumnType::Text)]
    public string $type = '';

    #[Index(name: 'media_health_report_items_title')]
    #[Column(type: ColumnType::Text)]
    public string $title = '';

    #[Transform(ScalarTransformer::class, ScalarType::INT, nullable: true)]
    #[Column(type: ColumnType::Int, nullable: true)]
    public ?int $year = null;

    #[Transform(ScalarTransformer::class, ScalarType::INT, nullable: true)]
    #[Column(type: ColumnType::Int, nullable: true)]
    public ?int $season = null;

    #[Transform(ScalarTransformer::class, ScalarType::INT, nullable: true)]
    #[Column(type: ColumnType::Int, nullable: true)]
    public ?int $episode = null;

    #[Index(name: 'media_health_report_items_status')]
    #[Column(type: ColumnType::Text)]
    public string $status = '';

    #[Index(name: 'media_health_report_items_severity')]
    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $severity = 0;

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $confidence = 0;

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $backend_count = 0;

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $expected_backend_count = 0;

    #[Transform(ArrayTransformer::class)]
    #[Column(type: ColumnType::Text, hasDefault: true, default: '[]')]
    public array $reasons = [];

    #[Transform(ArrayTransformer::class)]
    #[Column(type: ColumnType::Text, hasDefault: true, default: '{}')]
    public array $signals = [];
}
