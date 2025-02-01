<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Backends\Common\Cache as BackendCache;
use App\Backends\Common\ClientInterface as iClient;
use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\StreamLogHandler;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\ExtendedImportInterface as iEImport;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\Stream;
use App\Libs\UserContext;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Class ExportCommand
 *
 * Command for exporting play state to backends.
 *
 * @package App\Console\Commands\State
 */
#[Cli(command: self::ROUTE)]
class SyncCommand extends Command
{
    public const string ROUTE = 'state:sync';

    public const string TASK_NAME = 'sync';

    /**
     * Class Constructor.
     *
     * @param MemoryMapper $mapper The instance of the DirectMapper class.
     * @param QueueRequests $queue The instance of the QueueRequests class.
     * @param iLogger $logger The instance of the iLogger class.
     */
    public function __construct(
        #[Inject(MemoryMapper::class)]
        private iEImport $mapper,
        private readonly QueueRequests $queue,
        private readonly iLogger $logger,
        private readonly LogSuppressor $suppressor,
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');
        $this->mapper->setLogger(new NullLogger());
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Sync All users play state to backends.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full export. Ignore last export date.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit changes to backends.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption(
                'select-backend',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Select backend.'
            )
            ->addOption('exclude', null, InputOption::VALUE_NONE, 'Inverse --select-backend logic.')
            ->addOption('ignore-date', 'i', InputOption::VALUE_NONE, 'Ignore date comparison.')
            ->addOption('logfile', null, InputOption::VALUE_REQUIRED, 'Save console output to file.')
            ->addOption(
                'always-update-metadata',
                null,
                InputOption::VALUE_NONE,
                'Mapper option. Always update the locally stored metadata from backend.'
            )
            ->addOption('include-main-user', 'M', InputOption::VALUE_NONE, 'Include main user in sync.');
    }

