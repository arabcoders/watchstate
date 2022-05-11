<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Container;
use App\Libs\Entity\StateInterface;
use App\Libs\Guid;
use App\Libs\Storage\StorageInterface;
use Exception;
use PDO;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class ListCommand extends Command
{
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
            ->setDescription('List Database entries.');

        foreach (array_keys(Guid::SUPPORTED) as $guid) {
            $guid = afterLast($guid, 'guid_');
            $this->addOption(
                $guid,
                null,
                InputOption::VALUE_REQUIRED,
                'Search Using ' . ucfirst($guid) . ' id.'
            );
        }

        $this->addOption('parent', null, InputOption::VALUE_NONE, 'If set it will search parent GUIDs instead.');
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $list = [];

        $params = [
            'limit' => (int)$input->getOption('limit'),
        ];

        $where = [];

        $sql = "SELECT * FROM state ";

        if ($input->getOption('id')) {
            $where[] = "id = :id";
            $params['id'] = $input->getOption('id');
        }

        if ($input->getOption('via')) {
            $where[] = "via = :via";
            $params['via'] = $input->getOption('via');
        }

        if ($input->getOption('type')) {
            $where[] = "type = :type";
            $params['type'] = match ($input->getOption('type')) {
                StateInterface::TYPE_MOVIE => StateInterface::TYPE_MOVIE,
                default => StateInterface::TYPE_EPISODE,
            };
        }

        if ($input->getOption('title')) {
            $where[] = "title LIKE '%' || :title || '%'";
            $params['title'] = $input->getOption('title');
        }

        if (null !== $input->getOption('season')) {
            $where[] = "season = :season";
            $params['season'] = $input->getOption('season');
        }

        if (null !== $input->getOption('episode')) {
            $where[] = "episode = :episode";
            $params['episode'] = $input->getOption('episode');
        }

        if ($input->getOption('parent')) {
            foreach (array_keys(Guid::SUPPORTED) as $guid) {
                if (null === ($val = $input->getOption(afterLast($guid, 'guid_')))) {
                    continue;
                }
                $where[] = "json_extract(parent,'$.{$guid}') = :{$guid}";
                $params[$guid] = $val;
            }
        } else {
            foreach (array_keys(Guid::SUPPORTED) as $guid) {
                if (null === ($val = $input->getOption(afterLast($guid, 'guid_')))) {
                    continue;
                }
                $where[] = "json_extract(guids,'$.{$guid}') = :{$guid}";
                $params[$guid] = $val;
            }
        }

        if (count($where) >= 1) {
            $sql .= 'WHERE ' . implode(' AND ', $where);
        }

        $sort = match ($input->getOption('sort')) {
            'id' => 'id',
            'season' => 'season',
            'episode' => 'episode',
            'type' => 'type',
            default => 'updated',
        };

        $sortOrder = ($input->getOption('asc')) ? 'ASC' : 'DESC';

        $sql .= " ORDER BY {$sort} {$sortOrder} LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll();

        $rowCount = count($rows);

        if (0 === $rowCount) {
            $output->writeln('<error>No Results. Probably invalid filters values were used.</error>');
            if (count($params) > 1) {
                array_shift($params);
                if ('json' === $input->getOption('output')) {
                    $output->writeln(
                        json_encode(
                            [
                                'Filters' => $params
                            ],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        )
                    );
                } else {
                    $output->writeln(Yaml::dump(['Filters' => $params], 8, 2));
                }
            }
            return self::FAILURE;
        }

        if ('json' === $input->getOption('output')) {
            foreach ($rows as &$row) {
                $row['watched'] = (bool)$row['watched'];
                $row['updated'] = makeDate($row['updated']);
                $row['guids'] = json_decode($row['guids'], true);
                $row['parent'] = json_decode($row['parent'], true);
                $row['extra'] = json_decode($row['extra'], true);
            }

            unset($row);

            $output->writeln(
                json_encode(
                    1 === count($rows) ? $rows[0] : $rows,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            );
        } elseif ('yaml' === $input->getOption('output')) {
            foreach ($rows as &$row) {
                $row['watched'] = (bool)$row['watched'];
                $row['updated'] = makeDate($row['updated']);
                $row['guids'] = json_decode($row['guids'], true);
                $row['parent'] = json_decode($row['parent'], true);
                $row['extra'] = json_decode($row['extra'], true);
            }

            unset($row);
            $output->writeln(Yaml::dump(1 === count($rows) ? $rows[0] : $rows, 8, 2));
        } else {
            $x = 0;

            foreach ($rows as $row) {
                $entity = Container::get(StateInterface::class)->fromArray($row);

                $x++;

                $list[] = [
                    $entity->id,
                    $entity->getName(),
                    $entity->via ?? '??',
                    makeDate($entity->updated)->format('Y-m-d H:i:s T'),
                    $entity->isWatched() ? 'Yes' : 'No',
                    ag($entity->extra, 'webhook.event', '-'),
                ];

                if ($x < $rowCount) {
                    $list[] = new TableSeparator();
                }
            }

            $rows = null;

            (new Table($output))->setHeaders(['Id', 'Main Title', 'Via (Temp)', 'Date', 'Played', 'WH Event'])
                ->setStyle('box')->setRows($list)->render();
        }

        return self::SUCCESS;
    }
}
