<?php

declare(strict_types=1);

namespace Tests\fixtures\Prune;

use App\Libs\Attributes\Cli\Prune;

#[Prune(name: 'Another Pruner', cron: '0 5 * * *', desc: 'Another test pruner.', enabled: false)]
final class AnotherPruner
{
    public function __invoke(bool $execute): void
    {
    }
}
