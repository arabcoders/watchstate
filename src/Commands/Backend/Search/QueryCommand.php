<?php

declare(strict_types=1);

namespace App\Commands\Backend\Search;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Options;
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
    public const string ROUTE = 'backend:search:query';

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

        $query = [
            'q' => $query,
            'limit' => (int)$input->getOption('limit'),
        ];

        if ($input->getOption('include-raw-response')) {
            $query[Options::RAW_RESPONSE] = 1;
        }

        $response = APIRequest('GET', r('/backend/{backend}/search', ['backend' => $name]), opts: ['query' => $query]);

        if (Status::NOT_FOUND === $response->status) {
            $output->writeln(r("<error>No results for '{key}'. {status}: {message}</error>", [
                'key' => $query,
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        if (Status::OK !== $response->status) {
            $output->writeln(r("<error>API error. {status}: {message}</error>", [
                'key' => $query,
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        if (empty($response->body)) {
            $output->writeln(r("<error>No results for '{key}'.</error>", ['key' => $query]));
            return self::FAILURE;
        }

        if ('table' !== $mode) {
            $this->displayContent($response->body, $output, $mode);
            return self::SUCCESS;
        }

        $list = [];

        foreach ($response->body as $item) {
            $via = ag($item, iState::COLUMN_VIA, '-');
            $list[] = [
                iState::COLUMN_ID => ag($item, iState::COLUMN_ID, '-'),
                iState::COLUMN_TYPE => ucfirst(ag($item, iState::COLUMN_TYPE, '-')),
                'Reference' => ag($item, 'full_title', ag($item, iState::COLUMN_TITLE, '-')),
                iState::COLUMN_TITLE => mb_substr(ag($item, 'Title', ag($item, iState::COLUMN_TITLE, '-')), 0, 80),
                iState::COLUMN_UPDATED => makeDate(ag($item, iState::COLUMN_UPDATED, time()))->format('Y-m-d H:i:s T'),
                iState::COLUMN_WATCHED => ag($item, iState::COLUMN_WATCHED, false) ? 'Yes' : 'No',
                'Backend Item ID' => ag($item, iState::COLUMN_META_DATA . ".{$via}." . iState::COLUMN_ID, '-'),
            ];
        }

        $this->displayContent($list, $output, 'table');

        return self::SUCCESS;
    }
}
