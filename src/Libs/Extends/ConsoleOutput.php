<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\Container;
use App\Libs\LogSuppressor;
use Monolog\Level;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterInterface as iOutput;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput as baseConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

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
    private bool $jsonl = false;
    private string $streamName = 'stdout';
    private JsonlFormatter $jsonlFormatter;
    private mixed $message = '';

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
        $this->jsonlFormatter = new JsonlFormatter();

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
        $this->setErrorOutput($this->makeStreamOutput($this->openErrorStreamHandle(), 'stderr', $verbosity, $decorated, $formatter));
    }

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

        if (true === $this->jsonl) {
            if (true === JsonlFormatter::isJsonlRecord($message)) {
                $payload = rtrim($message, "\r\n");
                $this->message = $payload . PHP_EOL;
                parent::doWrite($payload, true);
                return;
            }

            $payload = $this->jsonlFormatter->formatValues(
                channel: 'cli',
                level: 'stderr' === $this->streamName ? Level::Warning : Level::Info,
                message: $this->normalizeMessage($message, $newline),
                context: ['cli' => ['stream' => $this->streamName]],
            );

            $this->message = $payload;
            parent::doWrite(rtrim($payload, "\r\n"), true);
            return;
        }

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
     * Enable or disable JSONL output mode.
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
     * Whether this output is in JSONL mode.
     */
    public function isJsonl(): bool
    {
        return $this->jsonl;
    }

    /**
     * Enable JSONL output if the current input contains --jsonl.
     */
    public function syncJsonlMode(?InputInterface $input = null): void
    {
        $input ??= new ArgvInput();

        if (false === $input->hasParameterOption('--jsonl', true)) {
            return;
        }

        $this->setJsonl(true);
    }

    /**
     * Write an existing JSONL record without wrapping it.
     */
    public function writeJsonlRecord(string $payload, bool $error = false): void
    {
        $payload = rtrim($payload, "\r\n");

        if (false === $error) {
            $this->message = $payload . PHP_EOL;
            parent::doWrite($payload, true);
            return;
        }

        $errorOutput = $this->getErrorOutput();
        if (method_exists($errorOutput, 'writeJsonlRecord')) {
            $errorOutput->writeJsonlRecord($payload);
            return;
        }

        $errorOutput->writeln($payload, OutputInterface::OUTPUT_RAW);
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        parent::setErrorOutput($error);

        if (method_exists($error, 'setJsonl')) {
            $error->setJsonl($this->jsonl);
        }
    }

    /**
     * @param resource $stream
     */
    public function setErrorStream($stream, int $verbosity = self::VERBOSITY_NORMAL, ?bool $decorated = null): void
    {
        $this->setErrorOutput($this->makeStreamOutput($stream, 'stderr', $verbosity, $decorated, $this->getFormatter()));
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

    private function normalizeMessage(string $message, bool $newline): string
    {
        $normalized = preg_replace('/\x1b\[[0-9;]*m/', '', $message) ?? $message;

        if (true === $newline) {
            return rtrim($normalized, "\r\n");
        }

        return $normalized;
    }

    /**
     * @return resource
     */
    private function openErrorStreamHandle()
    {
        if (defined('STDERR')) {
            return STDERR;
        }

        return fopen('php://stderr', 'w');
    }

    /**
     * @param resource $stream
     */
    private function makeStreamOutput($stream, string $streamName, int $verbosity, ?bool $decorated, ?iOutput $formatter): JsonlStreamOutput
    {
        return new JsonlStreamOutput($stream, $verbosity, $decorated, $formatter, $streamName);
    }
}
