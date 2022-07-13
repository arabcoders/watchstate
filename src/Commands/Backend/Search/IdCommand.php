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
final class IdCommand extends Command
{
    public const ROUTE = 'backend:search:id';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Get backend metadata related to specific id.')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name.')
            ->addArgument('id', InputArgument::REQUIRED, 'Backend item id.')
            ->setHelp(
                r(
                    <<<HELP

                    This command allow you to get metadata about specific <notice>item id</notice> from backend.

                    The default mode display minimal information. To get more information you have to switch the
                    [<flag>--output</flag>] flag to [<value>json</value> or <value>yaml</value>] and use the [<flag>--include-raw-response</flag>] flag.
                    For example,

                    {cmd} <cmd>{route}</cmd> <flag>--output</flag> <value>yaml</value> <flag>--include-raw-response</flag> -- <value>backend_name</value> <value>backend_item_id</value>

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
            $backendOpts = [];
            $opts = [
                Options::NO_CACHE => true,
            ];

            if ($input->getOption('trace')) {
                $backendOpts = ag_set($opts, 'options.' . Options::DEBUG_TRACE, true);
            }

            $backend = $this->getBackend($input->getArgument('backend'), $backendOpts);

            if ($input->getOption('include-raw-response')) {
                $opts[Options::RAW_RESPONSE] = true;
            }

            $results = $backend->searchId(id: $id, opts: $opts);

            if (count($results) < 1) {
                $arr = [
                    'info' => sprintf('%s: No results were found for this id #\'%s\' .', $backend->getName(), $id),
                ];
                $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
                return self::FAILURE;
            }

            $this->displayContent('table' === $mode ? [$results] : $results, $output, $mode);

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $arr = ['error' => $e->getMessage(),];
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
