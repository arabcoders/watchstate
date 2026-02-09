<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Class LogMessageProcessor
 *
 * This class implements the ProcessorInterface and is used to process log messages.
 * It checks if the log message contains curly brackets "{", and if not, it returns the original log record.
 * If the log message contains curly brackets "{", it replaces the placeholders with values from the context
 * using the r_array function and returns the log record with the updated message.
 */
class LogMessageProcessor implements ProcessorInterface
{
    /**
     * Invoke the method with a LogRecord object as parameter.
     *
     * @param LogRecord $record The LogRecord object to be processed.
     *
     * @return LogRecord Returns a modified LogRecord object. if th message contains curly brackets.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        if (false === str_contains($record->message, '{')) {
            return $record;
        }

        $repl = r_array(text: $record->message, context: $record->context, opts: [
            'log_behavior' => true,
        ]);

        return $record->with(...$repl);
    }
}
