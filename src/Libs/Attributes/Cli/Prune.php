<?php

declare(strict_types=1);

namespace App\Libs\Attributes\Cli;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Prune
{
    public function __construct(
        public string $name,
        public ?string $cron = null,
        public ?string $desc = null,
        public bool $enabled = true,
    ) {}
}
