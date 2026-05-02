<?php

declare(strict_types=1);

namespace Tests\fixtures\Commands\System;

use App\Commands\System\PruneCommand;
use Monolog\Logger;

final class FixturePruneCommand extends PruneCommand
{
    public function __construct(
        private readonly array $pruners,
    ) {
        parent::__construct(new Logger('test'));
    }

    protected function getPruners(): array
    {
        return $this->pruners;
    }
}
