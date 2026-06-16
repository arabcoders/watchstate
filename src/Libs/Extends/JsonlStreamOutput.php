<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\Extends\JsonlFormatter;
use Monolog\Level;
use Symfony\Component\Console\Formatter\OutputFormatterInterface as iOutput;
use Symfony\Component\Console\Output\StreamOutput;

final class JsonlStreamOutput extends StreamOutput
{
    private bool $jsonl = false;
    private mixed $message = '';
    private JsonlFormatter $formatter;

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
        $this->formatter = new JsonlFormatter();
        parent::__construct($stream, $verbosity, $decorated, $formatter);
    }

    public function setJsonl(bool $jsonl): void
    {
        $this->jsonl = $jsonl;
    }

    public function isJsonl(): bool
    {
        return $this->jsonl;
    }

    public function getLastMessage(): mixed
    {
        return $this->message;
    }

    public function writeJsonlRecord(string $payload): void
    {
        $payload = rtrim($payload, "\r\n");
        $this->message = $payload . PHP_EOL;
        parent::doWrite($payload, true);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->message = $message;

        if ($this->jsonl) {
            if (JsonlFormatter::isJsonlRecord($message)) {
                $this->writeJsonlRecord($message);
                return;
            }

            $payload = $this->formatter->formatValues(
                channel: 'cli',
                level: 'stderr' === $this->streamName ? Level::Warning : Level::Info,
                message: preg_replace('/\x1b\[[0-9;]*m/', '', rtrim($message, "\r\n")) ?? rtrim($message, "\r\n"),
                context: ['cli' => ['stream' => $this->streamName]],
            );

            $this->message = $payload;
            parent::doWrite($payload, false);
            return;
        }

        parent::doWrite($message, $newline);
    }
}
