<?php

declare(strict_types=1);

namespace App\Model\Base\Transformers;

use App\Model\Base\Enums\TransformType;
use BackedEnum;

final class EnumTransformer
{
    /**
     * @param string<class-string> $enumName The class name of the enum.
     */
    public function __construct(
        private string $enumName,
    ) {}

    public static function create(string $enumName): callable
    {
        $class = new self($enumName);
        return $class(...);
    }

    public function __invoke(TransformType $type, mixed $value): mixed
    {
        return match ($type) {
            TransformType::ENCODE => $this->encode($value),
            TransformType::DECODE => $this->decode($value),
        };
    }

    private function encode(mixed $value): string|int
    {
        if (is_string($value) || is_int($value)) {
            return $value;
        }

        return $value instanceof BackedEnum ? $value->value : $value->name;
    }

    private function decode(mixed $data): mixed
    {
        return is_subclass_of($this->enumName, BackedEnum::class)
            ? $this->enumName::from($data)
            : constant($this->enumName . '::' . $data);
    }
}
