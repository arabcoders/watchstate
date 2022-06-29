<?php

declare(strict_types=1);

namespace App\Libs;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Route
{
    public function __construct(public readonly string $command)
    {
    }
}
