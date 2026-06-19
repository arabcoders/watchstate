<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\Container;
use App\Libs\LogSuppressor;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterInterface as iOutput;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
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
    private ?LogSuppressor $suppressor = null;
    private mixed $message = '';
    private bool $jsonl = false;

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
        ?iOutput $formatter = null,
    ) {
        $formatter ??= new OutputFormatter();

        if (null !== $formatter) {
            $formatter->setStyle('flag', new OutputFormatterStyle('green'));
            $formatter->setStyle('value', new OutputFormatterStyle('yellow'));
            $formatter->setStyle('notice', new OutputFormatterStyle('magenta'));
            $formatter->setStyle('cmd', new OutputFormatterStyle('blue'));
            $formatter->setStyle('question', new OutputFormatterStyle('cyan'));
        }

        parent::__construct($verbosity, $decorated, $formatter);

        $this->setErrorOutput(
            new JsonlStreamOutput(
                defined('STDERR') ? STDERR : fopen('php://stderr', 'w'),
                $verbosity,
                $decorated,
                $formatter,
                'stderr',
            ),
        );
    }

    /**
     * Writes the given message to a certain location, optionally appending a newline character.
     *
     * @param string $message The message to be written.
     * @param bool $newline Whether to append a newline character after the message.
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
     * Disable the suppressor.
     *
     * @return self
     */
    public function withNoSuppressor(): self
    {
        $instance = $this;
        $instance->noSuppressor = true;
        $instance->suppressor = null;

        return $instance;
    }

    /**
     * Enable or disable JSONL output mode.
     *
     * @param bool $jsonl Whether to emit JSONL formatted output.
     */
    public function setJsonl(bool $jsonl): void
    {
        $this->jsonl = $jsonl;

        $error = $this->getErrorOutput();
        if (method_exists($error, 'setJsonl')) {
            $error->setJsonl($jsonl);
        }
    }

    /**
     * Check whether JSONL output mode is active.
     *
     * @return bool True when JSONL mode is enabled.
     */
    public function isJsonl(): bool
    {
        return $this->jsonl;
    }

    /**
     * Enable JSONL mode when the --jsonl flag is present in the input.
     *
     * @param InputInterface|null $input The console input to inspect. Falls back to argv when null.
     */
    public function syncJsonlMode(?InputInterface $input = null): void
    {
        $input ??= new ArgvInput();

        if (!$input->hasParameterOption('--jsonl', true)) {
            return;
        }

        $this->setJsonl(true);
    }

    /**
     * Strip ANSI color codes and trim trailing newlines from a message.
     *
     * @param string $message The raw message potentially containing escape sequences.
     * @param bool $newline Whether the original write included a newline.
     *
     * @return string The cleaned message.
     */
    private function normalizeMessage(string $message, bool $newline): string
    {
        $normalized = preg_replace('/\x1b\[[0-9;]*m/', '', $message) ?? $message;

        if ($newline) {
            return rtrim($normalized, "\r\n");
        }

        return $normalized;
    }
}
