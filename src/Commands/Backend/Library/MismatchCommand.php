<?php

declare(strict_types=1);

namespace App\Commands\Backend\Library;

use App\API\Backend\Mismatched;
use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Options;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class MismatchCommand
 *
 * Find possible mis-matched item in a libraries.
 */
#[Cli(command: self::ROUTE)]
final class MismatchCommand extends Command
{
    public const string ROUTE = 'backend:library:mismatch';

    private const int CUTOFF = 50;

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Find possible mis-matched item in a libraries.')
            ->addOption(
                'percentage',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Acceptable percentage.',
                Mismatched::DEFAULT_PERCENT
            )
            ->addOption(
                'method',
                'm',
                InputOption::VALUE_OPTIONAL,
                r('Comparison method. Can be [{list}].', ['list' => implode(', ', Mismatched::METHODS)]),
                Mismatched::METHODS[0]
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_OPTIONAL,
                'Request timeout in seconds.',
                Config::get('http.default.options.timeout')
            )
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('cutoff', null, InputOption::VALUE_REQUIRED, 'Increase title cutoff', self::CUTOFF)
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'backend Library id.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->setHelp(
                r(
                    <<<HELP

                    This command help find possible mis-matched <notice>Movies</notice> and <notice>Series</notice>.

                    This command require <notice>Plex Naming Standard</notice> and assume the reported [<value>title</value>, <value>year</value>] somewhat matches the reported media path.

                    We remove text contained within <value>{}</value> and <value>[]</value> brackets, as well as these characters:
                    [{removedList}]

                    Plex naming standard for <notice>Movies</notice> is:
                    /storage/movies/<value>Movie Title (Year)/Movie Title (Year)</value> [Tags].ext

                    Plex naming standard for <notice>Series</notice> is:
                    /storage/series/<value>Series Title (Year)</value>

                    -------------------
                    <notice>[ Expected Values ]</notice>
                    -------------------

                    <flag>percentage</flag> expects the value to be [<value>number</value>]. [<value>Default: {defaultPercent}</value>].
                    <flag>method</flag>     expects the value to be one of [{methodsList}]. [<value>Default: {DefaultMethod}</value>].

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># I want to check specific library id?</question>

                    You can do that by using [<flag>--id</flag>] flag, change the <value>backend_library_id</value> to the library
                    id you get from [<cmd>{library_list}</cmd>] command.

                    {cmd} <cmd>{route}</cmd> <flag>--id</flag> <value>backend_library_id</value> <flag>-s</flag> <value>backend_name</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'methodsList' => implode(
                            ', ',
                            array_map(fn($val) => '<value>' . $val . '</value>', Mismatched::METHODS)
                        ),
                        'DefaultMethod' => Mismatched::METHODS[0],
                        'defaultPercent' => Mismatched::DEFAULT_PERCENT,
                        'removedList' => implode(
                            ', ',
                            array_map(fn($val) => '<value>' . $val . '</value>', Mismatched::REMOVED_CHARS)
                        ),
                        'library_list' => ListCommand::ROUTE,
                    ]
                )
            );
    }

    /**
     * Run a command.
     *
     * @param InputInterface $input The input object.
     * @param OutputInterface $output The output object.
     *
     * @return int The exit code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $percentage = $input->getOption('percentage');
        $id = $input->getOption('id');
        $cutoff = (int)$input->getOption('cutoff');
        $name = $input->getOption('select-backend');
        if (empty($name)) {
            $output->writeln(r('<error>ERROR: Backend not specified. Please use [-s, --select-backend].</error>'));
            return self::FAILURE;
        }

        $query = [
            'percentage' => $percentage
        ];

        if ($input->getOption('timeout')) {
            $query['timeout'] = (float)$input->getOption('timeout');
        }

        if ($input->getOption('trace')) {
            $query[Options::DEBUG_TRACE] = true;
        }

        if ($input->getOption('include-raw-response')) {
            $query[Options::RAW_RESPONSE] = true;
        }

        $url = r('/backend/{backend}/mismatched/{id}', [
            'backend' => $name,
            'id' => $id ?? '',
        ]);

        $response = APIRequest('GET', $url, opts: ['query' => $query]);

        if (Status::HTTP_OK !== $response->status) {
            $output->writeln(r('<error>API error. {status}: {message}</error>', [
                'id' => $id,
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        if (empty($response->body)) {
            $output->writeln('<info>No mis-matched items were found.</info>');
            return self::SUCCESS;
        }

        $list = $response->body;

        if ('table' === $mode) {
            $forTable = [];

            foreach ($list as $item) {
                $via = ag($item, iState::COLUMN_VIA, '-');

                $leaf = [
                    iState::COLUMN_ID => ag($item, iState::COLUMN_META_DATA . ".{$via}." . iState::COLUMN_ID),
                ];

                if (!$id) {
                    $leaf[iState::COLUMN_TYPE] = ag($item, iState::COLUMN_TYPE);
                    $leaf[iState::COLUMN_META_LIBRARY] = ag($item, iState::COLUMN_META_LIBRARY);
                }

                $title = ag($item, 'title');

                if (mb_strlen($title) > $cutoff) {
                    $title = mb_substr($title, 0, $cutoff) . '..';
                }

                if (null !== ($webUrl = ag($item, 'webUrl'))) {
                    $leaf[iState::COLUMN_TITLE] = "<href={$webUrl}>{$title}</>";
                } else {
                    $leaf[iState::COLUMN_TITLE] = $title;
                }

                $leaf[iState::COLUMN_YEAR] = ag($item, iState::COLUMN_YEAR);
                $leaf['percent'] = ag($item, 'percent') . '%';

                $forTable[] = $leaf;
            }

            $list = $forTable;
        }

        $this->displayContent($list, $output, $mode);

        return self::SUCCESS;
    }

    /**
     * Completes the input with suggestion values for methods.
     *
     * @param CompletionInput $input The completion input object.
     * @param CompletionSuggestions $suggestions The completion suggestions object.
     * @return void
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestOptionValuesFor('methods')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (Mismatched::METHODS as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
