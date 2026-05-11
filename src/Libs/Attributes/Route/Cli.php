<?php

declare(strict_types=1);

namespace App\Libs\Attributes\Route;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Cli extends Route
{
    public function __construct(string $command, array $aliases = [])
    {
        parent::__construct(methods: [], pattern: $command, opts: ['cli' => true, 'aliases' => $aliases]);
    }
}
