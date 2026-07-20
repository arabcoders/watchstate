<?php

declare(strict_types=1);

namespace App\Libs\Attributes\DI;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class ForModel
{
    public function __construct(
        public string $model,
    ) {}
}
