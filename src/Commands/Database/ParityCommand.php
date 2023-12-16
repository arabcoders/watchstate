<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Routable;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ParityCommand
 *
 * This command helps find possible conflicting records that are being reported by backends.
 */
#[Routable(command: self::ROUTE)]
final class ParityCommand extends Command
{
    public const ROUTE = 'db:parity';

    /**
     * Class constructor.
     *
     * @param iDB $db The iDB instance to be injected.
     */
    public function __construct(private iDB $db)
    {
        parent::__construct();
    }

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription(
                'Inspect database records for possible conflicting records that are being reported by your backends.'
            )
            ->addOption(
                'minimum',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Integer. How many backends should have reported the record to be considered valid. By default it\'s 3/4 of your backends.'
            )
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit returned results.', 1000)
            ->addOption('count', 't', InputOption::VALUE_NONE, 'Disregard limit and display total count.')
            ->addOption('distinct', 'd', InputOption::VALUE_NONE, 'Report distinct records only.')
            ->addOption('prune', 'p', InputOption::VALUE_NONE, 'Remove all matching records from db.')
            ->setHelp(
                r(
                    <<<HELP

                    This command help find <notice>possible conflicting records</notice> that are being reported by
                    your backend.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># I have many records how to reduce the list?</question>

                    Please, take look at the optional flags. For example to reduce the episodes to bare minimum, you can
                    use the [<flag>--distinct</flag>] flag. which group records by title thus reducing the list. Example,

                    {cmd} <cmd>{route}</cmd> <flag>--distinct</flag>

                    If that is not enough you can change the [<flag>--limit</flag>] flag to reduce the number of records,
                    however this will not guarantee that you will get all the relevant records.

                    <question># How to fine tune how many backends trigger the report?</question>

                    By using the [<flag>-m, --minimum</flag>] flag you can specify how many backends should have reported
                    the record to be considered valid. By default, it's the number of your backends. To specify a custom value
                    use the flag [<flag>-m, --minimum</flag>] followed by the number of backends The value cannot be
                    greater than the total number of your backends. For example if you want all backends to be identical,
                    and you have 3 backends, you can use the following command which will make sure each record at least
                    reported by 3 backends.

                    {cmd} <cmd>{route}</cmd> <flag>--minimum</flag> <value>3</value>

                    <question># I fixed the records how do I remove them from database?</question>

                    You can use the [<flag>--prune</flag>] flag to remove all matching records from database. For Example,
                    {cmd} <cmd>{route}</cmd> <flag>--prune</flag>

                    This command require <notice>INTERACTION</notice> to actually delete data otherwise it won't work.
                    And it doesn't work in combination with [<flag>--count</flag>] or [<flag>--distinct</flag>] flags.
                    However, it does work with [<flag>--limit</flag>] and [<flag>--minimum</flag>] flags.

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
        $countRequest = (bool)$input->getOption('count');
        $distinct = (bool)$input->getOption('distinct');
        $prune = (bool)$input->getOption('prune');
        $limit = (int)$input->getOption('limit');
        $counter = $input->hasOption('minimum') ? (int)$input->getOption('minimum') : 0;

        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            Config::save('servers', Yaml::parseFile($this->checkCustomBackendsFile($config)));
        }

        $serversCount = count(Config::get('servers', []));
        $serversKeys = array_keys(Config::get('servers', []));

        if ($counter > $serversCount) {
            $output->writeln(
                r(
                    'Invalid value for option [<flag>-m, --minimum</flag>]. Value must be less than or equal to [<value>{count}</value>]. Which the total number of your backends.',
                    [
                        'count' => $serversCount,
                    ]
                )
            );
            return self::FAILURE;
        }

        if (0 === $counter) {
            $counter = $serversCount;
        }

        $distinctSQL = $distinct ? 'GROUP BY title' : '';

        if (true === $countRequest) {
            $sql = "SELECT
                    COUNT(*)
                FROM
                    state
                WHERE
                    ( SELECT COUNT(*) FROM JSON_EACH(state.metadata) ) < {$counter}
                {$distinctSQL}
            ";
        } else {
            $sqlFields = $prune ? 'id' : '*';
            $sql = "SELECT
                    {$sqlFields},
                    ( SELECT COUNT(*) FROM JSON_EACH(state.metadata) ) as total_md
                FROM
                    state
                WHERE
                    total_md < {$counter}
                    {$distinctSQL}
                LIMIT
                    {$limit}
            ";
        }

        $stmt = $this->db->getPDO()->query($sql);

        if (true === $countRequest) {
            $output->writeln(
                r(
                    '<info>Total records with [<value>{counter}</value>] backends or less reporting: [<value>{count}</value>] records.</info>',
                    [
                        'counter' => $counter,
                        'count' => $stmt->fetchColumn(),
                    ]
                )
            );
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($stmt as $row) {
            $rows[] = $row;
        }

        if (empty($rows)) {
            $output->writeln('<info>No records matched the criteria.</info>');
            return self::INVALID;
        }

        if (true === $prune) {
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
                        'count' => count($rows),
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

            $ids = array_column($rows, 'id');
            $this->db->getPDO()->exec("DELETE FROM state WHERE id IN (" . join(',', $ids) . ")");

            $output->writeln(
                r(
                    '<info>Pruned [<value>{count}</value>] records.</info>',
                    [
                        'count' => count($ids),
                    ]
                )
            );

            return self::SUCCESS;
        }

        if ('table' === $input->getOption('output')) {
            foreach ($rows as &$row) {
                $entity = Container::get(iState::class)->fromArray($row);
                $played = $entity->isWatched() ? '✓' : '✕';
                $reportedBackends = array_keys($entity->getMetadata());
                $row = [
                    'id' => $distinct && iState::TYPE_EPISODE === $entity->type ? '-' : $entity->id,
                    'type' => $played . ' ' . ($distinct && iState::TYPE_EPISODE === $entity->type ? 'Show' : ucfirst(
                            $entity->type
                        )),
                    'title' => $distinct && iState::TYPE_EPISODE === $entity->type ? $entity->getName(
                        true
                    ) : $entity->getName($distinct),
                    'date' => makeDate($entity->updated)->format('Y-m-d'),
                    'T/B' => "{$serversCount}/" . $row['total_md'],
                    'Reported By' => join(', ', $reportedBackends),
                    'Not reported By' => join(
                        ', ',
                        array_filter($serversKeys, fn($key) => !in_array($key, $reportedBackends))
                    ),
                ];
            }
        } else {
            foreach ($rows as &$row) {
                foreach (iState::ENTITY_ARRAY_KEYS as $key) {
                    if (null === ($row[$key] ?? null)) {
                        continue;
                    }
                    $row[$key] = json_decode($row[$key], true);
                }

                $reportedBackends = array_keys($row[iState::COLUMN_META_DATA] ?? []);

                $row[iState::COLUMN_WATCHED] = (bool)$row[iState::COLUMN_WATCHED];
                $row[iState::COLUMN_UPDATED] = makeDate($row[iState::COLUMN_UPDATED]);
                $row['tally'] = "{$serversCount}/" . $row['total_md'];
                $row['reported_by'] = $reportedBackends;
                $row['not_reported_by'] = array_filter($serversKeys, fn($key) => !in_array($key, $reportedBackends));
                unset($row['total_md']);
            }
        }

        unset($row);

        $this->displayContent($rows, $output, $input->getOption('output'));

        return self::SUCCESS;
    }
}
