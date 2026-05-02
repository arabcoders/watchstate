<?php

declare(strict_types=1);

namespace Tests\fixtures\Prune;

use App\Libs\Attributes\Cli\Prune;

#[Prune(name: 'Fake Pruner', cron: '* * * * *', desc: 'Fake test pruner.')]
final class FakePruner
{
    public static array $calls = [];

    public function __invoke(bool $execute): void
    {
        self::$calls[] = $execute;
    }
}
