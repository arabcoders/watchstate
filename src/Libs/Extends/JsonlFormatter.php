<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use stdClass;
use Throwable;

final class JsonlFormatter extends NormalizerFormatter
{
    private const array SENSITIVE_KEYS = [
        'authorization',
        'cookie',
        'jwt',
        'token',
        'password',
        'passwd',
        'secret',
        'apikey',
        'api_key',
        'accesskey',
        'privatekey',
        'session',
        'bearer',
    ];

    public function __construct()
    {
        parent::__construct(DateTimeInterface::RFC3339_EXTENDED);
        $this->removeJsonEncodeOption(JSON_UNESCAPED_SLASHES);
        $this->addJsonEncodeOption(JSON_UNESCAPED_UNICODE);
        $this->addJsonEncodeOption(JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Format a Monolog record as one JSONL line.
     */
    public function format(LogRecord $record): string
    {
        return $this->toJson($this->formatRecord($record), true) . PHP_EOL;
    }

    /**
     * Format a batch of records as JSONL.
     *
     * @param array<LogRecord> $records
     */
    public function formatBatch(array $records): string
    {
        $lines = [];

        foreach ($records as $record) {
            $lines[] = $this->format($record);
        }

        return implode('', $lines);
    }

    /**
     * Format non-Monolog values with the same JSONL schema.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $extra
     */
    public function formatValues(
        string $channel,
        Level $level,
        string $message,
        array $context = [],
        array $extra = [],
        ?DateTimeInterface $datetime = null,
    ): string {
        $date = $datetime instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($datetime) : new DateTimeImmutable();

        return $this->format(new LogRecord(
            datetime: $date,
            channel: $channel,
            level: $level,
            message: $message,
            context: $context,
            extra: $extra,
        ));
    }

    /**
     * Check whether a line already looks like a structured JSONL log record.
     */
    public static function isJsonlRecord(string $message): bool
    {
        $payload = json_decode(trim($message), true, 512, JSON_INVALID_UTF8_IGNORE);

        if (false === is_array($payload)) {
            return false;
        }

        if (1 === (int) ag($payload, 'schema', 0) && array_key_exists('message', $payload)) {
            return true;
        }

        foreach (['id', 'datetime', 'level'] as $required) {
            if (!isset($payload[$required]) || '' === trim((string) $payload[$required])) {
                return false;
            }
        }

        if (!isset($payload['logger']) && !isset($payload['channel'])) {
            return false;
        }

        return array_key_exists('message', $payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function formatRecord(LogRecord $record): array
    {
        $fields = [];
        $exceptionData = $this->exceptionData($record->context);
        $source = $this->source($record->context, $record->channel, $exceptionData);
        $exceptionMessage = $this->exceptionMessage($exceptionData);
        $exception = $this->exception($exceptionData, $record->context);
        $stack = $this->stack($record->context);
        $processId = getmypid();

        $this->flattenInto($fields, $record->context, skip: [
            'id',
            'trace',
            'e_file',
            'e_line',
            'exception.trace',
            'error.trace',
            'structured.exception.trace',
        ]);
        $this->flattenInto($fields, $record->extra);

        $payload = [
            'id' => (string) ($record->context['id'] ?? generate_uuid()),
            'datetime' => $record->datetime->setTimezone($this->timezone())->format(DateTimeInterface::RFC3339_EXTENDED),
            'level' => strtolower((string) $record->level->getName()),
            'levelno' => $this->syslogLevel($record->level),
            'logger' => $record->channel,
            'message' => $record->message,
            'source' => $source,
            'process' => [
                'id' => false === $processId ? 0 : $processId,
                'name' => PHP_SAPI,
            ],
            'fields' => [] === $fields ? new stdClass() : $fields,
        ];

        if (null !== $exception) {
            $payload['exception'] = $exception;
        }

        if (null !== $exceptionMessage) {
            $payload['exception_message'] = $exceptionMessage;
        }

        if (null !== $stack) {
            $payload['stack'] = $stack;
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $exceptionData
     * @return array<string,mixed>
     */
    private function source(array $context, string $channel, array $exceptionData = []): array
    {
        $path = null;
        $line = null;
        $function = null;
        $module = $channel;

        if (isset($context['cli']) && is_array($context['cli'])) {
            $module = (string) ($context['cli']['command_name'] ?? $context['cli']['command_class'] ?? $module);
        }

        if ([] !== $exceptionData) {
            $path = $exceptionData['file'] ?? null;
            $line = $exceptionData['line'] ?? null;
        }

        $function = $this->traceFunction($this->traceData($context));
        $path ??= $context['e_file'] ?? $context['file'] ?? null;
        $line ??= $context['e_line'] ?? $context['line'] ?? null;

        $source = ['module' => $module];

        if (is_string($path) && '' !== trim($path)) {
            $source['path'] = $path;
            $source['file'] = basename($path);
        }

        if (is_int($line) || is_numeric($line)) {
            $source['line'] = (int) $line;
        }

        if (is_string($function) && '' !== trim($function)) {
            $source['function'] = trim($function);
        }

        return $source;
    }

    /**
     * @param array<string,mixed> $exceptionData
     */
    private function exceptionMessage(array $exceptionData): ?string
    {
        if ([] === $exceptionData) {
            return null;
        }

        $type = $exceptionData['type'] ?? null;
        $message = $exceptionData['message'] ?? null;

        if (!is_string($type) || '' === trim($type)) {
            return null;
        }

        $parts = explode('\\', $type);
        $type = (string) end($parts);
        $message = is_string($message) ? trim($message) : '';

        return '' === $message ? $type : sprintf('%s: %s', $type, $message);
    }

    /**
     * @param array<string,mixed> $exceptionData
     * @param array<string,mixed> $context
     */
    private function exception(array $exceptionData, array $context): ?string
    {
        if ([] === $exceptionData) {
            return null;
        }

        $type = $exceptionData['type'] ?? null;

        if (!is_string($type) || '' === trim($type)) {
            return null;
        }

        $headline = $this->exceptionHeadline($exceptionData) ?? trim($type);
        $trace = $this->exceptionTrace($context);

        if (is_string($trace) && '' !== trim($trace)) {
            return $headline . PHP_EOL . trim($trace);
        }

        $file = $exceptionData['file'] ?? null;
        $line = $exceptionData['line'] ?? null;

        if (is_string($file) && '' !== trim($file)) {
            $location = $file;

            if (is_int($line) || is_numeric($line)) {
                $location .= ':' . (int) $line;
            }

            return sprintf('%s (%s)', $headline, $location);
        }

        return $headline;
    }

    /**
     * @param array<string,mixed> $exceptionData
     */
    private function exceptionHeadline(array $exceptionData): ?string
    {
        if ([] === $exceptionData) {
            return null;
        }

        $type = $exceptionData['type'] ?? null;
        $message = $exceptionData['message'] ?? null;

        if (!is_string($type) || '' === trim($type)) {
            return null;
        }

        $message = is_string($message) ? trim($message) : '';

        return '' === $message ? trim($type) : sprintf('%s: %s', trim($type), $message);
    }

    /**
     * @param array<string,mixed> $context
     * @return array{type?:string,message?:string,file?:string,line?:int|string|float|null}
     */
    private function exceptionData(array $context): array
    {
        $exception = ag($context, 'structured.exception');

        if (is_array($exception) && [] !== $exception) {
            return $this->normalizeExceptionData($exception);
        }

        $exception = ag($context, 'exception');

        if ($exception instanceof Throwable) {
            return $this->normalizeExceptionData([
                'type' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }

        if (is_array($exception) && [] !== $exception) {
            return $this->normalizeExceptionData([
                'type' => ag($exception, ['type', 'kind']),
                'message' => ag($exception, 'message'),
                'file' => ag($exception, 'file'),
                'line' => ag($exception, 'line'),
            ]);
        }

        $error = ag($context, 'error');
        if (is_array($error) && [] !== $error) {
            return $this->normalizeExceptionData([
                'type' => ag($error, ['type', 'kind']),
                'message' => ag($error, ['message', 'error']),
                'file' => ag($error, 'file'),
                'line' => ag($error, 'line'),
            ]);
        }

        if (isset($context['kind']) || isset($context['class']) || isset($context['file']) || isset($context['line'])) {
            return $this->normalizeExceptionData([
                'type' => $context['kind'] ?? $context['class'] ?? null,
                'message' => $context['message'] ?? $context['error'] ?? null,
                'file' => $context['file'] ?? null,
                'line' => $context['line'] ?? null,
            ]);
        }

        if (is_string($exception) && '' !== trim($exception)) {
            return $this->normalizeExceptionData([
                'type' => trim($exception),
                'message' => $context['message'] ?? null,
                'file' => $context['e_file'] ?? null,
                'line' => $context['e_line'] ?? null,
            ]);
        }

        return [];
    }

    /**
     * @param array<string,mixed> $exception
     * @return array{type?:string,message?:string,file?:string,line?:int|string|float|null}
     */
    private function normalizeExceptionData(array $exception): array
    {
        $normalized = [];

        if ('' !== trim((string) ($exception['type'] ?? ''))) {
            $normalized['type'] = trim((string) $exception['type']);
        }

        if ('' !== trim((string) ($exception['message'] ?? ''))) {
            $normalized['message'] = trim((string) $exception['message']);
        }

        if ('' !== trim((string) ($exception['file'] ?? ''))) {
            $normalized['file'] = trim((string) $exception['file']);
        }

        if (isset($exception['line']) && '' !== trim((string) $exception['line'])) {
            $normalized['line'] = $exception['line'];
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function stack(array $context): ?string
    {
        $trace = $this->traceData($context);

        if (is_string($trace) && '' !== trim($trace)) {
            return $trace;
        }

        if (!is_array($trace) || [] === $trace) {
            return null;
        }

        return $this->toJson($trace, true);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function exceptionTrace(array $context): ?string
    {
        $trace = $this->traceData($context);

        if (is_string($trace) && '' !== trim($trace)) {
            return trim($trace);
        }

        if (!is_array($trace) || [] === $trace) {
            return null;
        }

        $lines = [];

        foreach ($trace as $index => $frame) {
            if (!is_array($frame)) {
                continue;
            }

            $prefix = '#' . $index;
            $call = $this->traceCall($frame);
            $location = $this->traceLocation($frame);

            if ('' !== $location && '' !== $call) {
                $lines[] = sprintf('%s %s at %s', $prefix, $call, $location);
                continue;
            }

            if ('' !== $call) {
                $lines[] = sprintf('%s %s', $prefix, $call);
                continue;
            }

            if ('' !== $location) {
                $lines[] = sprintf('%s %s', $prefix, $location);
            }
        }

        return [] === $lines ? null : implode(PHP_EOL, $lines);
    }

    private function traceData(array $context): mixed
    {
        return ag($context, ['trace', 'exception.trace', 'error.trace', 'structured.exception.trace']);
    }

    private function traceFunction(mixed $trace): ?string
    {
        if (!is_array($trace) || [] === $trace) {
            return null;
        }

        $frame = $trace[0] ?? null;

        if (!is_array($frame)) {
            return null;
        }

        $call = $this->traceCall($frame);

        return '' === $call ? null : $call;
    }

    /**
     * @param array<string,mixed> $frame
     */
    private function traceCall(array $frame): string
    {
        $function = trim((string) ($frame['function'] ?? ''));
        $class = trim((string) ($frame['class'] ?? ''));
        $type = trim((string) ($frame['type'] ?? ''));

        if ('' === $function) {
            return '';
        }

        return '' === $class ? $function : $class . $type . $function;
    }

    /**
     * @param array<string,mixed> $frame
     */
    private function traceLocation(array $frame): string
    {
        $file = trim((string) ($frame['file'] ?? ''));

        if ('' === $file) {
            return '';
        }

        if (isset($frame['line']) && '' !== trim((string) $frame['line'])) {
            return $file . ':' . (int) $frame['line'];
        }

        return $file;
    }

    /**
     * @param array<string,mixed> $output
     * @param array<string,mixed> $data
     * @param list<string> $skip
     */
    private function flattenInto(array &$output, array $data, string $prefix = '', array $skip = []): void
    {
        foreach ($data as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $key = (string) $key;

            $path = '' === $prefix ? $key : $prefix . '.' . $key;

            if (true === $this->shouldSkipPath($path, $skip)) {
                continue;
            }

            if ($this->isSensitivePath($path)) {
                $output[$path] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                if ([] === $value) {
                    $output[$path] = [];
                    continue;
                }

                $this->flattenInto($output, $value, $path, $skip);
                continue;
            }

            if ($value instanceof DateTimeInterface) {
                $output[$path] = $value->format(DateTimeInterface::RFC3339_EXTENDED);
                continue;
            }

            if ($value instanceof Throwable) {
                $output[$path] = sprintf('%s: %s', $value::class, $value->getMessage());
                continue;
            }

            if (is_object($value)) {
                $normalizedObject = $this->normalizeValue($value);

                if (is_array($normalizedObject)) {
                    $this->flattenInto($output, $normalizedObject, $path, $skip);
                    continue;
                }

                if (is_scalar($normalizedObject) || null === $normalizedObject) {
                    $output[$path] = is_string($normalizedObject) ? $this->redactString($normalizedObject) : $normalizedObject;
                }

                continue;
            }

            $normalized = $this->normalizeValue($value);

            if (is_string($normalized)) {
                $normalized = $this->redactString($normalized);
            }

            if (is_scalar($normalized) || null === $normalized || [] === $normalized) {
                $output[$path] = $normalized;
            }
        }
    }

    /**
     * @param list<string> $skip
     */
    private function shouldSkipPath(string $path, array $skip): bool
    {
        foreach ($skip as $skipPath) {
            if ($path === $skipPath || true === str_starts_with($path, $skipPath . '.')) {
                return true;
            }
        }

        return false;
    }

    private function isSensitivePath(string $path): bool
    {
        $normalizedPath = strtolower(str_replace(['-', '_', '.'], '', $path));

        foreach (self::SENSITIVE_KEYS as $key) {
            if (str_contains($normalizedPath, strtolower(str_replace(['-', '_'], '', $key)))) {
                return true;
            }
        }

        return false;
    }

    private function redactString(string $value): string
    {
        if (!str_contains($value, '?')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/([?&])([^=&]+)=([^&]*)/',
            fn(array $matches): string => (
                $matches[1] . $matches[2] . '=' . ($this->isSensitivePath((string) $matches[2]) ? '[redacted]' : $matches[3])
            ),
            $value,
        );
    }

    private function timezone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }

    private function syslogLevel(Level $level): int
    {
        return match ($level) {
            Level::Debug => LOG_DEBUG,
            Level::Info => LOG_INFO,
            Level::Notice => LOG_NOTICE,
            Level::Warning => LOG_WARNING,
            Level::Error => LOG_ERR,
            Level::Critical => LOG_CRIT,
            Level::Alert => LOG_ALERT,
            Level::Emergency => LOG_EMERG,
        };
    }
}
