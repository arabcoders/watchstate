<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\HTTP_STATUS;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Class ParityCommand
 *
 * This command helps find possible conflicting records that are being reported by backends.
 */
#[Cli(command: self::ROUTE)]
final class ParityCommand extends Command
{
    public const string ROUTE = 'db:parity';

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);
        $this->setName(self::ROUTE)
            ->setDescription(
                'Inspect database records for possible conflicting records that are being reported by your backends.'
            )
            ->addOption(
                'min',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Integer. How many backends should have reported the record to be considered valid.',
                count($configFile->getAll() ?? [])
            )
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit returned results.', 1000)
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Limit returned results.', 1)
            ->addOption('prune', 'd', InputOption::VALUE_NONE, 'Remove all matching records from db.')
            ->setHelp(
                r(
                    <<<HELP

                    This command help find <notice>possible conflicting records</notice> that are being reported by
                    your backend.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># I have many records how to reduce the list?</question>

                    You can change the [<flag>--limit</flag>] flag to reduce the number of records shown.

                    <question># How to fine tune how many backends trigger the report?</question>

                    By using the [<flag>-m, --min</flag>] flag you can specify how many backends should have reported
                    the record to be considered valid. By default, it's the number of your backends. To specify a custom value
                    use the flag [<flag>-m, --min</flag>] followed by the number of backends The value cannot be
                    greater than the total number of your backends. For example if you want all backends to be identical,
                    and you have 3 backends, you can use the following command which will make sure each record at least
                    reported by 3 backends.

                    {cmd} <cmd>{route}</cmd> <flag>--min</flag> <value>3</value>

                    <question># I fixed the records how do I remove them from database?</question>

                    You can use the [<flag>--prune</flag>] flag to remove all matching records from database. For Example,
                    {cmd} <cmd>{route}</cmd> <flag>--min</flag> <value>3</value> <flag>--prune</flag>

                    Warning, this command will delete the entire match, not limited by <flag>--limit</flag> flag.
                    This flag require <notice>interaction</notice> to work. to bypass the check use <flag>[-n, --no-interaction]</flag> flag.

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                    ]
                )
            );
    }

    /**
     * Run a command.
     *
     * @param InputInterface $input The input instance.
     * @param OutputInterface $output The output instance.
     *
     * @return int The execution status of the command.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int)$input->getOption('limit');
        $min = (int)$input->getOption('min');
        $page = (int)$input->getOption('page');
        $prune = (bool)$input->getOption('prune');

        $params = [
            'min' => $min,
            'perpage' => $limit,
            'page' => $page,
        ];

        $response = APIRequest('GET', '/system/parity/', opts: ['query' => $params]);

        if (HTTP_STATUS::HTTP_OK !== $response->status) {
            $output->writeln(r("<error>API error. {status}: {message}</error>", [
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        $rows = ag($response->body, 'items', []);

        if (empty($rows)) {
            $output->writeln(r('<info>No records found. Criteria {params}</info>', [
                'params' => arrayToString($params),
            ]));
            return self::INVALID;
        }

        if (true === $prune) {
            if (!$input->getOption('no-interaction')) {
                $paging = ag($response->body, 'paging', []);
                $tty = !(function_exists('stream_isatty') && defined('STDERR')) || stream_isatty(STDERR);
                if (false === $tty || $input->getOption('no-interaction')) {
                    $output->writeln(
                        r(
                            <<<ERROR
                        <error>ERROR:</error> This command require <notice>interaction</notice>. For example:
                        {cmd} <cmd>{route}</cmd> <flag>--prune</flag>
                        ERROR,
                            [
                                'cmd' => trim(commandContext()),
                                'route' => self::ROUTE,
                            ]
                        )
                    );
                    return self::FAILURE;
                }

                $helper = $this->getHelper('question');

                $question = new ConfirmationQuestion(
                    r(
                        <<<HELP
                    <question>Are you sure you want to delete [<value>{count}</value>] records from database</question>? {default}
                    ------------------
                    <notice>NOTICE:</notice> You would have to re-import the records using [<cmd>state:import</cmd>] command.
                    ------------------
                    <notice>For more information please read the FAQ.</notice>
                    HELP. PHP_EOL . '> ',
                        [
                            'count' => ag($paging, 'total', 0),
                            'cmd' => trim(commandContext()),
                            'default' => '[<value>Y|N</value>] [<value>Default: No</value>]',
                        ]
                    ),
                    false,
                );

                if (true !== $helper->ask($input, $output, $question)) {
                    $output->writeln('<info>Pruning aborted.</info>');
                    return self::SUCCESS;
                }
            }

            $response = APIRequest('DELETE', '/system/parity/', opts: ['query' => ['min' => $min]]);

            if (HTTP_STATUS::HTTP_OK !== $response->status) {
                $output->writeln(r("<error>API error. {status}: {message}</error>", [
                    'status' => $response->status->value,
                    'message' => ag($response->body, 'error.message', 'Unknown error.')
                ]));
                return self::FAILURE;
            }

            $output->writeln(r('<info>Pruned [<value>{count}</value>] records.</info>', [
                'count' => ag($response->body, 'deleted_records', 0),
            ]));

            return self::SUCCESS;
        }

        if ('table' === $input->getOption('output')) {
            foreach ($rows as &$row) {
                $played = ag($row, iState::COLUMN_WATCHED) ? '✓' : '✕';
                $row['Reported by'] = join(', ', ag($row, 'reported_by', []));
                $row['Not reported by'] = join(', ', ag($row, 'not_reported_by', []));
                $row[iState::COLUMN_TITLE] = $played . ' ' . $row['full_title'];
                unset($row[iState::COLUMN_WATCHED], $row['full_title'], $row['reported_by'], $row['not_reported_by']);
                $row[iState::COLUMN_UPDATED] = makeDate($row[iState::COLUMN_UPDATED])->format('Y-m-d');
            }
        }

        unset($row);

        $this->displayContent($rows, $output, $input->getOption('output'));

        return self::SUCCESS;
    }
}
