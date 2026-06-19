<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

final class CaptureHandler extends AbstractProcessingHandler
{
    /** @var array<LogRecord> */
    private array $records = [];

    public function __construct()
    {
        parent::__construct(Level::Debug, true);
    }

    protected function write(LogRecord $record): void
    {
        $this->records[] = $record;
    }

    /** @return array<LogRecord> */
    public function getRecords(): array
    {
        return $this->records;
    }

    /** @return array<int, string> — each string is one JSONL line including trailing newline. */
    public function getFormatted(): array
    {
        $formatter = new JsonlFormatter();
        $lines = [];
        foreach ($this->records as $record) {
            $lines[] = $formatter->format($record);
        }
        return $lines;
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
