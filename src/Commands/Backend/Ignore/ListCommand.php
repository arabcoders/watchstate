<?php

declare(strict_types=1);

namespace App\Commands\Backend\Ignore;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ListCommand
 *
 * Represents a command for listing ignored external ids.
 */
#[Cli(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const string ROUTE = 'backend:ignore:list';

    /**
     * Class Constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption(
                'select-backend',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Filter based on backend.'
            )
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filter based on type.')
            ->addOption('db', 'd', InputOption::VALUE_REQUIRED, 'Filter based on db.')
            ->addOption('id', 'i', InputOption::VALUE_REQUIRED, 'Filter based on id.')
            ->setDescription('List Ignored external ids.')
            ->setHelp(
                r(
                    <<<HELP

                    This command display list of ignored external ids. You can filter the list by
                    using one or more of the provided options.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># List all ignore rules that relate to specific backend.</question>

                    {cmd} <cmd>{route}</cmd> <flag>-s</flag> <value>backend_name</value>

                    <question># Appending more filters to narrow down list</question>

                    {cmd} <cmd>{route}</cmd> <flag>-s</flag> <value>backend_name</value> <flag>-d</flag> <value>tvdb</value>

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
     * @param InputInterface $input The input object
     * @param OutputInterface $output The output object
     *
     * @return int The exit status code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $list = [];

        $backends = $input->getOption('select-backend');

        $query = [];

        if (null !== ($fType = $input->getOption('type'))) {
            $query['type'] = $fType;
        }
        if (null !== ($fDb = $input->getOption('db'))) {
            $query['db'] = $fDb;
        }
        if (null !== ($fId = $input->getOption('id'))) {
            $query['id'] = $fId;
        }

        if (!empty($backends)) {
            $query['backend'] = $backends[0];
        }

        $response = APIRequest('GET', '/ignore/?' . http_build_query($query));

        foreach ($response->body as $item) {
            if ('table' === $input->getOption('output')) {
                unset($item['rule']);
                $item = ag_set($item, 'scoped', ag($item, 'scoped', false) ? 'Yes' : 'No');
                if (null === ag($item, 'scoped_to')) {
                    $item = ag_set($item, 'scoped_to', '-');
                }
                $item = ag_set($item, 'created', makeDate(ag($item, 'created'))->format('Y-m-d H:i:s T'));
            }

            $list[] = $item;
        }

        if (empty($list)) {
            $hasFilter = count($query) > 0;

            $output->writeln(
                $hasFilter ? '<comment>Filters did not return any results.</comment>' : '<info>Ignore list is empty.</info>'
            );

            return $hasFilter ? self::FAILURE : self::SUCCESS;
        }

        $this->displayContent($list, $output, $input->getOption('output'));

        return self::SUCCESS;
    }

    /**
     * Completes the suggestions for the given input.
     *
     * @param CompletionInput $input The input object representing the completion request
     * @param CompletionSuggestions $suggestions The object responsible for suggesting values
     *
     * @return void
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestOptionValuesFor('backend')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (array_keys(Config::get('servers', [])) as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }

        if ($input->mustSuggestOptionValuesFor('type')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (iState::TYPES_LIST as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }

        if ($input->mustSuggestOptionValuesFor('db')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (array_keys(Guid::getSupported()) as $name) {
                $name = after($name, 'guid_');
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
