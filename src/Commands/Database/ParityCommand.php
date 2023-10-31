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
use Symfony\Component\Yaml\Yaml;

#[Routable(command: self::ROUTE)]
final class ParityCommand extends Command
{
    public const ROUTE = 'db:parity';

    public function __construct(private iDB $db)
    {
        parent::__construct();
    }

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
            ->addOption('count', null, InputOption::VALUE_NONE, 'Disregard limit and display total count.')
            ->addOption('distinct', null, InputOption::VALUE_NONE, 'Report distinct records only.')
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
        $countRequest = (bool)$input->getOption('count');
        $distinct = (bool)$input->getOption('distinct');
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
            $sql = "SELECT
                    *,
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
