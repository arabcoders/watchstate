<?php

declare(strict_types=1);

namespace App\Commands\Backend\Library;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Options;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class UnmatchedCommand
 *
 * This command helps find unmatched items in user libraries.
 */
#[Cli(command: self::ROUTE)]
final class UnmatchedCommand extends Command
{
    public const ROUTE = 'backend:library:unmatched';

    private const CUTOFF = 30;

    /**
     * Configures the command.
     */
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
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'backend Library id.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->setHelp(
                r(
                    <<<HELP

                    This command help find unmatched items in your libraries.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># I want to check specific library id?</question>

                    You can do that by using [<flag>--id</flag>] flag, change the <value>backend_library_id</value> to the library
                    id you get from [<cmd>{library_list}</cmd>] command.

                    {cmd} <cmd>{route}</cmd> <flag>--id</flag> <value>backend_library_id</value> <flag>-s</flag> <value>backend_name</value>

                    <question># I want to show all items regardless of the status?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--show-all</flag> <flag>-s</flag> <value>backend_name</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'library_list' => ListCommand::ROUTE,
                    ]
                )
            );
    }

    /**
     * Runs the command.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int The exit code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $showAll = $input->getOption('show-all');
        $id = $input->getOption('id');
        $cutoff = (int)$input->getOption('cutoff');
        $name = $input->getOption('select-backend');

        if (empty($name)) {
            $output->writeln(r('<error>ERROR: Backend not specified. Please use [-s, --select-backend].</error>'));
            return self::FAILURE;
        }

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

        try {
            $backend = $this->getBackend($name, $backendOpts);
        } catch (RuntimeException) {
            $output->writeln(r("<error>ERROR: Backend '{backend}' not found.</error>", ['backend' => $name]));
            return self::FAILURE;
        }

        $ids = [];
        if (null !== $id) {
            $ids[] = $id;
        } else {
            foreach ($backend->listLibraries() as $library) {
                if (false === (bool)ag($library, 'supported') || true === (bool)ag($library, 'ignored')) {
                    continue;
                }
                $ids[] = ag($library, 'id');
            }
        }

        foreach ($ids as $libraryId) {
            foreach ($backend->getLibrary(id: $libraryId, opts: $opts) as $item) {
                if (true === $showAll) {
                    $list[] = $item;
                    continue;
                }
                if (null === ($externals = ag($item, 'guids', null)) || empty($externals)) {
                    $list[] = $item;
                }
            }
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
