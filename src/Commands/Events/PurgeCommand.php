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
final class PurgeCommand extends AbstractEventCommand
{
    public const string ROUTE = 'events:purge';

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Bulk delete non-running events using safe filter flags.')
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Allow purging all non-running terminal events when no other filters are provided.',
            )
            ->addOption('include-pending', null, InputOption::VALUE_NONE, 'Include pending events in the purge set.')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Only purge events with this status.')
            ->addOption('event', 'e', InputOption::VALUE_REQUIRED, 'Only purge events matching this event name.')
            ->addOption('reference', 'r', InputOption::VALUE_REQUIRED, 'Only purge events matching this reference.')
            ->addOption(
                'before',
                null,
                InputOption::VALUE_REQUIRED,
                "Only purge events created before this time (for example: 'now', 'yesterday', or '2026-05-12 10:00').",
            )
            ->addOption(
                'after',
                null,
                InputOption::VALUE_REQUIRED,
                "Only purge events created after this time (for example: '1min ago', '2 hours ago', or '2026-05-11 10:00').",
            )
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Delete at most this many matching events, oldest first.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview matches without deleting them.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Delete without confirmation prompt.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        try {
            $query = $this->buildQuery($input);
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>' . $this->escape($e->getMessage()) . '</error>');
            return self::FAILURE;
        }

        if (
            [] === array_diff_key($query, array_flip(['page', 'perpage', 'all', 'direction', 'include_pending']))
            && !(bool) $input->getOption('all')
        ) {
            $output->writeln('<error>Refusing to purge without filters. Re-run with --all to confirm a broad non-running purge.</error>');
            return self::FAILURE;
        }

        $preview = api_request(Method::GET, '/system/events', opts: ['query' => array_filter(
            [
                ...$query,
                'all' => 1,
                'page' => 1,
                'perpage' => null === ag($query, 'limit') ? 1000 : ag($query, 'limit'),
            ],
            static fn(mixed $value): bool => null !== $value,
        )]);

        if (Status::OK !== $preview->status) {
            return $this->apiError($output, $preview);
        }

        $items = array_values(array_filter(
            (array) ag($preview->body, 'items', []),
            static fn(mixed $item): bool => is_array($item) && !in_array((int) ag($item, 'status', -1), [0, 1], true),
        ));

        if ((bool) $input->getOption('include-pending')) {
            $items = array_values(array_filter(
                (array) ag($preview->body, 'items', []),
                static fn(mixed $item): bool => is_array($item) && 1 !== (int) ag($item, 'status', -1),
            ));
        }

        $mode = $this->outputMode($input);
        if ((bool) $input->getOption('dry-run')) {
            if ('table' !== $mode) {
                $this->displayContent(
                    [
                        'matched' => count($items),
                        'limit' => ag($query, 'limit'),
                        'include_pending' => (bool) $input->getOption('include-pending'),
                        'preview' => $items,
                        'dry_run' => true,
                    ],
                    $output,
                    $mode,
                );
                return self::SUCCESS;
            }

            $output->writeln(r('<info>Purge Preview</info> matched {count} event(s)', ['count' => count($items)]));
            $this->renderItems($items, $output, 'No matching events found.');
            return self::SUCCESS;
        }

        if ([] === $items) {
            $output->writeln('<comment>No matching events found.</comment>');
            return self::SUCCESS;
        }

        if (!(bool) $input->getOption('force')) {
            $confirmed = $this->confirm($input, $output, r('Delete {count} matching event(s)? [y/N] ', ['count' => count($items)]));
            if (!$confirmed) {
                $output->writeln('<comment>Aborted.</comment>');
                return self::SUCCESS;
            }
        }

        $deleteQuery = $query;
        unset($deleteQuery['page'], $deleteQuery['perpage'], $deleteQuery['all'], $deleteQuery['direction']);
        $response = api_request(Method::DELETE, '/system/events', opts: ['query' => $deleteQuery]);
        if (Status::OK !== $response->status) {
            return $this->apiError($output, $response);
        }

        if ('table' !== $mode) {
            $this->displayContent($response->body, $output, $mode);
            return self::SUCCESS;
        }

        $output->writeln(r('<info>Deleted {count} event(s).</info>', ['count' => ag($response->body, 'deleted', 0)]));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildQuery(InputInterface $input): array
    {
        $query = [
            'page' => 1,
            'perpage' => 200,
            'all' => 1,
            'direction' => 'asc',
            'include_pending' => (bool) $input->getOption('include-pending') ? 1 : 0,
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

        $limit = $input->getOption('limit');
        if (null !== $limit && '' !== trim((string) $limit)) {
            $query['limit'] = $this->normalizePositiveInteger((string) $limit, 'Limit');
        }

        return $query;
    }
}