    /**
     * Make sure the command is not running in parallel.
     *
     * @param iInput $input The input object containing the command data.
     * @param iOutput $output The output object for displaying command output.
     *
     * @return int The exit code of the command execution.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output);
    }

    /**
     * Process the command by pulling and comparing status and then pushing.
     *
     * @param iInput $input
     * @param iOutput $output
     * @return int
     */
    protected function process(iInput $input, iOutput $output): int
    {
        if (null !== ($logfile = $input->getOption('logfile')) && true === ($this->logger instanceof Logger)) {
            $this->logger->setHandlers([
                $this->suppressor->withHandler(new StreamLogHandler(new Stream($logfile, 'w'), $output))
            ]);
        }

        $selected = $input->getOption('select-backend');
        $isCustom = !empty($selected) && count($selected) > 0;
        $supported = Config::get('supported', []);

        if (true === $input->getOption('dry-run')) {
            $this->logger->notice('Dry run mode. No changes will be committed to backends.');
        }

        $mapperOpts = [];

        if ($input->getOption('dry-run')) {
            $this->logger->notice('Dry run mode. No changes will be committed.');
            $mapperOpts[Options::DRY_RUN] = true;
        }

        if ($input->getOption('trace')) {
            $mapperOpts[Options::DEBUG_TRACE] = true;
        }

        if ($input->getOption('always-update-metadata')) {
            $mapperOpts[Options::MAPPER_ALWAYS_UPDATE_META] = true;
        }

        if (!empty($mapperOpts)) {
            $this->mapper = $this->mapper->withOptions($mapperOpts);
        }

        $userOpt = [
            'no_main_user' => !$input->getOption('include-main-user'),
        ];

        $usersContext = getUsersContext($this->mapper, $this->logger, $userOpt);

        if (empty($usersContext)) {
            $this->logger->warning('No users were found. Please create sub users via the backends:create command.');
            return self::SUCCESS;
        }

        foreach ($usersContext as $userContext) {
            try {
                $this->queue->reset();

                $list = [];

                foreach ($userContext->config->getAll() as $backendName => $backend) {
                    $type = strtolower(ag($backend, 'type', 'unknown'));

                    if ($isCustom && $input->getOption('exclude') === $this->in_array($selected, $backendName)) {
                        $this->logger->info("SYSTEM: Ignoring '{user}@{backend}' as requested.", [
                            'user' => $userContext->name,
                            'backend' => $backendName
                        ]);
                        continue;
                    }

                    if (true !== (bool)ag($backend, 'import.enabled')) {
                        $this->logger->info("SYSTEM: Ignoring '{user}@{backend}'. Import disabled.", [
                            'user' => $userContext->name,
                            'backend' => $backendName
                        ]);
                        continue;
                    }

                    if (!isset($supported[$type])) {
                        $this->logger->error("SYSTEM: Ignoring '{user}@{backend}'. Unexpected type '{type}'.", [
                            'user' => $userContext->name,
                            'type' => $type,
                            'backend' => $backendName,
                        ]);
                        continue;
                    }

                    if (null === ($url = ag($backend, 'url')) || false === isValidURL($url)) {
                        $this->logger->error("SYSTEM: Ignoring '{user}@{backend}'. Invalid URL '{url}'.", [
                            'user' => $userContext->name,
                            'url' => $url ?? 'None',
                            'backend' => $backendName,
                        ]);
                        continue;
                    }

                    $opts = ag($backend, 'options', []);

                    if ($input->getOption('ignore-date')) {
                        $opts = ag_set($opts, Options::IGNORE_DATE, true);
                    }

                    if ($input->getOption('trace')) {
                        $opts = ag_set($opts, Options::DEBUG_TRACE, true);
                    }

                    if ($input->getOption('dry-run')) {
                        $opts = ag_set($opts, Options::DRY_RUN, true);
                    }

                    if ($input->getOption('timeout')) {
                        $opts = ag_set($opts, 'client.timeout', $input->getOption('timeout'));
                    }

                    $backend['options'] = $opts;
                    $backend['name'] = $backendName;
                    $backend['class'] = makeBackend($backend, $backendName, [
                        BackendCache::class => Container::get(BackendCache::class)->with(
                            adapter: $userContext->cache
                        )
                    ])->setLogger($this->logger);

                    $list[$backendName] = $backend;
                }

                if (empty($list)) {
                    $this->logger->warning(
                        $isCustom ? '[-s, --select-backend] flag did not match any backend.' : 'No backends were found.'
                    );
                    continue;
                }

                $start = makeDate();
                $this->logger->notice("SYSTEM: Syncing user '{user}: {list}'.", [
                    'user' => $userContext->name,
                    'list' => join(', ', array_keys($list)),
                    'started' => $start,
                ]);

                $this->handleImport($userContext, $list, $input->getOption('force-full'));

                $changes = $userContext->mapper->computeChanges(array_keys($list));

                foreach ($changes as $b => $changed) {
                    $count = count($changed);
                    if ($count < 1) {
                        continue;
                    }
                    $this->logger->notice("SYSTEM: '{changes}' changes detected for '{name}@{backend}'.", [
                        'name' => $userContext->name,
                        'backend' => $b,
                        'changes' => $count,
                        'items' => array_map(
                            fn(iState $i) => [
                                'title' => $i->getName(),
                                'state' => $i->isWatched() ? 'played' : 'unplayed',
                                'meta' => $i->isSynced(array_keys($list)),
                            ],
                            $changed
                        )
                    ]);


                    /** @var iClient $client */
                    $client = $list[$b]['class'];
                    $client->updateState($changed, $this->queue);
                }

                $this->handleExport($userContext, $list);

                $end = makeDate();
                $this->logger->notice("SYSTEM: Completed syncing user '{name}: {list}' in '{time.duration}'s", [
                    'name' => $userContext->name,
                    'list' => join(', ', array_keys($list)),
                    'time' => [
                        'start' => $start,
                        'end' => $end,
                        'duration' => $end->getTimestamp() - $start->getTimestamp(),
                    ],
                    'memory' => [
                        'now' => getMemoryUsage(),
                        'peak' => getPeakMemoryUsage(),
                    ],
                ]);

                // -- Release memory.
                if (false === $input->getOption('dry-run')) {
                    $userContext->mapper->commit();

                    foreach ($list as $b => $_) {
                        $userContext->config->set("{$b}.import.lastSync", time());
                        $userContext->config->set("{$b}.export.lastSync", time());
                    }

                    $userContext->config->persist();
                } else {
                    $userContext->mapper->reset();
                }

                $this->logger->info("SYSTEM: Memory usage after reset '{memory}'.", [
                    'memory' => getMemoryUsage(),
                ]);
            } catch (Throwable $e) {
                $this->logger->error(
                    "SYSTEM: Exception '{error.kind}' was thrown unhandled during '{name}' sync. '{error.message}' at '{error.file}:{error.line}'.",
                    [
                        'name' => $userContext->name,
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                        ],
                    ]
                );
            }
        }

