<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use Symfony\Component\Console\Output\ConsoleOutput as baseConsoleOutput;

final class ConsoleOutput extends baseConsoleOutput
{
    private mixed $message = '';

    protected function doWrite(string $message, bool $newline): void
    {
        $this->message = $message;

        parent::doWrite($message, $newline);
    }

    public function getLastMessage(): mixed
    {
        return $this->message;
    }
}
