<?php

declare(strict_types=1);

namespace App\Commands\Backend\Search;

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

#[Routable(command: self::ROUTE)]
final class QueryCommand extends Command
{
    public const ROUTE = 'backend:search:query';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Search backend libraries for specific title keyword.')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit returned results.', 25)
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name.')
            ->addArgument('query', InputArgument::REQUIRED, 'Search query.')->setHelp(
                r(
                    <<<HELP
This command allow you to search for specific <notice>keyword</notice> in backend libraries.

The default mode display minimal information. To get more information you have to switch the output
mode to [<value>json</value> or <value>yaml</value>] and use the [<flag>--include-raw-response</flag>] flag. For example,

{cmd} <cmd>{route}</cmd> <flag>--output</flag> <value>yaml</value> <flag>--include-raw-response</flag> -- <value>BACKEND_NAME</value> '<value>KEYWORD</value>'

HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                    ]
                )
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $query = $input->getArgument('query');

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
            $opts = $backendOpts = [];

            if ($input->getOption('include-raw-response')) {
                $opts[Options::RAW_RESPONSE] = true;
            }

            if ($input->getOption('trace')) {
                $backendOpts = ag_set($opts, 'options.' . Options::DEBUG_TRACE, true);
            }

            $backend = $this->getBackend($input->getArgument('backend'), $backendOpts);

            $results = $backend->search(
                query: $query,
                limit: (int)$input->getOption('limit'),
                opts: $opts,
            );

            if (count($results) < 1) {
                $arr = [
                    'info' => sprintf('%s: No results were found for this query \'%s\' .', $backend->getName(), $query),
                ];
                $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
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
}
