<?php

namespace App\Model\Base\Transformers;

use App\Model\Base\Enums\TransformType;
use DateTimeInterface;
use RuntimeException;

final readonly class DateTransformer
{
    public function __construct(private bool $nullable = false)
    {
    }

    public static function create(bool $nullable = false): callable
    {
        $class = new self($nullable);
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

        if (false === $isDate && !is_string($data)) {
            if (true === ctype_digit((string)$data)) {
                $isDate = true;
                $data = makeDate($data);
            } else {
                throw new RuntimeException('Date must be a string or an instance of DateTimeInterface');
            }
        }

        return match ($type) {
            TransformType::ENCODE => $isDate ? $data->format(DateTimeInterface::ATOM) : (string)$data,
            TransformType::DECODE => makeDate($data),
        };
    }
}
