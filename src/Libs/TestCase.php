<?php

declare(strict_types=1);

namespace App\Libs;

use Monolog\Handler\TestHandler;

class TestCase extends \PHPUnit\Framework\TestCase
{
    protected TestHandler|null $handler = null;

    protected function printLogs(): void
    {
        if (null === $this->handler) {
            return;
        }

        foreach ($this->handler->getRecords() as $logs) {
            fwrite(STDOUT, $logs['formatted'] . PHP_EOL);
        }
    }
}
