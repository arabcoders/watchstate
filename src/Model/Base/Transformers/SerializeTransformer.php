<?php

declare(strict_types=1);

namespace App\Model\Base\Transformers;

use App\Model\Base\Enums\TransformType;
use Closure;

final class SerializeTransformer
{
    private static Closure $encode;
    private static Closure $decode;

    public function __construct(bool $allowClasses = true)
    {
        if (extension_loaded('igbinary')) {
            self::$encode = igbinary_serialize(...);
            self::$decode = igbinary_unserialize(...);
        } else {
            self::$encode = serialize(...);
            self::$decode = static fn(string $data) => unserialize($data, ['allowed_classes' => $allowClasses]);
        }
    }

    public function __invoke(TransformType $type, mixed $data): mixed
    {
        return match ($type) {
            TransformType::ENCODE => (self::$encode)($data),
            TransformType::DECODE => (self::$decode)($data),
        };
    }
}
