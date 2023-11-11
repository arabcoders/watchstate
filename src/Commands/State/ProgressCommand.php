<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\Routable;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
class ProgressCommand extends Command
{
    public const ROUTE = 'state:progress';

    public const TASK_NAME = 'progress';

    public function __construct(
        private iLogger $logger,
        private iCache $cache,
        private iDB $db,
        private QueueRequests $queue
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Push queued watch progress.')
            ->addOption('keep', 'k', InputOption::VALUE_NONE, 'Do not expunge queue after run is complete.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit changes to backends.')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List queued items.')
            ->addOption('ignore-date', null, InputOption::VALUE_NONE, 'Ignore date comparison.')
            ->setHelp(
                r(
                    <<<HELP
                    =================================================================================
                    <error>***WARNING THIS COMMAND IS EXPERIMENTAL AND MAY NOT WORK AS EXPECTED***</error>
                    <notice>THIS COMMAND ONLY WORKS CORRECTLY FOR PLEX & EMBY AT THE MOMENT.</notice>
                    =================================================================================
                    Jellyfin API has a bug which I cannot do anything about.

                    This command push <notice>user</notice> watch progress to export enabled backends.
                    You should not run this manually and instead rely on scheduled task to run this command.

                    This command require the <notice>metadata</notice> to be already saved in database.
                    If no metadata available for a backend, then watch progress update won't be sent to that backend.
                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                    ]
                )
            );
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
        if (!$this->cache->has('progress')) {
            $this->logger->info('No watch progress items in the queue.');
            return self::SUCCESS;
        }

        $entities = $items = [];

        foreach ($this->cache->get('progress', []) as $item) {
            /** @var iState $item */
            $items[] = Container::get(iState::class)::fromArray($item->getAll());
        }

        if (!empty($items)) {
            foreach ($items as $queueItem) {
                $dbItem = $this->db->get($queueItem);
                if ($dbItem->isWatched()) {
                    continue;
                }
                $dbItem = $dbItem->apply($queueItem);

                if (!$dbItem->hasPlayProgress()) {
                    continue;
                }
                $entities[$dbItem->id] = $dbItem;
            }
        }

        $items = null;

        if (empty($entities)) {
            $this->cache->delete('progress');
            $this->logger->debug('No watch progress items in the queue.');
            return self::SUCCESS;
        }

        if ($input->getOption('list')) {
            return $this->listItems($input, $output, $entities);
        }

        $list = [];
        $supported = Config::get('supported', []);

        foreach ((array)Config::get('servers', []) as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if (true !== (bool)ag($backend, 'export.enabled')) {
                $this->logger->info('SYSTEM: Export to [{backend}] is disabled by user.', [
                    'backend' => $backendName,
                ]);

                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->error('SYSTEM: [{backend}] Invalid type.', [
                    'backend' => $backendName,
                    'condition' => [
                        'expected' => implode(', ', array_keys($supported)),
                        'given' => $type,
                    ],
                ]);
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === isValidURL($url)) {
                $this->logger->error('SYSTEM: [{backend}] Invalid url.', [
                    'backend' => $backendName,
                    'url' => $url ?? 'None',
                ]);
                continue;
            }

            $backend['name'] = $backendName;
            $list[$backendName] = $backend;
        }

        if (empty($list)) {
            $this->logger->warning('SYSTEM: There are no backends with export enabled.');
            return self::FAILURE;
        }

        foreach ($list as $name => &$backend) {
            $opts = ag($backend, 'options', []);

            if ($input->getOption('ignore-date')) {
                $opts[Options::IGNORE_DATE] = true;
            }

            if ($input->getOption('dry-run')) {
                $opts[Options::DRY_RUN] = true;
            }

            if ($input->getOption('trace')) {
                $opts[Options::DEBUG_TRACE] = true;
            }

            $backend['options'] = $opts;
            $backend['class'] = $this->getBackend(name: $name, config: $backend);

            $backend['class']->progress(entities: $entities, queue: $this->queue);
        }

        unset($backend);

        $total = count($this->queue);

        if ($total >= 1) {
            $start = makeDate();
            $this->logger->notice('SYSTEM: Sending [{total}] progress update requests.', [
                'total' => $total,
                'time' => [
                    'start' => $start,
                ],
            ]);

            foreach ($this->queue->getQueue() as $response) {
                $context = ag($response->getInfo('user_data'), 'context', []);

                try {
                    if (!in_array($response->getStatusCode(), [200, 204])) {
                        $this->logger->error(
                            'SYSTEM: Request to change [{backend}] [{item.title}] watch progress returned with unexpected [{status_code}] status code.',
                            [
                                'status_code' => $response->getStatusCode(),
                                ...$context
                            ]
                        );
                        continue;
                    }

                    $this->logger->notice('SYSTEM: Updated [{backend}] [{item.title}] watch progress.', [
                        ...$context,
                        'status_code' => $response->getStatusCode(),
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'SYSTEM: Unhandled exception thrown during request to change watch progress of [{backend}] {item.type} [{item.title}].',
                        [
                            ...$context,
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                                'trace' => $e->getTrace(),
                            ],
                        ]
                    );
                }
            }

            $end = makeDate();

            $this->logger->notice('SYSTEM: Sent [{total}] watch progress requests.', [
                'total' => $total,
                'time' => [
                    'start' => $start,
                    'end' => $end,
                    'duration' => $end->getTimestamp() - $start->getTimestamp(),
                ],
            ]);

            $this->logger->notice(sprintf('Using WatchState Version - \'%s\'.', getAppVersion()));
        } else {
            $this->logger->notice('SYSTEM: No play state changes detected.');
        }

        if (false === $input->getOption('keep') && false === $input->getOption('dry-run')) {
            $this->cache->delete('progress');
        }

        return self::SUCCESS;
    }

    /**
     * List Items.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param array<iState> $items
     * @return int
     */
    private function listItems(InputInterface $input, OutputInterface $output, array $items): int
    {
        $list = [];

        $mode = $input->getOption('output');

        foreach ($items as $item) {
            if ('table' === $mode) {
                $builder = [
                    'queued' => makeDate(ag($item->getExtra($item->via), iState::COLUMN_EXTRA_DATE))->format(
                        'Y-m-d H:i:s T'
                    ),
                    'via' => $item->via,
                    'title' => $item->getName(),
                    'played' => $item->isWatched() ? 'Yes' : 'No',
                    'play_time' => $this->formatPlayProgress($item->getPlayProgress()),
                    'tainted' => $item->isTainted() ? 'Yes' : 'No',
                    'event' => ag($item->getExtra($item->via), iState::COLUMN_EXTRA_EVENT, '??'),
                ];
            } else {
                $builder = [
                    ...$item->getAll(),
                    'tainted' => $item->isTainted(),
                ];
            }

            $list[] = $builder;
        }

        $this->displayContent($list, $output, $mode);

        return self::SUCCESS;
    }

    public function formatPlayProgress(int $milliseconds): string
    {
        $seconds = floor($milliseconds / 1000);
        $minutes = floor($seconds / 60);
        $hours = floor($minutes / 60);
        $seconds = $seconds % 60;
        $minutes = $minutes % 60;

        $format = '%02u:%02u:%02u';
        return sprintf($format, $hours, $minutes, $seconds);
    }
}
