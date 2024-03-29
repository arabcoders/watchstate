<?php

declare(strict_types=1);

namespace App\Commands\Backend\Search;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Options;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class QueryCommand
 *
 * This command allows user to search backend libraries.
 *
 * @note Investigate the possibility of using the command to search all backends at once.
 */
#[Cli(command: self::ROUTE)]
final class QueryCommand extends Command
{
    public const ROUTE = 'backend:search:query';

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Search backend libraries for specific title keyword.')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit returned results.', 25)
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->addArgument('query', InputArgument::REQUIRED, 'Search query.')
            ->setHelp(
                r(
                    <<<HELP

                    This command allow you to search for <notice>keyword</notice> in backend libraries.

                    The default mode display minimal information. To get more information you have to switch the
                    [<flag>--output</flag>] flag to [<value>json</value> or <value>yaml</value>] and use the [<flag>--include-raw-response</flag>] flag.
                    For example,

                    {cmd} <cmd>{route}</cmd> <flag>--output</flag> <value>yaml</value> <flag>--include-raw-response -s</flag> <value>backend_name</value> '<value>search query word</value>'

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                    ]
                )
            );
    }

    /**
     * Execute the command.
     *
     * @param InputInterface $input The input object.
     * @param OutputInterface $output The output object.
     *
     * @return int The exit status code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $query = $input->getArgument('query');
        $name = $input->getOption('select-backend');

        if (empty($name)) {
            $output->writeln(r('<error>ERROR: Backend not specified. Please use [-s, --select-backend].</error>'));
            return self::FAILURE;
        }

        $opts = $backendOpts = [];

        if ($input->getOption('include-raw-response')) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        if ($input->getOption('trace')) {
            $backendOpts = ag_set($opts, 'options.' . Options::DEBUG_TRACE, true);
        }

        try {
            $backend = $this->getBackend($name, $backendOpts);
        } catch (RuntimeException) {
            $output->writeln(r("<error>ERROR: Backend '{backend}' not found.</error>", ['backend' => $name]));
            return self::FAILURE;
        }

        $results = $backend->search(
            query: $query,
            limit: (int)$input->getOption('limit'),
            opts: $opts,
        );

        if (count($results) < 1) {
            $output->writeln(r("{backend}: No results were found for this query '{query}'.", [
                'backend' => $backend->getName(),
                'query' => $query
            ]));
            return self::FAILURE;
        }

        if ('table' === $mode) {
            foreach ($results as &$item) {
                $item['title'] = preg_replace(
                    '#(' . preg_quote($query, '#') . ')#i',
                    '<value>$1</value>',
                    $item['title']
                );
            }
            unset($item);
        }

        $this->displayContent($results, $output, $mode);

        return self::SUCCESS;
    }
}
