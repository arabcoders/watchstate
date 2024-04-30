<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Guid;
use App\Libs\Mappers\Import\DirectMapper;
use PDO;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ListCommand
 *
 * This class act as frontend for the state table, it allows the user to see and manipulate view of state table.
 */
#[Cli(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const string ROUTE = 'db:list';

    /**
     * @var array The array containing the names of the columns that can be modified for viewing purposes.
     */
    public const array COLUMNS_CHANGEABLE = [
        iState::COLUMN_WATCHED,
        iState::COLUMN_VIA,
        iState::COLUMN_TITLE,
        iState::COLUMN_YEAR,
        iState::COLUMN_SEASON,
        iState::COLUMN_EPISODE,
        iState::COLUMN_UPDATED,
    ];

    /**
     * @var array The array containing the names of the columns that the list can be sorted by.
     */
    public const array COLUMNS_SORTABLE = [
        iState::COLUMN_ID,
        iState::COLUMN_TYPE,
        iState::COLUMN_UPDATED,
        iState::COLUMN_WATCHED,
        iState::COLUMN_VIA,
        iState::COLUMN_TITLE,
        iState::COLUMN_YEAR,
        iState::COLUMN_SEASON,
        iState::COLUMN_EPISODE,
    ];

    private PDO $pdo;

    /**
     * Class constructor.
     *
     * @param iDB $db The database object.
     * @param DirectMapper $mapper The direct mapper object.
     *
     * @return void
     */
    public function __construct(private iDB $db, private DirectMapper $mapper)
    {
        $this->pdo = $this->db->getPDO();

        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $list = [];

        foreach (array_keys(Guid::getSupported()) as $guid) {
            $guid = afterLast($guid, 'guid_');
            $list[] = '<value>' . $guid . '</value>';
        }

        $list = implode(', ', $list);

        $this->setName(self::ROUTE)
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit results to this number', 20)
            ->addOption(
                'via',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit results to this specified backend. This filter is not reliable. and changes based on last backend query.'
            )
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit results to this specified type can be [movie or episode].'
            )
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Limit results to this specified title.')
            ->addOption('season', null, InputOption::VALUE_REQUIRED, 'Select season number.')
            ->addOption('episode', null, InputOption::VALUE_REQUIRED, 'Select episode number.')
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'Select year.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Select db record number.')
            ->addOption(
                'sort',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Set sort by columns. [Example: <flag>--sort</flag> <value>season:asc</value>].',
            )
            ->addOption(
                'show-as',
                null,
                InputOption::VALUE_REQUIRED,
                'Switch the default metadata display to the chosen backend instead of latest metadata.'
            )
            ->addOption(
                'guid',
                null,
                InputOption::VALUE_REQUIRED,
                'Search <notice>item</notice> external db ids. [Format: <value>db://id</value>].'
            )
            ->addOption(
                'parent',
                null,
                InputOption::VALUE_REQUIRED,
                'Search <notice>parent</notice> external db ids. [Format: <value>db://id</value>].'
            )
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'For <notice>JSON Fields</notice> key selection.')
            ->addOption('value', null, InputOption::VALUE_REQUIRED, 'For <notice>JSON Fields</notice> value selection.')
            ->addOption(
                'metadata',
                null,
                InputOption::VALUE_NONE,
                'Search in (<notice>metadata</notice>) provided by backends JSON field. Expects [<flag>--key</flag>, <flag>--value</flag>] flags.'
            )
            ->addOption(
                'extra',
                null,
                InputOption::VALUE_NONE,
                'Search in (<notice>extra</notice>) info by backends JSON field. Expects [<flag>--key</flag>, <flag>--value</flag>] flags.'
            )
            ->addOption(
                'dump-query',
                null,
                InputOption::VALUE_NONE,
                'Dump the generated query and exit.'
            )
            ->addOption(
                'exact',
                null,
                InputOption::VALUE_NONE,
                'Use <notice>equal</notice> check instead of <notice>LIKE</notice> for JSON field query.'
            )
            ->addOption(
                'mark-as',
                'm',
                InputOption::VALUE_REQUIRED,
                'Change items play state. Expects [<value>played</value>, <value>unplayed</value>] as value.'
            )
            ->setDescription('List Database entries.')
            ->setHelp(
                r(
                    <<<HELP

                    This command show your <notice>current</notice> stored play state.
                    This command is powerful tool to explore your database and the metadata gathered
                    about your media files. Please do read the options it's just too many to list here.

                    -------------------
                    <notice>[ Expected Values ]</notice>
                    -------------------

                    <flag>guid</flag>, <flag>parent</flag> expects the format to be [<value>db</value>://<value>id</value>]. Where the db refers to [{dbs_list}].

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to search JSON fields?</question>

                    You can search JSON fields [<notice>metadata</notice>, <notice>extra</notice>] by using the corresponding flags.
                    [<flag>--metadata</flag>, <flag>--extra</flag>] Searching JSON fields require the use of [<flag>--key</flag>] and [<flag>--value</flag>] flags as well.
                    Unlike regular table fields JSON fields does not have fixed schema. You can alter the search mode by using [<flag>--exact</flag>] flag
                    that will switch the search mode from loose to strict match.

                    For example, To search for item that match backend id, you would run the following:

                    {cmd} <cmd>{route}</cmd> <flag>--key</flag> '<value>backend_name</value>.id' <flag>--value</flag> '<value>backend_item_id</value>' <flag>--metadata</flag>

                    <question># How to mark items as played/unplayed?</question>

                    First Use filters to narrow down the list. then add the [<flag>-m</flag>, <flag>--mark-as</flag>] flag with one value of [<value>played</value>, <value>unplayed</value>].

                    Example, to mark a show that has id of [<value>tvdb://269586</value>], you would do something like.

                    {cmd} <cmd>{route}</cmd> <flag>--parent</flag> <value>tvdb://269586</value> <flag>--mark-as</flag> <value>played</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'dbs_list' => $list,
                    ]
                )
            );
    }

    /**
     * Runs a command and returns the number of rows affected.
     *
     * @param InputInterface $input The input object.
     * @param OutputInterface $output The output object.
     *
     * @return int The number of rows affected.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int)$input->getOption('limit');

        if (null !== ($changeState = $input->getOption('mark-as'))) {
            $limit = PHP_INT_MAX;
        }

        $es = fn(string $val) => $this->db->identifier($val);

        $params = [
            'limit' => $limit <= 0 ? 20 : $limit,
        ];

        $sql = $where = [];

        $sql[] = sprintf('SELECT * FROM %s', $es('state'));

        if ($input->getOption('id')) {
            $where[] = $es(iState::COLUMN_ID) . ' = :id';
            $params['id'] = $input->getOption('id');
        }

        if ($input->getOption('via')) {
            $where[] = $es(iState::COLUMN_VIA) . ' = :via';
            $params['via'] = $input->getOption('via');
        }

        if ($input->getOption('year')) {
            $where[] = $es(iState::COLUMN_YEAR) . ' = :year';
            $params['year'] = $input->getOption('year');
        }

        if ($input->getOption('type')) {
            $where[] = $es(iState::COLUMN_TYPE) . ' = :type';
            $params['type'] = match ($input->getOption('type')) {
                iState::TYPE_MOVIE => iState::TYPE_MOVIE,
                default => iState::TYPE_EPISODE,
            };
        }

        if ($input->getOption('title')) {
            $where[] = $es(iState::COLUMN_TITLE) . ' LIKE "%" || :title || "%"';
            $params['title'] = $input->getOption('title');
        }

        if (null !== $input->getOption('season')) {
            $where[] = $es(iState::COLUMN_SEASON) . ' = :season';
            $params['season'] = $input->getOption('season');
        }

        if (null !== $input->getOption('episode')) {
            $where[] = $es(iState::COLUMN_EPISODE) . ' = :episode';
            $params['episode'] = $input->getOption('episode');
        }

        if (null !== ($parent = $input->getOption('parent'))) {
            $d = Guid::fromArray(['guid_' . before($parent, '://') => after($parent, '://')]);
            $parent = array_keys($d->getAll())[0] ?? null;

            if (null === $parent) {
                $output->writeln(
                    '<error>ERROR:</error> Invalid value for [<flag>--parent</flag>] expected value format is [<value>db://id</value>].'
                );
                return self::INVALID;
            }

            $where[] = "json_extract(" . iState::COLUMN_PARENT . ",'$.{$parent}') = :parent";
            $params['parent'] = array_values($d->getAll())[0];
        }

        if (null !== ($guid = $input->getOption('guid'))) {
            $d = Guid::fromArray(['guid_' . before($guid, '://') => after($guid, '://')]);
            $guid = array_keys($d->getAll())[0] ?? null;

            if (null === $guid) {
                $output->writeln(
                    '<error>ERROR:</error> Invalid value for [<flag>--guid</flag>] expected value format is [<value>db://id</value>]'
                );
                return self::INVALID;
            }

            $where[] = "json_extract(" . iState::COLUMN_GUIDS . ",'$.{$guid}') = :guid";
            $params['guid'] = array_values($d->getAll())[0];
        }

        if ($input->getOption('metadata')) {
            $sField = $input->getOption('key');
            $sValue = $input->getOption('value');
            if (null === $sField || null === $sValue) {
                throw new RuntimeException(
                    'When searching using JSON fields the option --key and --value must be set.'
                );
            }

            if ($input->getOption('exact')) {
                $where[] = "json_extract(" . iState::COLUMN_META_DATA . ",'$.{$sField}') = :jf_metadata_value ";
            } else {
                $where[] = "json_extract(" . iState::COLUMN_META_DATA . ",'$.{$sField}') LIKE \"%\" || :jf_metadata_value || \"%\"";
            }

            $params['jf_metadata_value'] = $sValue;
        }

        if ($input->getOption('extra')) {
            $sField = $input->getOption('key');
            $sValue = $input->getOption('value');
            if (null === $sField || null === $sValue) {
                throw new RuntimeException(
                    'When searching using JSON fields the option --key and --value must be set.'
                );
            }

            if ($input->getOption('exact')) {
                $where[] = "json_extract(" . iState::COLUMN_EXTRA . ",'$.{$sField}') = :jf_extra_value";
            } else {
                $where[] = "json_extract(" . iState::COLUMN_EXTRA . ",'$.{$sField}') LIKE \"%\" || :jf_extra_value || \"%\"";
            }

            $params['jf_extra_value'] = $sValue;
        }

        if (count($where) >= 1) {
            $sql[] = 'WHERE ' . implode(' AND ', $where);
        }

        $sorts = [];

        foreach ($input->getOption('sort') as $sort) {
            if (1 !== preg_match('/(?P<field>\w+)(:(?P<dir>\w+))?/', $sort, $matches)) {
                continue;
            }

            if (null === ($matches['field'] ?? null) || false === in_array($matches['field'], self::COLUMNS_SORTABLE)) {
                continue;
            }

            $sorts[] = sprintf(
                '%s %s',
                $es($matches['field']),
                match (strtolower($matches['dir'] ?? 'desc')) {
                    default => 'DESC',
                    'asc' => 'ASC',
                }
            );
        }

        if (count($sorts) < 1) {
            $sorts[] = sprintf('%s DESC', $es('updated'));
        }

        $sql[] = 'ORDER BY ' . implode(', ', $sorts) . ' LIMIT :limit';
        $sql = implode(' ', array_map('trim', $sql));

        if ($input->getOption('dump-query')) {
            $arr = [
                'query' => $sql,
                'parameters' => $params,
            ];

            if ('table' === $input->getOption('output')) {
                $arr = [$arr];

                $arr[0]['parameters'] = arrayToString($params);

                $arr[1] = [
                    'query' => $this->db->getRawSQLString($sql, $params),
                    'parameters' => 'raw sql query',
                ];
            } else {
                $arr['raw'] = $this->db->getRawSQLString($sql, $params);
            }

            $this->displayContent($arr, $output, $input->getOption('output'));
            return self::SUCCESS;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll();

        $rowCount = count($rows);

        if (0 === $rowCount) {
            $arr = [
                'Error' => 'No Results.',
                'Filters' => $params
            ];

            if (true === ($hasFilters = count($arr['Filters']) > 1)) {
                $arr['Error'] .= ' Probably invalid filters values were used.';
            }

            if ($hasFilters && 'table' !== $input->getOption('output')) {
                array_shift($arr['Filters']);
                if ('json' === $input->getOption('output')) {
                    $output->writeln(
                        json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                } elseif ('yaml' === $input->getOption('output')) {
                    $output->writeln(Yaml::dump($arr, 8, 2));
                }
            } else {
                $output->writeln('<error>' . $arr['Error'] . '</error>');
            }

            return self::FAILURE;
        }

        foreach ($rows as &$row) {
            foreach (iState::ENTITY_ARRAY_KEYS as $key) {
                if (null === ($row[$key] ?? null)) {
                    continue;
                }
                $row[$key] = json_decode($row[$key], true);
            }

            if (null !== ($via = $input->getOption('show-as'))) {
                $path = $row[iState::COLUMN_META_DATA][$via] ?? [];

                foreach (self::COLUMNS_CHANGEABLE as $column) {
                    if (null === ($path[$column] ?? null)) {
                        continue;
                    }

                    $row[$column] = 'int' === get_debug_type($row[$column]) ? (int)$path[$column] : $path[$column];
                }

                if (null !== ($dateFromBackend = $path[iState::COLUMN_META_DATA_PLAYED_AT] ?? $path[iState::COLUMN_META_DATA_ADDED_AT] ?? null)) {
                    $row[iState::COLUMN_UPDATED] = $dateFromBackend;
                }
            }

            $row[iState::COLUMN_WATCHED] = (bool)$row[iState::COLUMN_WATCHED];
            $row[iState::COLUMN_UPDATED] = makeDate($row[iState::COLUMN_UPDATED]);
        }

        unset($row);

        if ('table' === $input->getOption('output')) {
            foreach ($rows as &$row) {
                $row[iState::COLUMN_UPDATED] = $row[iState::COLUMN_UPDATED]->getTimestamp();
                $row[iState::COLUMN_WATCHED] = (int)$row[iState::COLUMN_WATCHED];
                $entity = Container::get(iState::class)->fromArray($row);

                $row = [
                    'id' => $entity->id,
                    'type' => ucfirst($entity->type),
                    'title' => $entity->getName(),
                    'via' => $entity->via ?? '??',
                    'date' => makeDate($entity->updated)->format('Y-m-d H:i:s T'),
                    'played' => $entity->isWatched() ? 'Yes' : 'No',
                    'progress' => $entity->hasPlayProgress() ? $entity->getPlayProgress() : 'None',
                    'event' => ag($entity->extra[$entity->via] ?? [], iState::COLUMN_EXTRA_EVENT, '-'),
                ];
            }
            unset($row);
        }

        $this->displayContent($rows, $output, $input->getOption('output'));

        if (null !== $changeState && count($rows) >= 1) {
            $changeState = strtolower($changeState);
            if (!$input->getOption('no-interaction')) {
                $text = r(
                    '<question>Are you sure you want to mark [<notce>{total}</notce>] items as [<notice>{state}</notice>]</question> ? [<value>Y|N</value>] [<value>Default: No</value>]',
                    [
                        'total' => count($rows),
                        'state' => 'played' === $changeState ? 'Played' : 'Unplayed',
                    ]
                );

                $question = new ConfirmationQuestion($text . PHP_EOL . '> ', false);

                if (false === $this->getHelper('question')->ask($input, $output, $question)) {
                    return self::FAILURE;
                }
            }

            foreach ($rows as $row) {
                $entity = $this->mapper->get(
                    Container::get(iState::class)->fromArray([iState::COLUMN_ID => $row['id']])
                );

                $entity->watched = 'played' === $changeState ? 1 : 0;
                $entity->updated = time();
                $entity->extra = ag_set($entity->getExtra(), $entity->via, [
                    iState::COLUMN_EXTRA_EVENT => 'cli.mark' . ($entity->isWatched() ? 'played' : 'unplayed'),
                    iState::COLUMN_EXTRA_DATE => (string)makeDate('now'),
                ]);

                $this->mapper->add($entity);

                queuePush($entity);
            }

            $output->writeln(
                r('<info>Successfully marked [<notice>{total}</notice>] items as [<notice>{state}</notice>].', [
                    'total' => count($rows),
                    'state' => 'played' === $changeState ? 'Played' : 'Unplayed',
                ])
            );
        }

        return self::SUCCESS;
    }

    /**
     * Completes the given suggestions for a specific input.
     *
     * @param CompletionInput $input The completion input object.
     * @param CompletionSuggestions $suggestions The completion suggestions object.
     *
     * @return void
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestOptionValuesFor('via') || $input->mustSuggestOptionValuesFor('show-as')) {
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

            foreach ([iState::TYPE_MOVIE, iState::TYPE_EPISODE] as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }

        if ($input->mustSuggestOptionValuesFor('mark-as')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (['played', 'unplayed'] as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }

        if ($input->mustSuggestOptionValuesFor('sort')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (self::COLUMNS_SORTABLE as $name) {
                foreach ([$name . ':desc', $name . ':asc'] as $subName) {
                    if (empty($currentValue) || true === str_starts_with($subName, $currentValue)) {
                        $suggest[] = $subName;
                    }
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
