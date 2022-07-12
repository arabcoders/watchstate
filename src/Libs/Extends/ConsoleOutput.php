<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterInterface as iOutput;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\ConsoleOutput as baseConsoleOutput;

final class ConsoleOutput extends baseConsoleOutput
{
    public function __construct(
        int $verbosity = parent::VERBOSITY_NORMAL,
        bool $decorated = null,
        iOutput $formatter = null
    ) {
        $formatter = $formatter ?? new OutputFormatter();

        if (null !== $formatter) {
            //(black, red, green, yellow, blue, magenta, cyan, white, default, gray, bright-red, bright-green,
            //bright-yellow, bright-blue, bright-magenta, bright-cyan, bright-white)
            $formatter->setStyle('flag', new OutputFormatterStyle('green'));
            $formatter->setStyle('value', new OutputFormatterStyle('yellow'));
            $formatter->setStyle('notice', new OutputFormatterStyle('blue'));
            $formatter->setStyle('cmd', new OutputFormatterStyle('blue'));
            $formatter->setStyle('question', new OutputFormatterStyle('cyan'));
        }

        parent::__construct($verbosity, $decorated, $formatter);
    }

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
