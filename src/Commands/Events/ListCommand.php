<?php

declare(strict_types=1);

namespace App\Commands\Events;

use App\Libs\Attributes\Route\Cli;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class ListCommand extends AbstractEventCommand
{
    public const string ROUTE = 'events:list';

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('List stored events.')
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Page number.', '1')
            ->addOption('perpage', null, InputOption::VALUE_REQUIRED, 'Items per page.', '25')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Show all events, not just active/problem states.')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Filter by status [pending|running|success|failed|cancelled].')
            ->addOption('event', 'e', InputOption::VALUE_REQUIRED, 'Filter by event name.')
            ->addOption('reference', 'r', InputOption::VALUE_REQUIRED, 'Filter by event reference.')
            ->addOption(
                'before',
                null,
                InputOption::VALUE_REQUIRED,
                "Only show events created before this time (for example: 'now', 'yesterday', or '2026-05-12 10:00').",
            )
            ->addOption(
                'after',
                null,
                InputOption::VALUE_REQUIRED,
                "Only show events created after this time (for example: '1min ago', '2 hours ago', or '2026-05-11 10:00').",
            )
            ->addOption('direction', 'd', InputOption::VALUE_REQUIRED, 'Sort direction [asc|desc].', 'desc');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        try {
            $query = $this->buildQuery($input);
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>' . $this->escape($e->getMessage()) . '</error>');
            return self::FAILURE;
        }

        $response = api_request(Method::GET, '/system/events', opts: ['query' => $query]);
        if (Status::OK !== $response->status) {
            return $this->apiError($output, $response);
        }

        $mode = $this->outputMode($input);
        if ('table' !== $mode) {
            $this->displayContent($response->body, $output, $mode);
            return self::SUCCESS;
        }

        $paging = ag($response->body, 'paging', []);
        $filter = ag($response->body, 'filter', []);

        $output->writeln(r('<info>Events</info> {scope}', [
            'scope' => $this->escape(true === (bool) ag($filter, 'all', false) ? 'all events' : 'pending, failed, and cancelled events'),
        ]));
        $output->writeln($this->escape(r(
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
            true === (bool) ag($filter, 'all', false) ? 'scope=all' : 'scope=active',
            '' !== trim((string) ag($filter, 'status', '')) ? 'status=' . ag($filter, 'status') : null,
            '' !== trim((string) ag($filter, 'event', '')) ? 'event="' . ag($filter, 'event') . '"' : null,
            '' !== trim((string) ag($filter, 'reference', '')) ? 'reference="' . ag($filter, 'reference') . '"' : null,
            '' !== trim((string) ag($filter, 'before', '')) ? 'before=' . ag($filter, 'before') : null,
            '' !== trim((string) ag($filter, 'after', '')) ? 'after=' . ag($filter, 'after') : null,
            'direction=' . (string) ag($filter, 'direction', 'desc'),
        ]));
        $output->writeln($this->escape('filters: ' . implode(', ', $parts)));

        $this->renderItems((array) ag($response->body, 'items', []), $output, 'No events matched the current filters.');

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildQuery(InputInterface $input): array
    {
        $query = [
            'page' => $this->normalizePositiveInteger((string) $input->getOption('page'), 'Page'),
            'perpage' => $this->normalizePositiveInteger((string) $input->getOption('perpage'), 'Per-page'),
            'all' => (bool) $input->getOption('all') ? 1 : 0,
            'direction' => 'asc' === strtolower(trim((string) $input->getOption('direction'))) ? 'asc' : 'desc',
        ];

        if (null !== ($status = $this->requireStatus($input->getOption('status')))) {
            $query['status'] = $status;
        }

        foreach (['event', 'reference'] as $key) {
            $value = trim((string) $input->getOption($key));
            if ('' !== $value) {
                $query[$key] = $value;
            }
        }

        if (null !== ($before = $this->normalizeDate($input->getOption('before')))) {
            $query['before'] = $before;
        }

        if (null !== ($after = $this->normalizeDate($input->getOption('after')))) {
            $query['after'] = $after;
        }

        return $query;
    }
}
