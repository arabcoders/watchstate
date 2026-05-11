<?php

declare(strict_types=1);

namespace App\Commands\Events;

use App\Command;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use InvalidArgumentException;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

abstract class AbstractEventCommand extends Command
{
    protected const array STATUSES = ['pending', 'running', 'success', 'failed', 'cancelled'];

    protected function confirm(InputInterface $input, OutputInterface $output, string $question, bool $default = false): bool
    {
        $helper = $this->getHelper('question');
        assert($helper instanceof QuestionHelper, '$helper must be instance of ' . QuestionHelper::class);

        return (bool) $helper->ask($input, $output, new ConfirmationQuestion($question, $default));
    }

    protected function outputMode(InputInterface $input): string
    {
        $mode = strtolower((string) $input->getOption('output'));
        return in_array($mode, self::DISPLAY_OUTPUT, true) ? $mode : 'table';
    }

    protected function normalizePositiveInteger(string $value, string $label): int
    {
        $value = trim($value);
        if (1 !== preg_match('/^\d+$/', $value)) {
            throw new InvalidArgumentException(r('{label} must be a positive integer.', ['label' => $label]));
        }

        $number = (int) $value;
        if ($number < 1) {
            throw new InvalidArgumentException(r('{label} must be greater than zero.', ['label' => $label]));
        }

        return $number;
    }

    protected function normalizeDate(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        $timestamp = strtotime($value);
        if (false === $timestamp) {
            throw new InvalidArgumentException(r(
                "Unable to parse time expression '{value}'. Use formats that PHP strtotime() accepts, such as '1min ago', '15 minutes ago', '2 hours ago', 'now', 'yesterday', or '2026-05-12 10:00'.",
                ['value' => $value],
            ));
        }

        return make_date($timestamp)->format('Y-m-d H:i:s');
    }

    protected function requireStatus(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = strtolower(trim($value));
        if ('' === $value) {
            return null;
        }

        if (ctype_digit($value)) {
            return $value;
        }

        if (in_array($value, self::STATUSES, true)) {
            return $value;
        }

        throw new InvalidArgumentException(r('Unknown event status [{status}].', ['status' => $value]));
    }

    protected function apiError(OutputInterface $output, mixed $response): int
    {
        $output->writeln(r('<error>API error. {status}: {message}</error>', [
            'status' => $response->status->value,
            'message' => ag($response->body, 'error.message', 'Unknown error.'),
        ]));

        return self::FAILURE;
    }

    protected function requestLatest(): mixed
    {
        return api_request(Method::GET, '/system/events', opts: [
            'query' => [
                'page' => 1,
                'perpage' => 1,
                'all' => 1,
            ],
        ]);
    }

    protected function resolveEventId(string $id, OutputInterface $output): ?string
    {
        $id = trim($id);

        if ('' === $id) {
            return null;
        }

        if (in_array(strtolower($id), ['latest', 'last', 'newest'], true)) {
            $response = $this->requestLatest();
            if (Status::OK !== $response->status) {
                $this->apiError($output, $response);
                return null;
            }

            $item = ag($response->body, 'items.0');
            if (!is_array($item) || '' === trim((string) ag($item, 'id', ''))) {
                $output->writeln('<error>No events are available.</error>');
                return null;
            }

            return (string) ag($item, 'id');
        }

        if (1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            return $id;
        }

        $response = api_request(Method::GET, '/system/events', opts: [
            'query' => [
                'page' => 1,
                'perpage' => 1000,
                'all' => 1,
            ],
        ]);

        if (Status::OK !== $response->status) {
            $this->apiError($output, $response);
            return null;
        }

        $items = array_values(array_filter(
            (array) ag($response->body, 'items', []),
            static fn(mixed $item): bool => is_array($item) && $id === (string) ag($item, 'display_id', ''),
        ));

        if (1 === count($items)) {
            return (string) ag($items[0], 'id');
        }

        $output->writeln(
            0 === count($items)
                ? r('<error>No event matched id [{id}].</error>', ['id' => $id])
                : r('<error>Short event id [{id}] is ambiguous.</error>', ['id' => $id]),
        );

        return null;
    }

    protected function renderItems(array $items, OutputInterface $output, string $empty = 'No events found.'): void
    {
        if ([] === $items) {
            $output->writeln('<comment>' . $this->escape($empty) . '</comment>');
            return;
        }

        foreach (array_values($items) as $index => $item) {
            $this->renderItemBlock($item, $output, $index + 1);

            if (($index + 1) < count($items)) {
                $output->writeln('');
            }
        }
    }

    protected function renderItemBlock(array $item, OutputInterface $output, ?int $index = null): void
    {
        $prefix = null === $index ? '-' : $index . '.';
        $status = strtoupper((string) ag($item, 'status_name', ag($item, 'status', 'unknown')));
        $event = trim((string) ag($item, 'event', ''));
        $reference = trim((string) ag($item, 'reference', ''));
        $attempts = (int) ag($item, 'attempts', 0);
        $delay = ag($item, 'delay_by');

        $output->writeln($this->escape(r('{prefix} [{status}] #{id} {event}', [
            'prefix' => $prefix,
            'status' => $status,
            'id' => (string) ag($item, 'display_id', ag($item, 'id', '?')),
            'event' => '' === $event ? '[no event name]' : $event,
        ])));

        $meta = [
            'created ' . $this->formatWhen((string) ag($item, 'created_at', '')),
            'updated ' . $this->formatWhen((string) ag($item, 'updated_at', '')),
            1 === $attempts ? '1 attempt' : $attempts . ' attempts',
        ];

        if ('' !== $reference) {
            $meta[] = 'ref ' . $reference;
        }

        if (null !== $delay && '' !== trim((string) $delay)) {
            $meta[] = 'delay ' . $delay . 's';
        }

        $output->writeln('   ' . $this->escape(implode(' | ', $meta)));
        $output->writeln('   ' . $this->escape('uuid ' . (string) ag($item, 'id', '-')));
    }

