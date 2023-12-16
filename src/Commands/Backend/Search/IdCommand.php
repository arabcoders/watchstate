<?php

declare(strict_types=1);

namespace App\Commands\Backend\Search;

use App\Command;
use App\Libs\Config;
use App\Libs\Options;
use App\Libs\Routable;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * IdCommand
 *
 * This class represents a command for getting backend metadata related to a specific id.
 */
#[Routable(command: self::ROUTE)]
final class IdCommand extends Command
{
    public const ROUTE = 'backend:search:id';

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Get backend metadata related to specific id.')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addOption('select-backends', 's', InputOption::VALUE_REQUIRED, 'Select backends')
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

    /**
     * Runs the command.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int The command exit code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $id = $input->getArgument('id');

        if (null === ($name = $input->getOption('select-backends'))) {
            $output->writeln(
                r('<error>ERROR: You must select a backend using [-s, --select-backends] option.</error>')
            );
            return self::FAILURE;
        } else {
            $name = explode(',', $name)[0];
        }

        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                Config::save('servers', Yaml::parseFile($this->checkCustomBackendsFile($config)));
            } catch (\App\Libs\Exceptions\RuntimeException $e) {
                $output->writeln(r('<error>{message}</error>', ['message' => $e->getMessage()]));
                return self::FAILURE;
            }
        }

        if (null === ag(Config::get('servers', []), $name, null)) {
            $output->writeln(r("<error>ERROR: Backend '{backend}' not found.</error>", ['backend' => $name]));
            return self::FAILURE;
        }

        $backendOpts = [];
        $opts = [
            Options::NO_CACHE => true,
        ];

        if ($input->getOption('trace')) {
            $backendOpts = ag_set($opts, 'options.' . Options::DEBUG_TRACE, true);
        }

        $backend = $this->getBackend($name, $backendOpts);

        if ($input->getOption('include-raw-response')) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        try {
            $results = $backend->searchId(id: $id, opts: $opts + [Options::NO_LOGGING => true]);
        } catch (Throwable $e) {
            $output->writeln(r('<error>{kind}: {message}</error>', [
                'kind' => $e::class,
                'message' => $e->getMessage()
            ]));
            return self::FAILURE;
        }

        if (count($results) < 1) {
            $output->writeln(r("{backend}: No results were found for this id #'{id}'.", [
                'backend' => $backend->getName(),
                'query' => $id
            ]));
            return self::FAILURE;
        }

        $this->displayContent('table' === $mode ? [$results] : $results, $output, $mode);

        return self::SUCCESS;
    }
}
