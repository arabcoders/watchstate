<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\Container;
use App\Libs\LogSuppressor;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterInterface as iOutput;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\ConsoleOutput as baseConsoleOutput;

/**
 * Class ConsoleOutput
 *
 * Represents a console output for displaying messages.
 *
 * @extends baseConsoleOutput
 */
final class ConsoleOutput extends baseConsoleOutput
{
    private bool $noSuppressor = false;
    private LogSuppressor|null $suppressor = null;

    /**
     * Constructor for the class.
     *
     * Initializes the object with the given parameters.
     *
     * @param int $verbosity The verbosity level (default: parent::VERBOSITY_NORMAL).
     * @param bool|null $decorated Whether to decorate the output (default: null).
     * @param iOutput|null $formatter The output formatter object (default: null).
     */
    public function __construct(
        int $verbosity = parent::VERBOSITY_NORMAL,
        ?bool $decorated = null,
        ?iOutput $formatter = null
    ) {
        $formatter = $formatter ?? new OutputFormatter();

        if (null !== $formatter) {
            //(black, red, green, yellow, blue, magenta, cyan, white, default, gray, bright-red, bright-green,
            //bright-yellow, bright-blue, bright-magenta, bright-cyan, bright-white)
            $formatter->setStyle('flag', new OutputFormatterStyle('green'));
            $formatter->setStyle('value', new OutputFormatterStyle('yellow'));
            $formatter->setStyle('notice', new OutputFormatterStyle('magenta'));
            $formatter->setStyle('cmd', new OutputFormatterStyle('blue'));
            $formatter->setStyle('question', new OutputFormatterStyle('cyan'));
        }

        parent::__construct($verbosity, $decorated, $formatter);
    }

    private mixed $message = '';

    /**
     * Writes the given message to a certain location, optionally appending a newline character.
     *
     * @param string $message The message to be written.
     * @param bool $newline Whether to append a newline character after the message. Default is false.
     */
    protected function doWrite(string $message, bool $newline): void
    {
        if (false === $this->noSuppressor) {
            if (null === $this->suppressor) {
                $this->suppressor = Container::get(LogSuppressor::class);
            }
            if (true === $this->suppressor->isSuppressed($message)) {
                return;
            }
        }

        $this->message = $message;

        parent::doWrite($message, $newline);
    }

    /**
     * Retrieves the last message that was written.
     *
     * @return mixed The last message that was written, or null if no message has been written yet.
     */
    public function getLastMessage(): mixed
    {
        return $this->message;
    }

    /**
     * Disable the suppressor
     * @return $this
     */
    public function withNoSuppressor(): self
    {
        $instance = $this;
        $instance->noSuppressor = true;
        $instance->suppressor = null;

        return $instance;
    }
}
