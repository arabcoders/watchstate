<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use Exception;
use Symfony\Component\Console\Output\ConsoleOutput as baseConsoleOutput;

final class ConsoleOutput extends baseConsoleOutput
{
    /**
     * @throws Exception
     */
    protected function doWrite(string $message, bool $newline): void
    {
        $message = str_replace('!{date}', '[' . makeDate('now') . ']', $message);

        parent::doWrite($message, $newline);
    }
}