    protected function renderView(array $item, array $sections, OutputInterface $output): void
    {
        $showAll = in_array('all', $sections, true);

        if ($showAll || in_array('summary', $sections, true)) {
            $output->writeln('<info>Summary</info>');
            $summary = [
                '#' . (string) ag($item, 'display_id', ''),
                strtoupper((string) ag($item, 'status_name', ag($item, 'status', 'unknown'))),
                (string) ag($item, 'event', ''),
                $this->formatWhen((string) ag($item, 'created_at', '')),
            ];
            $output->writeln($this->escape(implode(' | ', array_filter($summary, static fn(string $part): bool => '' !== trim($part)))));

            $extra = array_filter([
                '' !== trim((string) ag($item, 'reference', '')) ? 'ref ' . (string) ag($item, 'reference') : null,
                'uuid ' . (string) ag($item, 'id', ''),
                (int) ag($item, 'attempts', 0) . ' attempts',
                null !== ag($item, 'delay_by') && '' !== trim((string) ag($item, 'delay_by'))
                    ? 'delay ' . (string) ag($item, 'delay_by') . 's'
                    : null,
                'updated ' . $this->formatWhen((string) ag($item, 'updated_at', '')),
            ]);
            if ([] !== $extra) {
                $output->writeln($this->escape(implode(' | ', $extra)));
            }
            $output->writeln('');
        }

        if ($showAll || in_array('data', $sections, true)) {
            $data = ag($item, 'event_data', []);
            if (is_array($data) && [] !== $data) {
                $output->writeln('<info>Data</info>');
                $output->writeln($this->escape($this->encodeJson($data)));
                $output->writeln('');
            }
        }

        if ($showAll || in_array('options', $sections, true)) {
            $data = ag($item, 'options', []);
            if (is_array($data) && [] !== $data) {
                $output->writeln('<info>Options</info>');
                $output->writeln($this->escape($this->encodeJson($data)));
                $output->writeln('');
            }
        }

        if ($showAll || in_array('logs', $sections, true)) {
            $logs = ag($item, 'logs', []);
            if (is_array($logs) && [] !== $logs) {
                $output->writeln('<info>Logs</info>');
                foreach ($logs as $log) {
                    $date = trim((string) ag($log, 'date', ''));
                    $text = trim((string) ag($log, 'text', ''));
                    $output->writeln($this->escape('' === $date ? $text : '[' . $date . '] ' . $text));
                }
                $output->writeln('');
            }
        }

        if ($showAll || in_array('entry', $sections, true)) {
            $output->writeln('<info>Entry</info>');
            $output->writeln($this->escape($this->encodeJson($item)));
        }
    }

    protected function parseSections(string $value): array
    {
        $sections = array_values(array_filter(array_map(
            static fn(string $section): string => strtolower(trim($section)),
            explode(',', $value),
        )));

        if ([] === $sections) {
            $sections = ['summary', 'data', 'options', 'logs'];
        }

        foreach ($sections as $section) {
            if (!in_array($section, ['summary', 'data', 'options', 'logs', 'entry', 'all'], true)) {
                throw new InvalidArgumentException(r('Unknown section [{section}].', ['section' => $section]));
            }
        }

        return $sections;
    }

    protected function formatWhen(string $value): string
    {
        if ('' === trim($value)) {
            return '-';
        }

        $date = make_date($value);
        return $date->format('Y-m-d H:i:s T') . ' (' . $this->formatAge($date->getTimestamp()) . ')';
    }

    protected function formatAge(int $timestamp): string
    {
        $seconds = time() - $timestamp;
        $future = $seconds < 0;
        $seconds = abs($seconds);

        if ($seconds < 60) {
            $label = $seconds . 's';
        } elseif ($seconds < 3600) {
            $label = (int) floor($seconds / 60) . 'm';
        } elseif ($seconds < 86_400) {
            $label = (int) floor($seconds / 3600) . 'h';
        } elseif ($seconds < 604_800) {
            $label = (int) floor($seconds / 86_400) . 'd';
        } else {
            $label = (int) floor($seconds / 604_800) . 'w';
        }

        return $future ? 'in ' . $label : $label . ' ago';
    }

    protected function escape(string $value): string
    {
        return OutputFormatter::escape($value);
    }

    protected function encodeJson(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE,
        );
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestOptionValuesFor('status')) {
            $currentValue = strtolower($input->getCompletionValue());
            $suggestions->suggestValues(array_values(array_filter(
                self::STATUSES,
                static fn(string $status): bool => '' === $currentValue || str_starts_with($status, $currentValue),
            )));
        }
    }
}
