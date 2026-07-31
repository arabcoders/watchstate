<?php

declare(strict_types=1);

namespace App\Commands\Transport;

use App\Libs\Attributes\Route\Cli;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class QueueCommand extends AbstractTransportCommand
{
    public const string ROUTE = 'transport:queue';

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('List items held by the transport queue.')
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Page number.', '1')
            ->addOption('perpage', null, InputOption::VALUE_REQUIRED, 'Items per page.', '25')
            ->addOption('state', 's', InputOption::VALUE_REQUIRED, 'Filter by state [pending|processing|failed].')
            ->addOption('filter', 'f', InputOption::VALUE_REQUIRED, 'Filter by envelope ID or event name.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        try {
            $query = $this->buildQuery($input);
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>' . OutputFormatter::escape($e->getMessage()) . '</error>');
            return self::FAILURE;
        }

        $response = api_request(Method::GET, '/system/transport/queue', opts: ['query' => $query]);
        if (Status::OK !== $response->status) {
            $output->writeln(r('<error>API error. {status}: {message}</error>', [
                'status' => $response->status->value,
                'message' => OutputFormatter::escape((string) ag($response->body, 'error.message', 'Unknown error.')),
            ]));
            return self::FAILURE;
        }

        $mode = strtolower((string) $input->getOption('output'));
        $mode = in_array($mode, self::DISPLAY_OUTPUT, true) ? $mode : 'table';
        if ('table' !== $mode) {
            $this->displayContent($response->body, $output, $mode);
            return self::SUCCESS;
        }

        $paging = (array) ag($response->body, 'paging', []);
        $filter = (array) ag($response->body, 'filter', []);
        $output->writeln('<info>Transport Queue</info>');
        $output->writeln(OutputFormatter::escape(r(
            'page {page} | per-page {perpage} | total {total} | next {next} | prev {previous}',
            [
                'page' => ag($paging, 'page', 1),
                'perpage' => ag($paging, 'perpage', 0),
                'total' => ag($paging, 'total', 0),
                'next' => null === ag($paging, 'next') ? '-' : (string) ag($paging, 'next'),
                'previous' => null === ag($paging, 'previous') ? '-' : (string) ag($paging, 'previous'),
            ],
        )));

        $parts = array_values(array_filter([
            '' !== (string) ag($filter, 'state', '') ? 'state=' . ag($filter, 'state') : null,
            '' !== (string) ag($filter, 'filter', '') ? 'filter="' . ag($filter, 'filter') . '"' : null,
        ]));
        if ([] !== $parts) {
            $output->writeln(OutputFormatter::escape('filters: ' . implode(', ', $parts)));
        }

        $items = (array) ag($response->body, 'items', []);
        if ([] === $items) {
            $output->writeln('No transport items matched the current filters.');
            return self::SUCCESS;
        }

        foreach ($items as $item) {
            $output->writeln(OutputFormatter::escape(r(
                '{id} | {state} | {event} | {created_at}',
                [
                    'id' => ag($item, 'id', ''),
                    'state' => ag($item, 'state', ''),
                    'event' => ag($item, 'event', ''),
                    'created_at' => ag($item, 'created_at', ''),
                ],
            )));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildQuery(InputInterface $input): array
    {
        $state = strtolower(trim((string) $input->getOption('state')));
        if ('' !== $state && false === in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException("Unknown transport state [{$state}].");
        }

        $query = [
            'page' => $this->positiveInteger((string) $input->getOption('page'), 'Page'),
            'perpage' => $this->positiveInteger((string) $input->getOption('perpage'), 'Per-page'),
        ];

        if ('' !== $state) {
            $query['state'] = $state;
        }

        if ('' !== ($filter = trim((string) $input->getOption('filter')))) {
            $query['filter'] = $filter;
        }

        return $query;
    }

    private function positiveInteger(string $value, string $label): int
    {
        $value = trim($value);
        if (1 !== preg_match('/^\d+$/', $value) || (int) $value < 1) {
            throw new InvalidArgumentException(r('{label} must be a positive integer.', ['label' => $label]));
        }

        return (int) $value;
    }
}
