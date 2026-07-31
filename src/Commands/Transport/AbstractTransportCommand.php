<?php

declare(strict_types=1);

namespace App\Commands\Transport;

use App\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractTransportCommand extends Command
{
    protected const array STATES = ['pending', 'processing', 'failed'];

    protected function outputMode(InputInterface $input): string
    {
        $mode = strtolower((string) $input->getOption('output'));

        return in_array($mode, self::DISPLAY_OUTPUT, true) ? $mode : 'table';
    }

    protected function apiError(OutputInterface $output, mixed $response): int
    {
        $output->writeln(r('<error>API error. {status}: {message}</error>', [
            'status' => $response->status->value,
            'message' => OutputFormatter::escape((string) ag($response->body, 'error.message', 'Unknown error.')),
        ]));

        return self::FAILURE;
    }

    protected function parseSections(string $value): array
    {
        $sections = array_values(array_filter(array_map(
            static fn(string $section): string => strtolower(trim($section)),
            explode(',', $value),
        )));

        if ([] === $sections) {
            $sections = ['summary', 'data', 'options'];
        }

        foreach ($sections as $section) {
            if (!in_array($section, ['summary', 'data', 'options', 'entry', 'all'], true)) {
                throw new InvalidArgumentException(r('Unknown section [{section}].', ['section' => $section]));
            }
        }

        return $sections;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string> $sections
     */
    protected function renderView(array $item, array $sections, OutputInterface $output): void
    {
        $showAll = in_array('all', $sections, true);

        if ($showAll || in_array('summary', $sections, true)) {
            $output->writeln('<info>Summary</info>');
            $output->writeln($this->escape(implode(' | ', array_filter(
                [
                    '#' . (string) ag($item, 'id', ''),
                    strtoupper((string) ag($item, 'state', 'unknown')),
                    (string) ag($item, 'event', ''),
                    (string) ag($item, 'created_at', ''),
                ],
                static fn(string $part): bool => '' !== trim($part),
            ))));
            $output->writeln('');
        }

        if ($showAll || in_array('data', $sections, true)) {
            $this->renderJsonSection('Data', (array) ag($item, 'data', []), $output);
        }

        if ($showAll || in_array('options', $sections, true)) {
            $this->renderJsonSection('Options', (array) ag($item, 'options', []), $output);
        }

        if ($showAll || in_array('entry', $sections, true)) {
            $output->writeln('<info>Entry</info>');
            $output->writeln($this->escape($this->encodeJson($item)));
        }
    }

    /**
     * @param array<string,mixed> $value
     */
    private function renderJsonSection(string $title, array $value, OutputInterface $output): void
    {
        if ([] === $value) {
            return;
        }

        $output->writeln('<info>' . $title . '</info>');
        $output->writeln($this->escape($this->encodeJson($value)));
        $output->writeln('');
    }

    /**
     * @param array<string,mixed> $value
     */
    private function encodeJson(array $value): string
    {
        return (string) json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE,
        );
    }

    protected function escape(string $value): string
    {
        return OutputFormatter::escape($value);
    }
}
