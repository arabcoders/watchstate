<?php

declare(strict_types=1);

namespace App\Model\Base\Transformers;

use App\Model\Base\Enums\TransformType;
use InvalidArgumentException;

final class JSONTransformer
{
    public const int DEFAULT_JSON_FLAGS =
        JSON_INVALID_UTF8_IGNORE
            | JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_AMP
            | JSON_HEX_QUOT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private bool $isAssoc = false,
        private int $flags = self::DEFAULT_JSON_FLAGS,
        private bool $nullable = false,
    ) {}

    public static function create(
        bool $isAssoc = false,
        int $flags = self::DEFAULT_JSON_FLAGS,
        bool $nullable = false,
    ): callable {
        $class = new self(isAssoc: $isAssoc, flags: $flags, nullable: $nullable);
        return $class(...);
    }

    public function __invoke(TransformType $type, mixed $data): string|array|object|null
    {
        if (null === $data) {
            if (true === $this->nullable) {
                return null;
            }
            throw new InvalidArgumentException('Data cannot be null');
        }

        return match ($type) {
            TransformType::ENCODE => json_encode($data, flags: $this->flags),
            TransformType::DECODE => json_decode($data, associative: $this->isAssoc, flags: $this->flags),
        };
    }
}
