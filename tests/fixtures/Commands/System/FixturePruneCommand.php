<?php

declare(strict_types=1);

namespace Tests\fixtures\Commands\System;

use App\Commands\System\PruneCommand;
use App\Libs\Extends\LogMessageProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

final class FixturePruneCommand extends PruneCommand
{
    public function __construct(
        private readonly array $pruners,
        ?TestHandler $handler = null,
    ) {
        $logger = new Logger('test', [], [new LogMessageProcessor()]);
        if (null !== $handler) {
            $logger->pushHandler($handler);
        }
        parent::__construct($logger);
    }

    protected function getPruners(): array
    {
        return $this->pruners;
    }
}
