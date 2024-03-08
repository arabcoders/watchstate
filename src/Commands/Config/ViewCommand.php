<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\API\Backends\Index;
use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ViewCommand
 *
 * This command display all backend's information. User can select and/or filter the displayed information.
 */
#[Cli(command: self::ROUTE)]
final class ViewCommand extends Command
{
    public const ROUTE = 'config:view';

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('View Backends settings.')
            ->addOption(
                'select-backend',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Select backend.'
            )
            ->addOption('exclude', null, InputOption::VALUE_NONE, 'Inverse --select-backend logic.')
            ->addOption('expose', 'x', InputOption::VALUE_NONE, 'Expose the secret tokens in the view.')
            ->addArgument(
                'filter',
                InputArgument::OPTIONAL,
                'Can be any key from servers.yaml, use dot notion to access sub keys, for example [webhook.token]'
            )
            ->setHelp(
                r(
                    <<<HELP

                    This command display all of your backend's information.
                    You can select and/or filter the displayed information.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to show one backend information?</question>

                    The flag [<flag>-s, --select-backend</flag>] is array option with can accept many backends names,
                    Using the flag in combination with [<flag>--exclude</flag>] flag will flip the logic to exclude
                    the selected backends rather than include them.

                    {cmd} <cmd>{route}</cmd> <flag>-s</flag> <value>my_backend</value>

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
     * Run the command.
     *
     * @param InputInterface $input The input object.
     * @param OutputInterface $output The output object.
     *
     * @return int The exit code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $list = [];
        $selected = $input->getOption('select-backend');
        $isCustom = count($selected) > 0;
        $filter = $input->getArgument('filter');

        foreach (Config::get('servers', []) as $backendName => $backend) {
            if ($isCustom && $input->getOption('exclude') === in_array($backendName, $selected)) {
                $output->writeln(r('Ignoring backend \'{backend}\' as requested by [-s, --select-backend].', [
                    'backend' => $backendName
                ]), OutputInterface::VERBOSITY_VERY_VERBOSE);
                continue;
            }

            $list[$backendName] = ['name' => $backendName, ...$backend];
        }

        if (empty($list)) {
            $output->writeln(r('<error>{error}</error>', [
                'error' => $isCustom ? '[-s, --select-backend] did not return any backend.' : 'No backends were found.'
            ]));
            return self::FAILURE;
        }

        $x = 0;
        $count = count($list);

        $rows = [];
        foreach ($list as $backendName => $backend) {
            $x++;
            $rows[] = [
                $backendName,
                $this->filterData($backend, $filter, $input->getOption('expose'))
            ];

            if ($x < $count) {
                $rows[] = new TableSeparator();
            }
        }

        $mode = $input->getOption('output');

        if ('table' === $mode) {
            (new Table($output))->setStyle('box')
                ->setHeaders(['Backend', 'Data (Filter: ' . (empty($filter) ? 'None' : $filter) . ')']
                )->setRows($rows)
                ->render();
        } else {
            $this->displayContent($list, $output, $mode);
        }

        return self::SUCCESS;
    }

    /**
     * Completes the suggestion for filter input.
     *
     * @param CompletionInput $input The input for completion.
     * @param CompletionSuggestions $suggestions The completion suggestions.
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestArgumentValuesFor('filter')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (require __DIR__ . '/../../../config/backend.spec.php' as $name => $val) {
                if (true === $val && (empty($currentValue) || str_starts_with($name, $currentValue))) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }

    /**
     * Filters the given backend data based on the provided filter and expose parameters.
     *
     * @param array $backend The backend data to filter.
     * @param string|null $filter The filter criteria.
     * @param bool $expose Whether to expose hidden values or not.
     *
     * @return string The filtered data in YAML format.
     */
    private function filterData(array $backend, string|null $filter = null, bool $expose = false): string
    {
        if (null === $filter && true !== $expose) {
            foreach (Index::BLACK_LIST as $hideValue) {
                if (true === ag_exists($backend, $hideValue)) {
                    $backend = ag_set($backend, $hideValue, '*HIDDEN*');
                }
            }
        }

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
