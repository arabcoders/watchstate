<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Container;
use App\Libs\Entity\StateInterface;
use App\Libs\Storage\StorageInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class QueueCommand extends Command
{
    public function __construct(
        private CacheInterface $cache,
        private StorageInterface $storage,
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('db:queue')
            ->setDescription('Show webhook queued events.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws InvalidArgumentException
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->cache->has('queue')) {
            $output->writeln('<info>No items in the queue.</info>');
            return self::SUCCESS;
        }

        $entities = $items = [];

        foreach ($this->cache->get('queue', []) as $item) {
            $items[] = Container::get(StateInterface::class)::fromArray($item);
        }

        if (!empty($items)) {
            foreach ($this->storage->find(...$items) as $item) {
                $entities[$item->id] = $item;
            }
        }

        $items = null;

        if (empty($entities)) {
            $this->cache->delete('queue');
            $output->writeln('<info>No items in the queue.</info>');
            return self::SUCCESS;
        }

        $rows = [];

        $x = 0;
        $count = count($entities);

        foreach ($entities as $entity) {
            $x++;

            $rows[] = [
                $entity->id,
                $entity->getName(),
                $entity->isWatched() ? 'Yes' : 'No',
                $entity->via ?? '??',
                makeDate($entity->updated)->format('Y-m-d H:i:s T'),
            ];

            if ($x < $count) {
                $rows[] = new TableSeparator();
            }
        }

        (new Table($output))->setHeaders(['Id', 'Title', 'Played', 'Via', 'Date'])
            ->setStyle('box')->setRows($rows)->render();

        return self::SUCCESS;
    }
}
