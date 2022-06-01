<?php

declare(strict_types=1);

namespace App\Commands\Backend\Search;

use App\Command;
use App\Libs\Config;
use App\Libs\Options;
use RuntimeException;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class QueryCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('backend:search:query')
            ->setDescription('Search backend libraries for specific title keyword.')
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                sprintf('Output mode. Can be [%s].', implode(', ', $this->outputs)),
                $this->outputs[0],
            )
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit returned results.', 25)
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name.')
            ->addArgument('query', InputArgument::REQUIRED, 'Search query.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $query = $input->getArgument('query');

        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                Config::save('servers', Yaml::parseFile($this->checkCustomServersFile($config)));
            } catch (RuntimeException $e) {
                $arr = [
                    'error' => $e->getMessage()
                ];
                $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
                return self::FAILURE;
            }
        }

        try {
            $backend = $this->getBackend($input->getArgument('backend'));

            $opts = [];

            if ($input->getOption('include-raw-response')) {
                $opts[Options::RAW_RESPONSE] = true;
            }

            $results = $backend->search(
                query: $query,
                limit: (int)$input->getOption('limit'),
                opts:  $opts,
            );

            if (count($results) < 1) {
                $arr = [
                    'info' => sprintf('%s: No results were found for this query \'%s\' .', $backend->getName(), $query),
                ];
                $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
                return self::FAILURE;
            }

            $this->displayContent($results, $output, $mode);

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $arr = [
                'error' => $e->getMessage(),
            ];
            if ('table' !== $mode) {
                $arr += [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
            }
            $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
            return self::FAILURE;
        }
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        $methods = [
            'output' => 'outputs',
        ];

        foreach ($methods as $key => $of) {
            if ($input->mustSuggestOptionValuesFor($key)) {
                $currentValue = $input->getCompletionValue();

                $suggest = [];

                foreach ($this->{$of} as $name) {
                    if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                        $suggest[] = $name;
                    }
                }

                $suggestions->suggestValues($suggest);
            }
        }
    }
}
