<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class QueueCommand
 *
 * This command is used to show webhook queued events.
 *
 * @package YourPackageNamespace
 */
#[Cli(command: self::ROUTE)]
class QueueCommand extends Command
{
    public const ROUTE = 'db:queue';

    /**
     * Class constructor.
     *
     * @param CacheInterface $cache The cache object to be injected.
     * @param iDB $db The database object to be injected.
     */
    public function __construct(private CacheInterface $cache, private iDB $db)
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('add', 'a', InputOption::VALUE_REQUIRED, 'Add record id to push queue.')
            ->addOption('remove', 'r', InputOption::VALUE_REQUIRED, 'Remove record id from push queue.')
            ->setDescription('Show webhook queued events.')
            ->setHelp(
                r(
                    <<<HELP

                    This command show items that was queued via <notice>webhook</notice> for change play state.

                    You can add items or remove item from the queue using the [<flag>-a, --add</flag>] and [<flag>-r, --remove</flag>] flags.

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
     * @return int The command's exit code.
     * @throws \Psr\Cache\InvalidArgumentException If the cache key is invalid.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        if (null !== ($id = $input->getOption('add'))) {
            $item = Container::get(iState::class)::fromArray(['id' => $id]);

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
            $item = Container::get(iState::class)::fromArray(['id' => $id]);

            queuePush($item, remove: true);
        }

        foreach ($queue as $item) {
            $items[] = Container::get(iState::class)::fromArray($item);
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

        foreach ($entities as $entity) {
            $rows[] = [
                'id' => $entity->id,
                'title' => $entity->getName(),
                'played' => $entity->isWatched() ? 'Yes' : 'No',
                'via' => $entity->via ?? '??',
                'date' => makeDate($entity->updated)->format('Y-m-d H:i:s T'),
                'event' => ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_EVENT),
            ];
        }

        $this->displayContent($rows, $output, $input->getOption('output'));

        return self::SUCCESS;
    }
}
