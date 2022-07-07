<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use App\Libs\Routable;
use Exception;
use PDO;
use RuntimeException;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[Routable(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const ROUTE = 'db:list';

    private const COLUMNS_CHANGEABLE = [
        iFace::COLUMN_WATCHED,
        iFace::COLUMN_VIA,
        iFace::COLUMN_TITLE,
        iFace::COLUMN_YEAR,
        iFace::COLUMN_SEASON,
        iFace::COLUMN_EPISODE,
        iFace::COLUMN_UPDATED,
    ];

    private const COLUMNS_SORTABLE = [
        iFace::COLUMN_ID,
        iFace::COLUMN_TYPE,
        iFace::COLUMN_UPDATED,
        iFace::COLUMN_WATCHED,
        iFace::COLUMN_VIA,
        iFace::COLUMN_TITLE,
        iFace::COLUMN_YEAR,
        iFace::COLUMN_SEASON,
        iFace::COLUMN_EPISODE,
    ];

    private PDO $pdo;

    public function __construct(private iDB $db)
    {
        $this->pdo = $this->db->getPdo();

        parent::__construct();
    }

    protected function configure(): void
    {
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
                'Set sort by columns. for example, <comment>--sort season:asc --sort episode:desc</comment>.',
            )
            ->addOption(
                'metadata-as',
                null,
                InputOption::VALUE_REQUIRED,
                'Display metadata from this backend instead of latest.'
            )
            ->setDescription('List Database entries.');

        foreach (array_keys(Guid::getSupported(includeVirtual: false)) as $guid) {
            $guid = afterLast($guid, 'guid_');
            $this->addOption(
                $guid,
                null,
                InputOption::VALUE_REQUIRED,
                'Search Using <info>' . $guid . '</info> external id.'
            );
        }

        $this->addOption('parent', null, InputOption::VALUE_NONE, 'If set it will search parent external ids instead.')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'For JSON Fields key selection.')
            ->addOption('value', null, InputOption::VALUE_REQUIRED, 'For JSON Fields value selection.')
            ->addOption(
                'metadata',
                null,
                InputOption::VALUE_NONE,
                'Search in (metadata) provided by backends JSON Field using (--key, --value) options.'
            )
            ->addOption(
                'extra',
                null,
                InputOption::VALUE_NONE,
                'Search in (extra information) JSON Field using (--key, --value) options.'
            )
            ->addOption(
                'dump-query',
                null,
                InputOption::VALUE_NONE,
                'Dump the generated query and exit.'
            );
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int)$input->getOption('limit');

        $es = fn(string $val) => $this->db->identifier($val);

        $params = [
            'limit' => $limit <= 0 ? 20 : $limit,
        ];

        $sql = $where = [];

        $sql[] = sprintf('SELECT * FROM %s', $es('state'));

        if ($input->getOption('id')) {
            $where[] = $es(iFace::COLUMN_ID) . ' = :id';
            $params['id'] = $input->getOption('id');
        }

        if ($input->getOption('via')) {
            $where[] = $es(iFace::COLUMN_VIA) . ' = :via';
            $params['via'] = $input->getOption('via');
        }

        if ($input->getOption('year')) {
            $where[] = $es(iFace::COLUMN_YEAR) . ' = :year';
            $params['year'] = $input->getOption('year');
        }

        if ($input->getOption('type')) {
            $where[] = $es(iFace::COLUMN_TYPE) . ' = :type';
            $params['type'] = match ($input->getOption('type')) {
                iFace::TYPE_MOVIE => iFace::TYPE_MOVIE,
                default => iFace::TYPE_EPISODE,
            };
        }

        if ($input->getOption('title')) {
            $where[] = $es(iFace::COLUMN_TITLE) . ' LIKE "%" || :title || "%"';
            $params['title'] = $input->getOption('title');
        }

        if (null !== $input->getOption('season')) {
            $where[] = $es(iFace::COLUMN_SEASON) . ' = :season';
            $params['season'] = $input->getOption('season');
        }

        if (null !== $input->getOption('episode')) {
            $where[] = $es(iFace::COLUMN_EPISODE) . ' = :episode';
            $params['episode'] = $input->getOption('episode');
        }

        if ($input->getOption('parent')) {
            foreach (array_keys(Guid::getSupported(includeVirtual: false)) as $guid) {
                if (null === ($val = $input->getOption(afterLast($guid, 'guid_')))) {
                    continue;
                }
                $where[] = "json_extract(" . iFace::COLUMN_PARENT . ",'$.{$guid}') = :{$guid}";
                $params[$guid] = $val;
            }
        } else {
            foreach (array_keys(Guid::getSupported(includeVirtual: false)) as $guid) {
                if (null === ($val = $input->getOption(afterLast($guid, 'guid_')))) {
                    continue;
                }
                $where[] = "json_extract(" . iFace::COLUMN_GUIDS . ",'$.{$guid}') = :{$guid}";
                $params[$guid] = $val;
            }
        }

        if ($input->getOption('metadata')) {
            $sField = $input->getOption('key');
            $sValue = $input->getOption('value');
            if (null === $sField || null === $sValue) {
                throw new RuntimeException(
                    'When searching using JSON fields the option --key and --value must be set.'
                );
            }

            $where[] = "json_extract(" . iFace::COLUMN_META_DATA . ",'$.{$sField}') LIKE \"%\" || :jf_metadata_value || \"%\"";
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

            $where[] = "json_extract(" . iFace::COLUMN_EXTRA . ",'$.{$sField}') LIKE \"%\" || :jf_extra_value || \"%\"";
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
        $sql = implode(' ', $sql);

        if ($input->getOption('dump-query')) {
            $arr = [
                'query' => $sql,
                'parameters' => $params,
                'raw' => $this->db->getRawSQLString($sql, $params),
            ];

            if ('table' === $input->getOption('output')) {
                $arr['parameters'] = arrayToString($params);
                unset($arr['raw']);
                $arr = [$arr];
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
            foreach (iFace::ENTITY_ARRAY_KEYS as $key) {
                if (null === ($row[$key] ?? null)) {
                    continue;
                }
                $row[$key] = json_decode($row[$key], true);
            }

            if (null !== ($via = $input->getOption('metadata-as'))) {
                $path = $row[iFace::COLUMN_META_DATA][$via] ?? [];

                foreach (self::COLUMNS_CHANGEABLE as $column) {
                    if (null === ($path[$column] ?? null)) {
                        continue;
                    }

                    $row[$column] = 'int' === get_debug_type($row[$column]) ? (int)$path[$column] : $path[$column];
                }

                if (null !== ($dateFromBackend = $path[iFace::COLUMN_META_DATA_PLAYED_AT] ?? $path[iFace::COLUMN_META_DATA_ADDED_AT] ?? null)) {
                    $row[iFace::COLUMN_UPDATED] = $dateFromBackend;
                }
            }

            $row[iFace::COLUMN_WATCHED] = (bool)$row[iFace::COLUMN_WATCHED];
            $row[iFace::COLUMN_UPDATED] = makeDate($row[iFace::COLUMN_UPDATED]);
        }

        unset($row);

        if ('table' === $input->getOption('output')) {
            $list = [];

            foreach ($rows as $row) {
                $row[iFace::COLUMN_UPDATED] = $row[iFace::COLUMN_UPDATED]->getTimestamp();
                $row[iFace::COLUMN_WATCHED] = (int)$row[iFace::COLUMN_WATCHED];
                $entity = Container::get(iFace::class)->fromArray($row);

                $item = [
                    'id' => $entity->id,
                    'Type' => ucfirst($entity->type),
                    'Title' => $entity->getName(),
                    'Via (Last)' => $entity->via ?? '??',
                    'Date' => makeDate($entity->updated)->format('Y-m-d H:i:s T'),
                    'Played' => $entity->isWatched() ? 'Yes' : 'No',
                    'Via (Event)' => ag($entity->extra[$entity->via] ?? [], iFace::COLUMN_EXTRA_EVENT, '-'),
                ];

                $list[] = $item;
                $list[] = new TableSeparator();
            }

            $rows = null;

            if (count($list) >= 2) {
                array_pop($list);
            }

            (new Table($output))->setHeaders(array_keys($list[0] ?? []))->setStyle('box')->setRows($list)->render();
        } else {
            $this->displayContent($rows, $output, $input->getOption('output'));
        }

        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestOptionValuesFor('via') || $input->mustSuggestOptionValuesFor('metadata-as')) {
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

            foreach ([iFace::TYPE_MOVIE, iFace::TYPE_EPISODE] as $name) {
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
