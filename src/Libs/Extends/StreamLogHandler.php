<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\Config;
use DateTimeInterface;
use Monolog\LogRecord;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The StreamLogHandler class is a subclass of ConsoleHandler that handles logging to a stream.
 * It extends the parent class and overrides the write method to format and write log records to the stream.
 */
class StreamLogHandler extends ConsoleHandler
{
    /**
     * Constructor method for the class.
     *
     * @param StreamInterface $stream The Stream object used for logging.
     * @param OutputInterface|null $output (optional) The OutputInterface object to handle the output. Default is null.
     * @param bool $bubble (optional) Flag to determine if the log messages should bubble up the logging hierarchy. Default is true.
     * @param array $levelsMapper (optional) An array that maps log levels to specific handlers. Default is an empty array.
     *
     * @return void
     */
    public function __construct(
        private StreamInterface $stream,
        ?OutputInterface $output = null,
        bool $bubble = true,
        array $levelsMapper = [],
    ) {
        parent::__construct($output, $bubble, $levelsMapper);
    }

    /**
     * Writes a log record to the stream.
     *
     * @param LogRecord|array $record The LogRecord object or an array representing the log record.
     */
    protected function write(LogRecord|array $record): void
    {
        if (true === $record instanceof LogRecord) {
            $record = $record->toArray();
        }

        $date = $record['datetime'] ?? 'No date set';

        if (true === $date instanceof DateTimeInterface) {
            $date = $date->format(DateTimeInterface::ATOM);
        }

        $message = r('[{date}] {level}: {message}', [
            'date' => $date,
            'level' => $record['level_name'] ?? $record['level'] ?? '??',
            'message' => $record['message'],
        ]);

        if (false === empty($record['context']) && true === (bool) Config::get('logs.context')) {
            $message .= ' ' . array_to_json($record['context']);
        }

        $this->stream->write(trim($message) . PHP_EOL);
    }
}
