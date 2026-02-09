<?php

declare(strict_types=1);

namespace App\Model\Base\Transformers;

use App\Model\Base\Enums\TransformType;
use DateTimeInterface;
use RuntimeException;

final readonly class TimestampTransformer
{
    public function __construct(
        private bool $nullable = false,
    ) {}

    public static function create(bool $nullable = false): callable
    {
        $class = new self(nullable: $nullable);
        return $class(...);
    }

    public function __invoke(TransformType $type, mixed $data): int|string|null|DateTimeInterface
    {
        if (null === $data) {
            if ($this->nullable) {
                return null;
            }

            throw new RuntimeException('Date cannot be null');
        }

        $isDate = true === $data instanceof DateTimeInterface;
        $isDigit = is_int($data) || is_string($data) && ctype_digit((string) $data);
        if (false === $isDate && !$isDigit) {
            if (is_string($data)) {
                $isDate = true;
                $data = make_date($data);
            } else {
                throw new RuntimeException(r("Date must be a integer or DateTime. '{type}('{data}')' given.", [
                    'type' => get_debug_type($data),
                    'data' => $data,
                ]));
            }
        }

        return match ($type) {
            TransformType::ENCODE => $isDate ? $data->getTimestamp() : $data,
            TransformType::DECODE => $isDate ? $data : make_date($data),
        };
    }
}
