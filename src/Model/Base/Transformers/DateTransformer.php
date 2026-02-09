<?php

declare(strict_types=1);

namespace App\Model\Base\Transformers;

use App\Model\Base\Enums\TransformType;
use DateTimeInterface;
use RuntimeException;

final readonly class DateTransformer
{
    public function __construct(
        private bool $nullable = false,
    ) {}

    public static function create(bool $nullable = false): callable
    {
        $class = new self($nullable);
        return $class(...);
    }

    public function __invoke(TransformType $type, mixed $data): string|null|DateTimeInterface
    {
        if (null === $data) {
            if ($this->nullable) {
                return null;
            }

            throw new RuntimeException('Date cannot be null');
        }

        $isDate = true === $data instanceof DateTimeInterface;
        $isNumeric = is_int($data) || is_string($data) && ctype_digit($data);

        if (false === $isDate && false === $isNumeric && !is_string($data)) {
            throw new RuntimeException('Date must be a string or an instance of DateTimeInterface');
        }

        if (false === $isDate && true === $isNumeric) {
            $isDate = true;
            $data = make_date((string) $data);
        }

        return match ($type) {
            TransformType::ENCODE => $isDate ? $data->format(DateTimeInterface::ATOM) : (string) $data,
            TransformType::DECODE => make_date($data),
        };
    }
}
