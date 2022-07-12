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

    private const CUTOFF = 30;

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Find Item in backend library that does not have external ids.')
            ->addOption('show-all', null, InputOption::VALUE_NONE, 'Show all items regardless of the match status.')
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_OPTIONAL,
                'Increase request timeout.',
                Config::get('http.default.options.timeout')
            )
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('cutoff', null, InputOption::VALUE_REQUIRED, 'Increase title cutoff', self::CUTOFF)
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'backend Library id.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name.')
            ->setHelp(
                r(
                    <<<HELP

This command help find unmatched items in your libraries.

-------
<comment>[ FAQ ]</comment>
-------

<comment># I want to check specific library id?</comment>

You can do that by using [<comment>--id</comment>] flag, change the <info>backend_library_id</info> to the library
id you get from [<comment>{library_list}</comment>] command.

{cmd} {route} <comment>--id</comment> '<info>backend_library_id</info>' -- [<info>BACKEND_NAME</info>]

<comment># I want to show all items regardless of the status?</comment>

{cmd} {route} <comment>--show-all</comment> -- [<info>BACKEND_NAME</info>]

HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'library_list' => ListCommand::ROUTE,
                    ]
                )
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $showAll = $input->getOption('show-all');
        $backend = $input->getArgument('backend');
        $id = $input->getOption('id');
        $cutoff = (int)$input->getOption('cutoff');

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

            $client = $this->getBackend($backend, $backendOpts);

            $ids = [];
            if (null !== $id) {
                $ids[] = $id;
            } else {
                foreach ($client->listLibraries() as $library) {
                    if (false === (bool)ag($library, 'supported') || true === (bool)ag($library, 'ignored')) {
                        continue;
                    }
                    $ids[] = ag($library, 'id');
                }
            }

            foreach ($ids as $libraryId) {
                foreach ($client->getLibrary(id: $libraryId, opts: $opts) as $item) {
                    if (true === $showAll) {
                        $list[] = $item;
                        continue;
                    }
                    if (null === ($externals = ag($item, 'guids', null)) || empty($externals)) {
                        $list[] = $item;
                    }
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
                'info' => 'No un-unmatched items were found',
            ];

            $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
            return self::SUCCESS;
        }

        if ('table' === $mode) {
            $forTable = [];

            foreach ($list as $item) {
                $leaf = [
                    'id' => ag($item, 'id'),
                ];

                if (!$id) {
                    $leaf['type'] = ag($item, 'type');
                    $leaf['library'] = ag($item, 'library');
                }

                $title = ag($item, 'title');

                if (mb_strlen($title) > $cutoff) {
                    $title = mb_substr($title, 0, $cutoff) . '..';
                }

                $leaf['title'] = $title;
                $leaf['year'] = ag($item, 'year');

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
