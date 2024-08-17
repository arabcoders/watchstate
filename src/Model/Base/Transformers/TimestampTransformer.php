<?php

namespace App\Model\Base\Transformers;

use App\Model\Base\Enums\TransformType;
use DateTimeInterface;
use RuntimeException;

final readonly class TimestampTransformer
{
    public function __construct(private bool $nullable = false)
    {
    }

    public static function create(bool $nullable = false): callable
    {
        $class = new self(nullable: $nullable);
        return fn(TransformType $type, mixed $data) => $class($type, $data);
    }

    public function __invoke(TransformType $type, mixed $data): string|null|DateTimeInterface
    {
        if (null === $data) {
            if ($this->nullable) {
                return null;
            }

            throw new RuntimeException('Date cannot be null');
        }

        $isDate = true === ($data instanceof DateTimeInterface);

        if (false === $isDate && !ctype_digit($data)) {
            if (is_string($data)) {
                $isDate = true;
                $data = makeDate($data);
            } else {
                throw new RuntimeException(r("Date must be a integer or DateTime. '{type}('{data}')' given.", [
                    'type' => get_debug_type($data),
                    'data' => $data,
                ]));
            }
        }

        return match ($type) {
            TransformType::ENCODE => $isDate ? $data->getTimestamp() : $data,
            TransformType::DECODE => $isDate ? $data : makeDate($data),
        };
    }
}
