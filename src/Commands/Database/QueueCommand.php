<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface;
use App\Libs\Routable;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
class QueueCommand extends Command
{
    public const ROUTE = 'db:queue';

    public function __construct(private CacheInterface $cache, private iDB $db)
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('add', 'a', InputOption::VALUE_REQUIRED, 'Add record id to push queue.')
            ->addOption('remove', 'r', InputOption::VALUE_REQUIRED, 'Remove record id from push queue.')
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
        if (null !== ($id = $input->getOption('add'))) {
            $item = Container::get(StateInterface::class)::fromArray(['id' => $id]);

            if (null === ($item = $this->db->get($item))) {
                $output->writeln(sprintf('<error>Record id \'%d\' does not exists.</error>', $id));
                return self::FAILURE;
            }

            queuePush($item);
        }

        if (!$this->cache->has('queue')) {
            $output->writeln('<info>No items in the queue.</info>');
            return self::SUCCESS;
        }

        $entities = $items = [];

        $queue = $this->cache->get('queue', []);

        if (null !== ($id = $input->getOption('remove'))) {
            if (!array_key_exists($id, $queue)) {
                $output->writeln(sprintf('<error>Record id \'%d\' does not exists in the queue.</error>', $id));
                return self::FAILURE;
            }

            unset($queue[$id]);
            $item = Container::get(StateInterface::class)::fromArray(['id' => $id]);

            queuePush($item, remove: true);
        }

        foreach ($queue as $item) {
            $items[] = Container::get(StateInterface::class)::fromArray($item);
        }

        if (!empty($items)) {
            foreach ($this->db->find(...$items) as $item) {
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
