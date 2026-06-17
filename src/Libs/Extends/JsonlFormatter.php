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
    private const array RESERVED_FIELDS = [
        'exception',
    ];

    private const array SENSITIVE_FIELDS = [
        'apikey',
        'api_key',
        'authorization',
        'cookie',
        'password',
        'secret',
        'set-cookie',
        'token',
    ];

    /**
     * Create a formatter for one-record-per-line JSON logs.
     */
    public function __construct()
    {
        parent::__construct(DateTimeInterface::RFC3339_EXTENDED);

        $this->addJsonEncodeOption(JSON_UNESCAPED_SLASHES);
        $this->addJsonEncodeOption(JSON_UNESCAPED_UNICODE);
        $this->addJsonEncodeOption(JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Format a Monolog record as one compact JSON object followed by a newline.
     */
    public function format(LogRecord $record): string
    {
        return $this->toJson($this->formatRecord($record), true) . PHP_EOL;
    }

    /**
     * Format multiple Monolog records as JSON Lines.
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
     * Check whether a string is a valid JSONL log record.
     *
     * @param string $line The raw line to inspect.
     *
     * @return bool True when the line decodes to a JSON object containing
     *              the required id, datetime, level, logger, and message keys.
     */
    public static function isJsonlRecord(string $line): bool
    {
        $payload = json_decode(trim($line), true, 512, JSON_INVALID_UTF8_IGNORE);

        if (!is_array($payload)) {
            return false;
        }

        foreach (['id', 'datetime', 'level', 'logger'] as $required) {
            if (!isset($payload[$required]) || '' === trim((string) $payload[$required])) {
                return false;
            }
        }

        return array_key_exists('message', $payload);
    }

    /**
     * Format raw values using the same JSONL schema as a Monolog record.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
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
     * @return array<string, mixed>
     */
    private function formatRecord(LogRecord $record): array
    {
        $fields = [];
        $trace = $this->stack($record->context);
        $exceptionData = $this->exceptionData($record->context, $record->message, $trace);
        $source = $this->source($record->context, $record->channel, $exceptionData, $trace);
        $fieldContext = $record->context;

        foreach (self::RESERVED_FIELDS as $field) {
            unset($fieldContext[$field]);
        }

        $this->flattenInto($fields, $fieldContext);
        $this->flattenInto($fields, $record->extra);

        $payload = [
            'id' => generate_uuid(),
            'datetime' => $record->datetime->setTimezone($this->timezone())->format(DateTimeInterface::RFC3339_EXTENDED),
            'level' => strtolower((string) $record->level->getName()),
            'levelno' => $this->syslogLevel($record->level),
            'logger' => $record->channel,
            'message' => $record->message,
            'source' => $source,
            'process' => $this->process($record->context),
            'fields' => [] === $fields ? new stdClass() : $fields,
        ];

        if ([] !== $exceptionData) {
            $payload['exception'] = $exceptionData;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, int|string>
     */
    private function process(array $context): array
    {
        $pid = getmypid();

        return [
            'id' => false === $pid ? 0 : $pid,
            'name' => PHP_SAPI,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $exceptionData
     * @param array<int, array<string, mixed>>|null $trace
     * @return array<string, mixed>
     */
    private function source(array $context, string $channel, array $exceptionData = [], ?array $trace = null): array
    {
        $path = null;
        $line = null;
        $module = $channel;

        $cliModule = ag($context, ['cli.command_name', 'cli.command_class']);
        if (is_scalar($cliModule) && '' !== trim((string) $cliModule)) {
            $module = trim((string) $cliModule);
        }

        if ([] !== $exceptionData) {
            $path = $exceptionData['file'] ?? null;
            $line = $exceptionData['line'] ?? null;
        }

        $function = $this->traceFunction($trace);

        $source = [
            'module' => $module,
        ];

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
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>>|null $trace
     * @return array<string, mixed>
     */
    private function exceptionData(array $context, string $message, ?array $trace = null): array
    {
        $exception = $context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            return $this->normalizeExceptionData(
                [
                    'type' => $exception::class,
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ],
                $trace ?? $this->stack(['trace' => $exception->getTrace()]),
            );
        }

        if (is_array($exception) && [] !== $exception) {
            return $this->normalizeExceptionData([
                'type' => $exception['type'] ?? $exception['kind'] ?? null,
                'message' => $exception['message'] ?? null,
                'file' => $exception['file'] ?? null,
                'line' => $exception['line'] ?? null,
            ], $trace);
        }

        return [];
    }

    /**
     * @param array<string, mixed> $exception
     * @param array<int, array<string, mixed>>|null $trace
     * @return array<string, mixed>
     */
    private function normalizeExceptionData(array $exception, ?array $trace = null): array
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
            $normalized['line'] = is_numeric($exception['line']) ? (int) $exception['line'] : $exception['line'];
        }

        if (null !== $trace) {
            $normalized['trace'] = $trace;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>|null
     */
    private function stack(array $context): ?array
    {
        $trace = $context['trace'] ?? null;

        if (!is_array($trace) || [] === $trace) {
            $exception = $context['exception'] ?? null;

            if ($exception instanceof Throwable) {
                $trace = $exception->getTrace();
            } elseif (is_array($exception) && isset($exception['trace']) && is_array($exception['trace'])) {
                $trace = $exception['trace'];
            }
        }

        if (!is_array($trace) || [] === $trace) {
            return null;
        }

        $normalized = [];

        foreach ($trace as $frame) {
            if (!is_array($frame)) {
                continue;
            }

            $normalizedFrame = $this->normalizeValue($frame);

            if (is_array($normalizedFrame)) {
                $normalized[] = $normalizedFrame;
            }
        }

        return [] === $normalized ? null : $normalized;
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

        $function = trim((string) ($frame['function'] ?? ''));
        $class = trim((string) ($frame['class'] ?? ''));
        $type = trim((string) ($frame['type'] ?? ''));

        if ('' === $function) {
            return null;
        }

        return '' === $class ? $function : $class . $type . $function;
    }

    /**
     * @param array<string, mixed> $output
     * @param array<string, mixed> $data
     */
    private function flattenInto(array &$output, array $data, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $key = (string) $key;
            $path = '' === $prefix ? $key : $prefix . '.' . $key;

            if (true === $this->isSensitivePath($path)) {
                continue;
            }

            if (is_array($value)) {
                if ([] === $value) {
                    $output[$path] = [];
                    continue;
                }

                $this->flattenInto($output, $value, $path);
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

            $normalized = $this->normalizeValue($value);

            if (is_array($normalized)) {
                $this->flattenInto($output, $normalized, $path);
                continue;
            }

            if (is_scalar($normalized) || null === $normalized) {
                $output[$path] = $normalized;
            }
        }
    }

    private function isSensitivePath(string $path): bool
    {
        $segments = explode('.', strtolower($path));

        foreach ($segments as $segment) {
            if (in_array($segment, self::SENSITIVE_FIELDS, true)) {
                return true;
            }

            if (true === str_ends_with($segment, '_token')) {
                return true;
            }
        }

        return false;
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
