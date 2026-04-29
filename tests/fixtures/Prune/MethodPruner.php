<?php

declare(strict_types=1);

namespace Tests\fixtures\Prune;

use App\Libs\Attributes\Cli\Prune;

final class MethodPruner
{
    public static array $calls = [];

    #[Prune(name: 'Method Pruner', cron: '*/15 * * * *', desc: 'Method-based test pruner.')]
    public function run(bool $execute): void
    {
        self::$calls[] = $execute;
    }
}
