<?php

declare(strict_types=1);

namespace App\Commands\History;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

#[Cli(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const string ROUTE = 'history:list';

    /**
     * @var array<string>
     */
    private const array FILTERS = [
        'id',
        'via',
        'year',
        'type',
        'title',
        'season',
        'episode',
    ];

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('List history items.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user.', 'main')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number.', '1')
            ->addOption('perpage', null, InputOption::VALUE_REQUIRED, 'Items per page.', '12')
            ->addOption('sort', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sort by field[:asc|desc].')
            ->addOption('with-duplicates', null, InputOption::VALUE_NONE, 'Include duplicate reference ids.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Filter by local history id.')
            ->addOption('played', null, InputOption::VALUE_NONE, 'Only show played items.')
            ->addOption('unplayed', null, InputOption::VALUE_NONE, 'Only show unplayed items.')
            ->addOption('via', 'b', InputOption::VALUE_REQUIRED, 'Filter by backend name.')
            ->addOption('year', 'y', InputOption::VALUE_REQUIRED, 'Filter by release year.')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filter by content type [movie|episode].')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Filter by content title.')
            ->addOption('season', null, InputOption::VALUE_REQUIRED, 'Filter by season number.')
            ->addOption('episode', null, InputOption::VALUE_REQUIRED, 'Filter by episode number.')
            ->addOption(
                'query',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Advanced query item in key=value format. Can be used multiple times.',
            )
            ->setHelp(
                r(
                    <<<HELP

                        List history items using the same filters supported by the internal history API.

                        Examples:

                        {cmd} <cmd>{route}</cmd>
                        {cmd} <cmd>{route}</cmd> <flag>--title</flag> <value>Foundation</value> <flag>--played</flag>
                        {cmd} <cmd>{route}</cmd> <flag>--query</flag> <value>parent=tvdb://121361</value> <flag>--query</flag> <value>season=1</value>
                        {cmd} <cmd>{route}</cmd> <flag>--sort</flag> <value>updated_at:desc</value> <flag>--output</flag> <value>json</value>

                        HELP,
                    [
                        'cmd' => trim(command_context()),
                        'route' => self::ROUTE,
                    ],
                ),
            );
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $mode = strtolower((string) $input->getOption('output'));
        if (!in_array($mode, self::DISPLAY_OUTPUT, true)) {
            $mode = 'table';
        }

        try {
            $query = $this->buildQuery($input);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(r('<error>{message}</error>', [
                'message' => $e->getMessage(),
            ]));

            return self::FAILURE;
        }

        $opts = [
            'query' => $query,
            'headers' => [
                'X-User' => (string) $input->getOption('user'),
            ],
        ];

        $response = api_request(Method::GET, '/history', opts: $opts);

        if (Status::NOT_FOUND === $response->status && 'table' === $mode) {
            $output->writeln('<comment>No history items matched.</comment>');
            return self::SUCCESS;
        }

        if (Status::OK !== $response->status) {
            $output->writeln(r('<error>API error. {status}: {message}</error>', [
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.'),
            ]));

            return self::FAILURE;
        }

        if ('table' !== $mode) {
            $this->displayContent($response->body, $output, $mode);
            return self::SUCCESS;
        }

        $history = ag($response->body, 'history', []);
        if ([] === $history) {
            $output->writeln('<comment>No history items matched.</comment>');
            return self::SUCCESS;
        }

        $this->displayContent(array_map($this->toTableRow(...), $history), $output, 'table');

        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestOptionValuesFor('type')) {
            $currentValue = $input->getCompletionValue();
            $suggestions->suggestValues(array_values(array_filter(
                [
                    'movie',
                    'episode',
                ],
                static fn(string $type) => '' === $currentValue || str_starts_with($type, $currentValue),
            )));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildQuery(iInput $input): array
    {
        $query = $this->parseKeyValueList((array) $input->getOption('query'));

        $page = $this->normalizePositiveInteger((string) $input->getOption('page'), 'Page');
        $perpage = $this->normalizeInteger((string) $input->getOption('perpage'), 'Per-page');

        $query['page'] = $page;
        $query['perpage'] = $perpage;

        foreach (self::FILTERS as $filter) {
            $value = $input->getOption($filter);
            if (null === $value || '' === trim((string) $value)) {
                continue;
            }

            $query[$filter] = trim((string) $value);
        }

        if (true === $input->getOption('played') && true === $input->getOption('unplayed')) {
            throw new \InvalidArgumentException('The --played and --unplayed options cannot be used together.');
        }

        if ($input->getOption('played')) {
            $query['watched'] = 1;
        }

        if ($input->getOption('unplayed')) {
            $query['watched'] = 0;
        }

        if ($input->getOption('with-duplicates')) {
            $query['with_duplicates'] = 1;
        }

        $sort = array_values(array_filter(
            array_map(static fn(string $value) => trim($value), (array) $input->getOption('sort')),
            static fn(string $value) => '' !== $value,
        ));

        if ([] !== $sort) {
            $query['sort'] = $sort;
        }

        return $query;
    }

    /**
     * @param array<int,string> $items
     *
     * @return array<string,string>
     */
    private function parseKeyValueList(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $pair = explode('=', $item, 2);
            if (2 !== count($pair)) {
                throw new \InvalidArgumentException(r("Invalid key=value input '{item}'.", ['item' => $item]));
            }

            [$key, $value] = $pair;
            $key = trim($key);

            if ('' === $key) {
                throw new \InvalidArgumentException('Query key cannot be empty.');
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function normalizePositiveInteger(string $value, string $label): int
    {
        $value = trim($value);
        if (1 !== preg_match('/^\d+$/', $value)) {
            throw new \InvalidArgumentException(r('{label} must be a positive integer.', ['label' => $label]));
        }

        $number = (int) $value;
        if ($number < 1) {
            throw new \InvalidArgumentException(r('{label} must be greater than zero.', ['label' => $label]));
        }

        return $number;
    }

    private function normalizeInteger(string $value, string $label): int
    {
        $value = trim($value);
        if (1 !== preg_match('/^-?\d+$/', $value)) {
            throw new \InvalidArgumentException(r('{label} must be an integer.', ['label' => $label]));
        }

        return (int) $value;
    }

    /**
     * @param array<string,mixed> $item
     *
     * @return array<string,scalar|null>
     */
    private function toTableRow(array $item): array
    {
        return [
            'id' => ag($item, 'id'),
            'watched' => (int) ag($item, 'watched', 0),
            'type' => ag($item, 'type'),
            'via' => ag($item, 'via'),
            'title' => ag($item, 'content_title', ag($item, 'title')),
            'year' => ag($item, 'year'),
            'season' => ag($item, 'season'),
            'episode' => ag($item, 'episode'),
            'updated_at' => ag($item, 'updated_at'),
        ];
    }
}
