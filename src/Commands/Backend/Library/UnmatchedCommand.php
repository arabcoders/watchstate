<?php

declare(strict_types=1);

namespace App\Commands\Backend\Library;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Options;
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
    public const string ROUTE = 'backend:library:unmatched';

    private const int CUTOFF = 30;

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Find Item in backend library that does not have external ids.')
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
        $id = $input->getOption('id');
        $cutoff = (int)$input->getOption('cutoff');
        $name = $input->getOption('select-backend');

        if (empty($name)) {
            $output->writeln(r('<error>ERROR: Backend not specified. Please use [-s, --select-backend].</error>'));
            return self::FAILURE;
        }

        $query = [];

        if ($input->getOption('timeout')) {
            $query['timeout'] = (float)$input->getOption('timeout');
        }

        if ($input->getOption('trace')) {
            $query[Options::DEBUG_TRACE] = true;
        }

        if ($input->getOption('include-raw-response')) {
            $query[Options::RAW_RESPONSE] = true;
        }

        $url = r('/backend/{backend}/unmatched/{id}', [
            'backend' => $name,
            'id' => $id ?? '',
        ]);

        $response = APIRequest('GET', $url, opts: ['query' => $query]);

        if (Status::OK !== $response->status) {
            $output->writeln(r('<error>API error. {status}: {message}</error>', [
                'id' => $id,
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        if (empty($response->body)) {
            $output->writeln(r('<info>No unmatched items found.</info>'));
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

                $forTable[] = $leaf;
            }

            $list = $forTable;
        }

        $this->displayContent($list, $output, $mode);

        return self::SUCCESS;
    }
}
