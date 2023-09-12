<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Libs\Routable;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
class RequestsCommand extends Command
{
    public const ROUTE = 'state:requests';

    public const TASK_NAME = 'requests';

    public function __construct(private iLogger $logger, private iCache $cache, private DirectMapper $mapper)
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Process queued requests.')
            ->addOption('keep', 'k', InputOption::VALUE_NONE, 'Do not expunge queue after run is complete.')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List queued requests.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit changes to backends.')
            ->addOption('no-stats', null, InputOption::VALUE_NONE, 'Do not display end of run stats.')
            ->setHelp('This command process <notice>queued</notice> http requests.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws InvalidArgumentException
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output);
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function process(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->cache->has('requests')) {
            $this->logger->info('No requests in the queue.');
            return self::SUCCESS;
        }

        $queued = [];
        $requests = $this->cache->get('requests', []);

        if (count($requests) < 1) {
            $this->logger->info('No requests in the queue.');
            return self::SUCCESS;
        }

        if ($input->getOption('list')) {
            return $this->listItems($input, $output, $requests);
        }

        if ($input->getOption('dry-run')) {
            $this->logger->info('Dry run mode. No changes will be committed.');
        }

        $this->mapper->setOptions([
            Options::DRY_RUN => $input->getOption('dry-run'),
            Options::DEBUG_TRACE => $input->getOption('trace')
        ]);

        $fn = function (iState $state) use (&$queued) {
            $queued[$state->id] = $state;
        };

        foreach ($requests as $request) {
            $entity = ag($request, 'entity');
            assert($entity instanceof iState);

            $options = ag($request, 'options', []);

            $lastSync = ag(Config::get("servers.{$entity->via}", []), 'import.lastSync');
            if (null !== $lastSync) {
                $lastSync = makeDate($lastSync);
            }

            $this->logger->notice('SYSTEM: Processing [{backend}] [{title}] {tainted} request.', [
                'backend' => $entity->via,
                'title' => $entity->getName(),
                'event' => ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_EVENT, '??'),
                'tainted' => $entity->isTainted() ? 'tainted' : 'untainted',
                'lastSync' => $lastSync,
            ]);

            $this->mapper->add($entity, [
                Options::IMPORT_METADATA_ONLY => (bool)ag($options, Options::IMPORT_METADATA_ONLY),
                Options::STATE_UPDATE_EVENT => $fn,
                'after' => $lastSync,
            ]);
        }

        foreach ($queued as $item) {
            queuePush($item);
        }

        $operations = $this->mapper->commit();

        $a = [
            [
                'Type' => ucfirst(iState::TYPE_MOVIE),
                'Added' => $operations[iState::TYPE_MOVIE]['added'] ?? '-',
                'Updated' => $operations[iState::TYPE_MOVIE]['updated'] ?? '-',
                'Failed' => $operations[iState::TYPE_MOVIE]['failed'] ?? '-',
            ],
            new TableSeparator(),
            [
                'Type' => ucfirst(iState::TYPE_EPISODE),
                'Added' => $operations[iState::TYPE_EPISODE]['added'] ?? '-',
                'Updated' => $operations[iState::TYPE_EPISODE]['updated'] ?? '-',
                'Failed' => $operations[iState::TYPE_EPISODE]['failed'] ?? '-',
            ],
        ];

        if (false === $input->getOption('no-stats')) {
            (new Table($output))
                ->setHeaders(array_keys($a[0]))
                ->setStyle('box')
                ->setRows(array_values($a))
                ->render();
        }

        if (false === $input->getOption('keep') && false === $input->getOption('dry-run')) {
            $this->cache->delete('requests');
        }

        return self::SUCCESS;
    }

    /**
     * List Items.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param array $requests
     * @return int
     */
    private function listItems(InputInterface $input, OutputInterface $output, array $requests): int
    {
        $list = [];

        $mode = $input->getOption('output');

        foreach ($requests as $request) {
            $opts = ag($request, 'options', []);
            $item = ag($request, 'entity');

            assert($item instanceof iState);

            if ('table' === $mode) {
                $builder = [
                    'queued' => makeDate(ag($item->getExtra($item->via), iState::COLUMN_EXTRA_DATE))->format(
                        'Y-m-d H:i:s T'
                    ),
                    'via' => $item->via,
                    'title' => $item->getName(),
                    'played' => $item->isWatched() ? 'Yes' : 'No',
                    'tainted' => $item->isTainted() ? 'Yes' : 'No',
                    'event' => ag($item->getExtra($item->via), iState::COLUMN_EXTRA_EVENT, '??'),
                ];
            } else {
                $builder = [
                    ...$item->getAll(),
                    'tainted' => $item->isTainted(),
                    'options' => $opts
                ];
            }

            $list[] = $builder;
        }

        $this->displayContent($list, $output, $mode);

        return self::SUCCESS;
    }
}
