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

#[Table(name: 'events')]
final class Event extends BaseModel
{
    #[Column(type: ColumnType::Text, primary: true)]
    public string $id = '';

    #[Index(name: 'events_status')]
    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int, hasDefault: true, default: 0)]
    public int $status = 0;

    #[Index(name: 'events_reference')]
    #[Column(type: ColumnType::Text, nullable: true)]
    public ?string $reference = null;

    #[Index(name: 'events_event')]
    #[Column(type: ColumnType::Text)]
    public string $event = '';

    #[Transform(ArrayTransformer::class)]
    #[Column(type: ColumnType::Text, hasDefault: true, default: '{}')]
    public array $event_data = [];

    #[Transform(ArrayTransformer::class)]
    #[Column(type: ColumnType::Text, hasDefault: true, default: '{}')]
    public array $options = [];

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int, hasDefault: true, default: 0)]
    public int $attempts = 0;

    #[Transform(ArrayTransformer::class)]
    #[Column(type: ColumnType::Text, hasDefault: true, default: '{}')]
    public array $logs = [];

    #[Transform(ScalarTransformer::class, ScalarType::INT)]
    #[Column(type: ColumnType::Int)]
    public int $created_at = 0;

    #[Transform(ScalarTransformer::class, ScalarType::INT, nullable: true)]
    #[Column(type: ColumnType::Int, nullable: true)]
    public ?int $updated_at = null;
}
