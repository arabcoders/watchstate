<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use Monolog\Processor\ProcessorInterface;
use Monolog\Utils;

/**
 * Original code by Jordi Boggiano <j.boggiano@seld.be>
 */
class LogMessageProcessor implements ProcessorInterface
{
    private string $pattern;

    public function __construct(
        private string $tagStart = '%(',
        private string $tagEnd = ')'
    ) {
        $this->pattern = '#' . preg_quote($this->tagStart, '#') . '([\w\d_.]+)' . preg_quote(
                $this->tagEnd,
                '#'
            ) . '#is';
    }

    public function __invoke(array $record): array
    {
        if (false === str_contains($record['message'], $this->tagStart)) {
            return $record;
        }

        $status = preg_match_all($this->pattern, $record['message'], $matches);

        if (false === $status || $status < 1) {
            return $record;
        }

        $replacements = [];

        foreach ($matches[1] as $key) {
            $placeholder = $this->tagStart . $key . $this->tagEnd;

            if (false === str_contains($record['message'], $placeholder)) {
                continue;
            }

            if (false === ag_exists($record['context'] ?? [], $key)) {
                continue;
            }

            $val = ag($record['context'], $key);

            $record['context'] = ag_delete($record['context'], $key);

            if (is_null($val) || is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replacements[$placeholder] = $val;
            } elseif (is_object($val)) {
                $replacements[$placeholder] = '[object ' . Utils::getClass($val) . ']';
            } elseif (is_array($val)) {
                $replacements[$placeholder] = 'array' . Utils::jsonEncode($val, null, true);
            } else {
                $replacements[$placeholder] = '[' . gettype($val) . ']';
            }
        }

        // -- This might be problem in multibyte context.
        $record['message'] = strtr($record['message'], $replacements);

        return $record;
    }
}
