<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Config;
use App\Libs\Routable;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[Routable(command: self::ROUTE), Routable(command: 'servers:view')]
final class ViewCommand extends Command
{
    public const ROUTE = 'config:view';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('View Backends settings.')
            ->addOption('select-backends', 's', InputOption::VALUE_OPTIONAL, 'Select backends. comma , seperated.', '')
            ->addOption('exclude', null, InputOption::VALUE_NONE, 'Inverse --select-backends logic.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument(
                'filter',
                InputArgument::OPTIONAL,
                'Can be any key from servers.yaml, use dot notion to access sub keys, for example [webhook.token]'
            )
            ->setAliases(['servers:view'])
            ->addOption('servers-filter', null, InputOption::VALUE_OPTIONAL, '[DEPRECATED] Select backends.', '')
            ->setHelp(
                r(
                    <<<HELP

                    This command display all of your backends information.
                    You can select and/or filter the displayed information.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to show one backend information?</question>

                    The flag [<flag>-s, --select-backends</flag>] accept comma seperated list of backends name, Using the flag
                    in combination with [<flag>--exclude</flag>] flag will flip the logic to exclude the selected backends
                    rather than include them.

                    {cmd} <cmd>{route}</cmd> <flag>--select-backends</flag> <value>my_backend</value>

                    <question># How to show specific <value>key</value>?</question>

                    The key can be any value that already exists in the list. to access sub-keys use dot notation for example,
                    To see if the value of <value>import.enabled</value> you would run:

                    {cmd} <cmd>{route}</cmd> -- <value>import.enabled</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                    ]
                )
            );
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                Config::save('servers', Yaml::parseFile($this->checkCustomBackendsFile($config)));
            } catch (RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        }

        $selectBackends = (string)$input->getOption('select-backends');
        $serversFilter = (string)$input->getOption('servers-filter');

        if (!empty($serversFilter)) {
            $output->writeln(
                '<comment>The [--servers-filter] flag is deprecated and will be removed in v1.0. Use [--select-backends].</comment>'
            );
            if (empty($selectBackends)) {
                $selectBackends = $serversFilter;
            }
        }

        $list = [];
        $selected = array_map('trim', explode(',', $selectBackends));
        $isCustom = !empty($selectBackends) && count($selected) >= 1;
        $filter = $input->getArgument('filter');

        foreach (Config::get('servers', []) as $backendName => $backend) {
            if ($isCustom && $input->getOption('exclude') === in_array($backendName, $selected)) {
                $output->writeln(
                    sprintf('%s: Ignoring backend as requested by [-s, --select-backends].', $backendName),
                    OutputInterface::VERBOSITY_VERY_VERBOSE
                );
                continue;
            }

            $list[$backendName] = ['name' => $backendName, ...$backend];
        }

        if (empty($list)) {
            throw new RuntimeException(
                $isCustom ? '[-s, --select-backends] did not return any backend.' : 'No backends were found.'
            );
        }

        $x = 0;
        $count = count($list);

        $rows = [];
        foreach ($list as $backendName => $backend) {
            $x++;
            $rows[] = [
                $backendName,
                $this->filterData($backend, $filter)
            ];

            if ($x < $count) {
                $rows[] = new TableSeparator();
            }
        }

        (new Table($output))->setStyle('box')
            ->setHeaders(['Backend', 'Filter: ' . (empty($filter) ? 'None' : $filter)]
            )->setRows($rows)
            ->render();

        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestArgumentValuesFor('filter')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (require __DIR__ . '/../../../config/backend.spec.php' as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }

    private function filterData(array $backend, string|null $filter = null): string
    {
        if (null === $filter || false === str_contains($filter, ',')) {
            return trim(Yaml::dump(ag($backend, $filter, 'Not configured, or invalid key.'), 8, 2));
        }

        $filters = array_map(fn($val) => trim($val), explode(',', $filter));
        $list = [];

        foreach ($filters as $fil) {
            $list[$fil] = ag($backend, $fil, 'Not configured, or invalid key.');
        }

        return trim(Yaml::dump($list, 8, 2));
    }
}
