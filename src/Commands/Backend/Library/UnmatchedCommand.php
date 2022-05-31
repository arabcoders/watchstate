<?php

declare(strict_types=1);

namespace App\Commands\Backend\Library;

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
use Throwable;

final class UnmatchedCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('backend:library:unmatched')
            ->setDescription('Find top level Items in library that has no external ids.')
            ->addOption('id', 'i', InputOption::VALUE_REQUIRED, 'Library id.')
            ->addOption('show-all', null, InputOption::VALUE_NONE, 'Show all content regardless.')
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                sprintf(
                    'Output mode. Can be [%s]. Modes other than table mode gives more info.',
                    implode(', ', $this->outputs)
                ),
                $this->outputs[0],
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_OPTIONAL,
                'Request timeout in seconds.',
                Config::get('http.default.options.timeout')
            )
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $showAll = $input->getOption('show-all');

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

        if (null === ($id = $input->getOption('id'))) {
            $arr = [
                'error' => 'Library mismatch search require library id to be passed in [-i, --id].'
            ];

            $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
            return self::FAILURE;
        }

        try {
            $serverOpts = $opts = $list = [];

            if ($input->getOption('timeout')) {
                $serverOpts = ag_set($opts, 'client.timeout', (float)$input->getOption('timeout'));
            }

            if ($input->getOption('include-raw-response')) {
                $opts[Options::RAW_RESPONSE] = true;
            }

            foreach ($this->getBackend($input->getArgument('backend'), $serverOpts)->getLibrary($id, $opts) as $item) {
                if (true === $showAll) {
                    $list[] = $item;
                    continue;
                }
                if (null === ($externals = ag($item, 'guids', null)) || empty($externals)) {
                    $list[] = $item;
                }
            }
        } catch (Throwable $e) {
            $arr = [
                'error' => $e->getMessage(),
            ];
            if ('table' !== $mode) {
                $arr += [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'item' => $item ?? [],
                ];
            }

            $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);

            return self::FAILURE;
        }

        if (empty($list)) {
            $arr = [
                'info' => 'No un-matched items were found in given library.',
            ];

            $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
            return self::SUCCESS;
        }

        if ('table' === $mode) {
            $forTable = [];

            foreach ($list as $item) {
                $leaf = [
                    'id' => ag($item, 'id'),
                    'type' => ag($item, 'type'),
                    'title' => ag($item, 'title'),
                    'year' => ag($item, 'year'),
                ];

                if ($showAll) {
                    $leaf['guids'] = implode(', ', $item['guids'] ?? []);
                }

                $forTable[] = $leaf;
            }

            $list = $forTable;
        }

        $this->displayContent($list, $output, $mode);

        return self::SUCCESS;
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
