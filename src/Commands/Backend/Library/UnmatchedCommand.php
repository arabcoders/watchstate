<?php

declare(strict_types=1);

namespace App\Commands\Backend\Library;

use App\Command;
use App\Libs\Config;
use App\Libs\Options;
use App\Libs\Routable;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

#[Routable(command: self::ROUTE)]
final class UnmatchedCommand extends Command
{
    public const ROUTE = 'backend:library:unmatched';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Find top level Items in library that has no external ids.')
            ->addOption('show-all', null, InputOption::VALUE_NONE, 'Show all items regardless of the match status.')
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_OPTIONAL,
                'Request timeout in seconds.',
                Config::get('http.default.options.timeout')
            )
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name.')
            ->addArgument('id', InputArgument::REQUIRED, 'Library id.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $showAll = $input->getOption('show-all');
        $backend = $input->getArgument('backend');
        $id = $input->getArgument('id');

        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                Config::save('servers', Yaml::parseFile($this->checkCustomBackendsFile($config)));
            } catch (RuntimeException $e) {
                $arr = [
                    'error' => $e->getMessage()
                ];
                $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
                return self::FAILURE;
            }
        }

        try {
            $backendOpts = $opts = $list = [];

            if ($input->getOption('timeout')) {
                $backendOpts = ag_set($opts, 'client.timeout', (float)$input->getOption('timeout'));
            }

            if ($input->getOption('trace')) {
                $backendOpts = ag_set($opts, 'options.' . Options::DEBUG_TRACE, true);
            }

            if ($input->getOption('include-raw-response')) {
                $opts[Options::RAW_RESPONSE] = true;
            }

            foreach ($this->getBackend($backend, $backendOpts)->getLibrary(id: $id, opts: $opts) as $item) {
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
                $arr['exception'] = [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'item' => $item ?? [],
                ];

                if (!empty($item)) {
                    $arr['item'] = $item;
                }
            }

            $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);

            return self::FAILURE;
        }

        if (empty($list)) {
            $arr = [
                'info' => sprintf(
                    'No un-unmatched items were found in [%s] library [%s] given library.',
                    $backend,
                    $id
                ),
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
}
