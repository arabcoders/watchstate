<?php

namespace App\Model\Base\Transformers;

use App\Model\Base\Enums\ScalarType;
use App\Model\Base\Enums\TransformType;

final class ScalarTransformer
{
    public function __construct(private ScalarType $scalarType)
    {
    }

    public static function create(ScalarType $scalarType): callable
    {
        $class = new self($scalarType);
        return fn(TransformType $type, mixed $data) => $class($type, $data);
    }

    public function __invoke(TransformType $type, mixed $value): int|string|float|bool
    {
        return match ($type) {
            TransformType::ENCODE => $this->encode($value),
            TransformType::DECODE => $this->decode($value),
        };
    }

    private function encode(mixed $value): float|bool|int|string
    {
        return $this->decode($value);
    }

    private function decode(mixed $data): float|bool|int|string
    {
        return match ($this->scalarType) {
            ScalarType::STRING => (string)$data,
            ScalarType::INT => (int)$data,
            ScalarType::FLOAT => (float)$data,
            ScalarType::BOOL => (bool)$data,
        };
    }
}
