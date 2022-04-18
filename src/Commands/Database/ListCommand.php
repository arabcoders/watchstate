<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Entity\StateInterface;
use App\Libs\Storage\StorageInterface;
use Exception;
use PDO;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
            ->addOption('via', null, InputOption::VALUE_REQUIRED, 'Limit results to this specified server.')
            ->addOption('series', null, InputOption::VALUE_REQUIRED, 'Limit results to this specified series.')
            ->addOption('movie', null, InputOption::VALUE_REQUIRED, 'Limit results to this specified movie.')
            ->setDescription('List Database entries.');
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $list = [];

        $table = new Table($output);
        $table->setHeaders(
            [
                'ID',
                'Type',
                'Via',
                'Main Title',
                'Year | Episode',
                'Date',
                'Watched',
                'WH Event'
            ]
        );

        $params = [
            'limit' => (int)$input->getOption('limit'),
        ];

        $where = [];

        $sql = "SELECT * FROM state ";

        if ($input->getOption('via')) {
            $where[] = "json_extract(meta,'$.via') = :via";
            $params['via'] = $input->getOption('via');
        }

        if ($input->getOption('series')) {
            $where[] = "json_extract(meta,'$.series') = :series";
            $params['series'] = $input->getOption('series');
        }

        if ($input->getOption('movie')) {
            $where[] = "json_extract(meta,'$.title') = :movie";
            $params['movie'] = $input->getOption('movie');
        }

        if (count($where) >= 1) {
            $sql .= 'WHERE ' . implode(' AND ', $where);
        }

        $sql .= " ORDER BY updated DESC LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll();

        $rowCount = count($rows);

        $x = 0;

        foreach ($rows as $row) {
            $x++;

            $type = strtolower($row['type'] ?? '??');

            $meta = json_decode(ag($row, 'meta', '{}'), true);
            $number = '( ' . ($meta['year'] ?? 0) . ' )';

            if (StateInterface::TYPE_EPISODE === $type) {
                $number .= sprintf(
                    ' - S%sE%s',
                    str_pad((string)($meta['season'] ?? 0), 2, '0', STR_PAD_LEFT),
                    str_pad((string)($meta['episode'] ?? 0), 2, '0', STR_PAD_LEFT),
                );
            }

            $list[] = [
                $row['id'],
                ucfirst($row['type'] ?? '??'),
                $meta['via'] ?? '??',
                $meta['series'] ?? $meta['title'] ?? '??',
                $number,
                makeDate($row['updated']),
                true === (bool)$row['watched'] ? 'Yes' : 'No',
                $meta['webhook']['event'] ?? '-',
            ];

            if ($x < $rowCount) {
                $list[] = new TableSeparator();
            }
        }

        $rows = null;

        $table->setStyle('box')->setRows($list)->render();

        return self::SUCCESS;
    }
}
