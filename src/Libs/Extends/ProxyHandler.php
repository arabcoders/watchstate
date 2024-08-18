<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\Config;
use Closure;
use DateTimeInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * ProxyHandler, a handler that calls a closure to process the log record.
 */
final class ProxyHandler extends AbstractProcessingHandler
{
    private bool $closed = false;

    public function __construct(private readonly Closure $callback, $level = Level::Debug)
    {
        $this->bubble = true;
        parent::__construct($level, true);
    }

    public static function create(Closure $callback, $level = Level::Debug): self
    {
        return new self($callback, $level);
    }

    public function close(): void
    {
        $this->closed = true;
    }

    protected function write(LogRecord $record): void
    {
        if (true === $this->closed) {
            return;
        }

        $date = $record['datetime'] ?? 'No date set';

        if (true === ($date instanceof DateTimeInterface)) {
            $date = $date->format(DateTimeInterface::ATOM);
        }

        $message = r('[{date}] {level}: {message}', [
            'date' => $date,
            'level' => $record['level_name'] ?? $record['level'] ?? '??',
            'message' => $record['message'],
        ]);

        if (false === empty($record['context']) && true === (bool)Config::get('logs.context')) {
            $message .= ' { ' . arrayToString($record['context']) . ' }';
        }

        ($this->callback)($message, $record);
    }
}