        return self::SUCCESS;
    }

    protected function handleImport(UserContext $userContext, array $backends, bool $isFull): void
    {
        /** @var array<array-key,ResponseInterface> $queue */
        $queue = [];

        $this->logger->info("SYSTEM: Loading '{user}' mapper data. Current memory usage '{memory}'.", [
            'user' => $userContext->name,
            'memory' => getMemoryUsage(),
        ]);

        $userContext->mapper->loadData();

        $this->logger->info("SYSTEM: loading of '{user}' mapper data '{count}' completed using '{memory}' of memory.", [
            'user' => $userContext->name,
            'count' => $userContext->mapper->count(),
            'memory' => getMemoryUsage(),
        ]);

        foreach ($backends as $backend) {
            /** @var iClient $client */
            $client = ag($backend, 'class');
            assert($client instanceof iClient);

            $backendContext = $client->getContext();

            if (true === $isFull || ag($backendContext->options, Options::FORCE_FULL)) {
                $after = null;
            } else {
                $after = $userContext->config->get($backendContext->backendName . '.import.lastSync');
            }

            if (null !== $after) {
                $after = makeDate($after);
            }

            array_push($queue, ...$client->pull(mapper: $userContext->mapper, after: $after));
        }

        $start = makeDate();
        $this->logger->notice("SYSTEM: Waiting on '{total}' requests for '{name}: {backends}' data.", [
            'name' => $userContext->name,
            'backends' => join(', ', array_keys($backends)),
            'total' => number_format(count($queue)),
            'time' => [
                'start' => $start,
            ],
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        foreach ($queue as $_key => $response) {
            $requestData = $response->getInfo('user_data');

            try {
                $requestData['ok']($response);
            } catch (Throwable $e) {
                $requestData['error']($e);
            }

            $queue[$_key] = null;

            gc_collect_cycles();
        }

        $end = makeDate();
        $this->logger->notice(
            "SYSTEM: Finished waiting on '{total}' requests in '{time.duration}'s for importing '{name}: {backends}' data. Parsed '{responses.size}' of data.",
            [
                'name' => $userContext->name,
                'backends' => join(', ', array_keys($backends)),
                'total' => number_format(count($queue)),
                'time' => [
                    'start' => $start,
                    'end' => $end,
                    'duration' => $end->getTimestamp() - $start->getTimestamp(),
                ],
                'memory' => [
                    'now' => getMemoryUsage(),
                    'peak' => getPeakMemoryUsage(),
                ],
                'responses' => [
                    'size' => fsize((int)Message::get('response.size', 0)),
                ],
            ]
        );

        Message::add('response.size', 0);
    }

    protected function handleExport(UserContext $userContext, array $backends): void
    {
        $total = count($this->queue->getQueue());
        if ($total < 1) {
            $this->logger->notice("SYSTEM: No play state changes detected for '{name}: {backends}'.", [
                'name' => $userContext->name,
                'backends' => join(', ', array_keys($backends))
            ]);
            return;
        }

        $this->logger->notice("SYSTEM: Sending '{total}' change play state requests for '{name}: {backends}'.", [
            'name' => $userContext->name,
            'total' => $total,
            'backends' => join(', ', array_keys($backends)),
        ]);

        foreach ($this->queue->getQueue() as $response) {
            $context = ag($response->getInfo('user_data'), 'context', []);

            try {
                if (200 !== ($statusCode = $response->getStatusCode())) {
                    $this->logger->error(
                        "Request to change '{name}@{backend}' '{item.title}' play state returned with unexpected '{status_code}' status code.",
                        [
                            'name' => $userContext->name,
                            'status_code' => $statusCode,
                            ...$context,
                        ],
                    );
                    continue;
                }

                $this->logger->notice("Marked '{name}@{backend}' '{item.title}' as '{play_state}'.", [
                    'name' => $userContext->name,
                    ...$context
                ]);
            } catch (Throwable $e) {
                $this->logger->error(
                    message: "Exception '{error.kind}' was thrown unhandled during '{name}@{backend}' request to change play state of {item.type} '{item.title}'. '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'name' => $userContext->name,
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        ...$context,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                        ],
                    ]
                );
            }
        }

        $this->logger->notice("SYSTEM: Sent '{total}' change play state requests for '{name}: {backends}'.", [
            'name' => $userContext->name,
            'total' => $total,
            'backends' => join(', ', array_keys($backends)),
        ]);
    }

    private function in_array(array $haystack, string $needle): bool
    {
        return array_any($haystack, fn($item) => str_starts_with($item, $needle));
    }
}
