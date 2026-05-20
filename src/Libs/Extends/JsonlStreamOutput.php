<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use Monolog\Level;
use Symfony\Component\Console\Formatter\OutputFormatterInterface as iOutput;
use Symfony\Component\Console\Output\StreamOutput;

final class JsonlStreamOutput extends StreamOutput
{
    private bool $jsonl = false;
    private mixed $message = '';
    private JsonlFormatter $jsonlFormatter;

    /**
     * @param resource $stream
     */
    public function __construct(
        $stream,
        int $verbosity,
        ?bool $decorated,
        ?iOutput $formatter,
        private readonly string $streamName,
    ) {
        $this->jsonlFormatter = new JsonlFormatter();

        parent::__construct($stream, $verbosity, $decorated, $formatter);
    }

    /**
     * Enable or disable JSONL output mode.
     */
    public function setJsonl(bool $jsonl): void
    {
        $this->jsonl = $jsonl;
    }

    /**
     * Whether this stream output is in JSONL mode.
     */
    public function isJsonl(): bool
    {
        return $this->jsonl;
    }

    /**
     * Return the last written message.
     */
    public function getLastMessage(): mixed
    {
        return $this->message;
    }

    /**
     * Write an existing JSONL payload without wrapping it again.
     */
    public function writeJsonlRecord(string $payload): void
    {
        $payload = rtrim($payload, "\r\n");
        $this->message = $payload . PHP_EOL;
        parent::doWrite($payload, true);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->message = $message;

        if (true === $this->jsonl) {
            if (true === JsonlFormatter::isJsonlRecord($message)) {
                $this->writeJsonlRecord($message);
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

    private function normalizeMessage(string $message, bool $newline): string
    {
        $normalized = preg_replace('/\x1b\[[0-9;]*m/', '', $message) ?? $message;

        if (true === $newline) {
            return rtrim($normalized, "\r\n");
        }

        return $normalized;
    }
}
