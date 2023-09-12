<?php

namespace App\Libs\Extends;

use App\Libs\Config;
use DateTimeInterface;
use Monolog\LogRecord;
use Nyholm\Psr7\Stream;
use Symfony\Component\Console\Output\OutputInterface;

class StreamLogHandler extends ConsoleHandler
{
    public function __construct(
        private Stream $stream,
        OutputInterface|null $output = null,
        bool $bubble = true,
        array $levelsMapper = []
    ) {
        parent::__construct($output, $bubble, $levelsMapper);
    }

    protected function write(LogRecord|array $record): void
    {
        if (true === ($record instanceof LogRecord)) {
            $record = $record->toArray();
        }

        $date = $record['datetime'] ?? 'No date set';

        if (true === ($date instanceof DateTimeInterface)) {
            $date = $date->format(DateTimeInterface::ATOM);
        }

        $message = sprintf(
            '[%s] %s: %s',
            $date,
            $record['level_name'] ?? $record['level'] ?? '??',
            $record['message'],
        );

        if (false === empty($record['context']) && true === (bool)Config::get('logs.context')) {
            $message .= ' { ' . arrayToString($record['context']) . ' }';
        }

        $this->stream->write(trim($message) . PHP_EOL);
    }

}
