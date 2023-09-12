<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Original code by Jordi Boggiano <j.boggiano@seld.be>
 */
class LogMessageProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        if (false === str_contains($record->message, '{')) {
            return $record;
        }

        $repl = r_array(
            text: $record->message,
            context: $record->context,
            opts: [
                'log_behavior' => true
            ]
        );

        return $record->with(...$repl);
    }
}
