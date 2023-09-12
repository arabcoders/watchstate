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
class PushCommand extends Command
{
    public const ROUTE = 'state:push';

    public const TASK_NAME = 'push';

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
            ->setDescription('Push webhook queued events.')
            ->addOption('keep', 'k', InputOption::VALUE_NONE, 'Do not expunge queue after run is complete.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit changes to backends.')
            ->addOption('ignore-date', null, InputOption::VALUE_NONE, 'Ignore date comparison.')
            ->setHelp(
                r(
                    <<<HELP

                    This command push <notice>webhook</notice> updated play state to export enabled backends.
                    You should not run this manually and instead rely on scheduled task to run this command.

                    This command require the <notice>metadata</notice> to be already saved in database.
                    If no metadata available for a backend, then the item will be ignored for that backend.

                    If the item was ignored during <cmd>{route}</cmd> run, it will be picked up later by next <cmd>{export_route}</cmd> run.

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'export_route' => ExportCommand::ROUTE,
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
        return $this->single(fn(): int => $this->process($input), $output);
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function process(InputInterface $input): int
    {
        if (!$this->cache->has('queue')) {
            $this->logger->info('No items in the queue.');
            return self::SUCCESS;
        }

        $entities = $items = [];

        foreach ($this->cache->get('queue', []) as $item) {
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
            $this->logger->debug('No items in the queue.');
            return self::SUCCESS;
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

            if (null === ($url = ag($backend, 'url')) || false === filter_var($url, FILTER_VALIDATE_URL)) {
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

            $backend['class']->push(entities: $entities, queue: $this->queue);
        }

        unset($backend);

        $total = count($this->queue);

        if ($total >= 1) {
            $start = makeDate();
            $this->logger->notice('SYSTEM: Sending [{total}] change play state requests.', [
                'total' => $total,
                'time' => [
                    'start' => $start,
                ],
            ]);

            foreach ($this->queue->getQueue() as $response) {
                $context = ag($response->getInfo('user_data'), 'context', []);

                try {
                    if (200 !== $response->getStatusCode()) {
                        $this->logger->error(
                            'SYSTEM: Request to change [{backend}] [{item.title}] play state returned with unexpected [{status_code}] status code.',
                            $context
                        );
                        continue;
                    }

                    $this->logger->notice('SYSTEM: Marked [{backend}] [{item.title}] as [{play_state}].', $context);
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'SYSTEM: Unhandled exception thrown during request to change play state of [{backend}] {item.type} [{item.title}].',
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

            $this->logger->notice('SYSTEM: Sent [{total}] change play state requests.', [
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
            $this->cache->delete('queue');
        }

        return self::SUCCESS;
    }
}
