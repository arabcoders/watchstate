<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use DateTimeInterface;
use Monolog\LogRecord;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

final class ConsoleLogFormatter
{
    /**
     * Format a log record or structured log entry for interactive console display.
     *
     * @param LogRecord|array<string,mixed> $record
     */
    public function format(LogRecord|array $record): string
    {
        $entry = true === $record instanceof LogRecord ? $this->fromRecord($record) : $record;
        $severity = strtoupper((string) ag($entry, 'level', '-'));
        $severityText = OutputFormatter::escape($severity);
        $message = $this->messageText($entry);
        $severityColor = $this->severityColor((string) ag($entry, 'level', ''));

        if (null !== $severityColor) {
            $severityText = sprintf('<fg=%s;options=bold>%s</>', $severityColor, $severityText);
        } else {
            $severityText = sprintf('<options=bold>%s</>', $severityText);
        }

        return sprintf(
            '<comment>%s</comment> <info>%s</info> %s <fg=cyan>%s</> %s',
            OutputFormatter::escape($this->formatTimestamp(ag($entry, ['datetime', 'date'], '-'))),
            OutputFormatter::escape($this->hostname($entry)),
            $severityText,
            OutputFormatter::escape((string) ag($entry, ['logger', 'channel'], '-')),
            OutputFormatter::escape($message),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function fromRecord(LogRecord $record): array
    {
        $entry = [
            'datetime' => $record->datetime,
            'level' => $record->level->getName(),
            'logger' => $record->channel,
            'message' => $record->message,
            'fields' => $record->context,
            'source' => [
                'module' => $record->channel,
            ],
            'process' => [
                'name' => PHP_SAPI,
            ],
        ];

        $exception = ag($record->context, 'exception');
        if (true === $exception instanceof Throwable) {
            $entry['exception_message'] = $exception->getMessage();
        }

        return $entry;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function hostname(array $entry): string
    {
        $value = ag(
            $entry,
            [
                'fields.structured.request.name',
                'fields.hostname',
                'fields.route.ip',
                'fields.task_id',
                'fields.command',
                'fields.user.name',
                'fields.user',
                'fields.backend.name',
                'fields.backend',
                'fields.cli.stream',
                'fields.item_id',
                'fields.event_name',
                'source.module',
                'process.name',
                'logger',
                'channel',
            ],
            '-',
        );

        if (false === is_scalar($value)) {
            return '-';
        }

        return '' !== trim((string) $value) ? trim((string) $value) : '-';
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function messageText(array $entry): string
    {
        $message = trim((string) ag($entry, ['message', 'text'], '-'));

        if ('' === $message) {
            return '-';
        }

        if (null !== ($exception = ag($entry, 'exception_message')) && '' !== trim((string) $exception)) {
            return $message . ' [' . trim((string) $exception) . ']';
        }

        return $message;
    }

    private function formatTimestamp(mixed $value): string
    {
        if (true === $value instanceof DateTimeInterface) {
            return make_date($value)->format('m/d, H:i:s');
        }

        $value = (string) $value;

        if ('' === trim($value)) {
            return '-';
        }

        try {
            return make_date($value)->format('m/d, H:i:s');
        } catch (Throwable) {
            return $value;
        }
    }

    private function severityColor(string $severity): ?string
    {
        $level = strtolower(trim($severity));

        if (true === in_array($level, ['emergency', 'alert', 'critical'], true)) {
            return 'bright-red';
        }

        if ('error' === $level) {
            return 'red';
        }

        if ('warning' === $level) {
            return 'yellow';
        }

        if ('notice' === $level) {
            return 'magenta';
        }

        if ('info' === $level) {
            return 'cyan';
        }

        if ('debug' === $level) {
            return 'gray';
        }

        return null;
    }
}
