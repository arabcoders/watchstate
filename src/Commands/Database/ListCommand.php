<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use App\Libs\Storage\StorageInterface;
use Exception;
use PDO;
use RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class ListCommand extends Command
{
    public const CHANGEABLE_COLUMNS = [
        iFace::COLUMN_WATCHED,
        iFace::COLUMN_VIA,
        iFace::COLUMN_TITLE,
        iFace::COLUMN_YEAR,
        iFace::COLUMN_SEASON,
        iFace::COLUMN_EPISODE,
        iFace::COLUMN_UPDATED,
    ];

    private PDO $pdo;

    public function __construct(private StorageInterface $storage)
    {
        $this->pdo = $this->storage->getPdo();

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('db:list')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit results to this number', 20)
            ->addOption(
                'via',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit results to this specified server. This filter is not reliable. and changes based on last server query.'
            )
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Display output as [json, yaml, table]', 'table')
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit results to this specified type can be [movie or episode].'
            )
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Limit results to this specified tv show.')
            ->addOption('season', null, InputOption::VALUE_REQUIRED, 'Select season number')
            ->addOption('episode', null, InputOption::VALUE_REQUIRED, 'Select episode number')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Select db record number')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'sort order by [id, updated]', 'updated')
            ->addOption('asc', null, InputOption::VALUE_NONE, 'Sort records in ascending order.')
            ->addOption('desc', null, InputOption::VALUE_NONE, 'Sort records in descending order. (Default)')
            ->addOption(
                'metadata-as',
                null,
                InputOption::VALUE_REQUIRED,
                'Display metadata from this server instead of latest.'
            )
            ->setDescription('List Database entries.');

        foreach (array_keys(Guid::SUPPORTED) as $guid) {
            $guid = afterLast($guid, 'guid_');
            $this->addOption(
                $guid,
                null,
                InputOption::VALUE_REQUIRED,
                'Search Using ' . ucfirst($guid) . ' external id.'
            );
        }

        $this->addOption('parent', null, InputOption::VALUE_NONE, 'If set it will search parent external ids instead.')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'For JSON Fields key selection.')
            ->addOption('value', null, InputOption::VALUE_REQUIRED, 'For JSON Fields value selection.')
            ->addOption(
                'metadata',
                null,
                InputOption::VALUE_NONE,
                'Search in (metadata) provided by servers JSON Field using (--key, --value) options.'
            )
            ->addOption(
                'extra',
                null,
                InputOption::VALUE_NONE,
                'Search in (extra information) JSON Field using (--key, --value) options.'
            );
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $list = [];

        $limit = (int)$input->getOption('limit');

        $params = [
            'limit' => $limit <= 0 ? 20 : $limit,
        ];

        $where = [];

        $sql = "SELECT * FROM state ";

        if ($input->getOption('id')) {
            $where[] = iFace::COLUMN_ID . ' = :id';
            $params['id'] = $input->getOption('id');
        }

        if ($input->getOption('via')) {
            $where[] = iFace::COLUMN_VIA . ' = :via';
            $params['via'] = $input->getOption('via');
        }

        if ($input->getOption('type')) {
            $where[] = iFace::COLUMN_TYPE . ' = :type';
            $params['type'] = match ($input->getOption('type')) {
                iFace::TYPE_MOVIE => iFace::TYPE_MOVIE,
                default => iFace::TYPE_EPISODE,
            };
        }

        if ($input->getOption('title')) {
            $where[] = iFace::COLUMN_TITLE . " LIKE '%' || :title || '%'";
            $params['title'] = $input->getOption('title');
        }

        if (null !== $input->getOption('season')) {
            $where[] = iFace::COLUMN_SEASON . ' = :season';
            $params['season'] = $input->getOption('season');
        }

        if (null !== $input->getOption('episode')) {
            $where[] = iFace::COLUMN_EPISODE . ' = :episode';
            $params['episode'] = $input->getOption('episode');
        }

        if ($input->getOption('parent')) {
            foreach (array_keys(Guid::SUPPORTED) as $guid) {
                if (null === ($val = $input->getOption(afterLast($guid, 'guid_')))) {
                    continue;
                }
                $where[] = "json_extract(" . iFace::COLUMN_PARENT . ",'$.{$guid}') = :{$guid}";
                $params[$guid] = $val;
            }
        } else {
            foreach (array_keys(Guid::SUPPORTED) as $guid) {
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
            if (empty($sField) || empty($sValue)) {
                throw new RuntimeException(
                    'When searching using JSON fields the option --key and --value must be set.'
                );
            }

            $where[] = "json_extract(" . iFace::COLUMN_META_DATA . ",'$.{$sField}') = :jf_metadata_value";
            $params['jf_metadata_value'] = $sValue;
        }

        if ($input->getOption('extra')) {
            $sField = $input->getOption('key');
            $sValue = $input->getOption('value');
            if (empty($sField) || empty($sValue)) {
                throw new RuntimeException(
                    'When searching using JSON fields the option --key and --value must be set.'
                );
            }

            $where[] = "json_extract(" . iFace::COLUMN_EXTRA . ",'$.{$sField}') = :jf_extra_value";
            $params['jf_extra_value'] = $sValue;
        }

        if (count($where) >= 1) {
            $sql .= 'WHERE ' . implode(' AND ', $where);
        }

        $sort = match ($input->getOption('sort')) {
            'id' => iFace::COLUMN_ID,
            'season' => iFace::COLUMN_SEASON,
            'episode' => iFace::COLUMN_EPISODE,
            'type' => iFace::COLUMN_TYPE,
            default => iFace::COLUMN_UPDATED,
        };

        $sortOrder = ($input->getOption('asc')) ? 'ASC' : 'DESC';

        $sql .= " ORDER BY {$sort} {$sortOrder} LIMIT :limit";

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

                foreach (self::CHANGEABLE_COLUMNS as $column) {
                    if (null === ($path[$column] ?? null)) {
                        continue;
                    }

                    $row[$column] = 'int' === get_debug_type($row[$column]) ? (int)$path[$column] : $path[$column];
                }
                if (null !== ($row[iFace::COLUMN_EXTRA][$via][iFace::COLUMN_EXTRA_DATE] ?? null)) {
                    $row[iFace::COLUMN_UPDATED] = $row[iFace::COLUMN_EXTRA][$via][iFace::COLUMN_EXTRA_DATE];
                }
            }

            $row[iFace::COLUMN_WATCHED] = (bool)$row[iFace::COLUMN_WATCHED];
            $row[iFace::COLUMN_UPDATED] = makeDate($row[iFace::COLUMN_UPDATED]);
        }

        unset($row);

        if ('json' === $input->getOption('output')) {
            $output->writeln(
                json_encode(
                    1 === count($rows) ? $rows[0] : $rows,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            );
        } elseif ('yaml' === $input->getOption('output')) {
            $output->writeln(Yaml::dump(1 === count($rows) ? $rows[0] : $rows, 8, 2));
        } else {
            $x = 0;

            foreach ($rows as $row) {
                $row[iFace::COLUMN_UPDATED] = $row[iFace::COLUMN_UPDATED]->getTimestamp();
                $row[iFace::COLUMN_WATCHED] = (int)$row[iFace::COLUMN_WATCHED];
                $entity = Container::get(iFace::class)->fromArray($row);

                $x++;

                $list[] = [
                    $entity->id,
                    ucfirst($entity->type),
                    $entity->getName(),
                    $entity->via ?? '??',
                    makeDate($entity->updated)->format('Y-m-d H:i:s T'),
                    $entity->isWatched() ? 'Yes' : 'No',
                    ag($entity->extra[$entity->via] ?? [], iFace::COLUMN_EXTRA_EVENT, '-'),
                ];

                if ($x < $rowCount) {
                    $list[] = new TableSeparator();
                }
            }

            $rows = null;

            (new Table($output))->setHeaders(['Id', 'Type', 'Title', 'Via (Last)', 'Date', 'Played', 'Webhook Event'])
                ->setStyle('box')->setRows($list)->render();
        }

        return self::SUCCESS;
    }
}
