<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Backends\Common\ClientInterface;
use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Database\DatabaseInterface;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\StreamLogHandler;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\Stream;
use App\Libs\UserContext;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Class ExportCommand
 *
 * Command for exporting play state to backends.
 *
 * @package App\Console\Commands\State
 */
#[Cli(command: self::ROUTE)]
class ExportCommand extends Command
{
    public const string ROUTE = 'state:export';

    public const string TASK_NAME = 'export';

    /**
     * Class Constructor.
     *
     * @param DirectMapper $mapper The instance of the DirectMapper class.
     * @param QueueRequests $queue The instance of the QueueRequests class.
     * @param iLogger $logger The instance of the iLogger class.
     */
    public function __construct(
        #[Inject(DirectMapper::class)]
        private iImport $mapper,
        private QueueRequests $queue,
        private iLogger $logger,
        private LogSuppressor $suppressor,
    ) {
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
            ->setDescription('Export play state to backends.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full export. Ignore last export date.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit changes to backends.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Export to this specific user. Default all users.')
            ->addOption(
                'select-backend',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Select backend.'
            )
            ->addOption('exclude', null, InputOption::VALUE_NONE, 'Inverse --select-backend logic.')
            ->addOption('ignore-date', 'i', InputOption::VALUE_NONE, 'Ignore date comparison.')
            ->addOption('logfile', null, InputOption::VALUE_REQUIRED, 'Save console output to file.')
            ->setHelp(
                r(
                    <<<HELP

                    This command export your <notice>current</notice> play state to backends.
                    This command provide powerful options. do read them.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to force export play state to a backend?</question>

                    You have to use the following flags [<flag>-f</flag>, <flag>-f</flag>] and [<flag>-i</flag>, <flag>-i</flag>]. For example,

                    {cmd} <cmd>{route}</cmd> <flag>-fi</flag> <flag>-s</flag> <value>backend_name</value>

                    <question># how to see what will be changed without committing them?</question>

                    You have to use the [<flag>--dry-run</flag>]. For example,

                    {cmd} <cmd>{route}</cmd> <flag>-v --dry-run -s</flag> <value>backend_name</value>

                    <question># Ignoring [backend_name] [item_title]. [Movie|Episode] Is not imported yet.</question>

                    This error indicates that the item is not imported possibly because the backend in the question is
                    set as metadata only, and thus it will not import the item unless it's already exists in the
                    database. if you are sure it's already exists on the other backend. Then this likely means
                    that you have mismatched IDs. Run,

                    {cmd} <cmd>db:list</cmd> <flag>--output</flag> <value>yaml</value> <flag>--title</flag> <value>"showName"</value>

                    This command will show you which items are linked to given title,
                    you can replace  <flag>--title</flag> <value>"showName"</value> with <flag>--parent</flag> <value>tvdb://id</value> to check specific show id

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,

                    ]
                )
            );
    }

    /**
     * Make sure the command is not running in parallel.
     *
     * @param InputInterface $input The input object containing the command data.
     * @param OutputInterface $output The output object for displaying command output.
     *
     * @return int The exit code of the command execution.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output);
    }

    /**
     * Process the command by pulling and comparing status and then pushing.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function process(InputInterface $input, OutputInterface $output): int
    {
        if (null !== ($logfile = $input->getOption('logfile')) && true === ($this->logger instanceof Logger)) {
            $this->logger->setHandlers([
                $this->suppressor->withHandler(new StreamLogHandler(new Stream($logfile, 'w'), $output))
            ]);
        }

        $mapperOpts = $dbOpts = [];

        if ($input->getOption('dry-run')) {
            $mapperOpts[Options::DRY_RUN] = true;
        }

        if ($input->getOption('trace')) {
            $mapperOpts[Options::DEBUG_TRACE] = true;
            $dbOpts[Options::DEBUG_TRACE] = true;
        }

        if (!empty($mapperOpts)) {
            $this->mapper->setOptions(options: $mapperOpts);
        }

        $users = getUsersContext(mapper: $this->mapper, logger: $this->logger, opts: [
            DatabaseInterface::class => $dbOpts,
        ]);

        if (null !== ($user = $input->getOption('user'))) {
            $users = array_filter($users, fn($k) => $k === $user, mode: ARRAY_FILTER_USE_KEY);
            if (empty($users)) {
                $output->writeln(r("<error>User '{user}' not found.</error>", ['user' => $user]));
                return self::FAILURE;
            }
        }

        $selected = $input->getOption('select-backend');
        $isCustom = !empty($selected) && count($selected) > 0;
        $supported = Config::get('supported', []);

        if (true === $input->getOption('dry-run')) {
            $this->logger->notice('Dry run mode. No changes will be committed to backends.');
        }

        foreach ($users as $userContext) {
            try {
                $backends = $export = $push = $entities = [];

                foreach ($userContext->config->getAll() as $backendName => $backend) {
                    $type = strtolower(ag($backend, 'type', 'unknown'));

                    if ($isCustom && $input->getOption('exclude') === $this->in_array($selected, $backendName)) {
                        $this->logger->info("SYSTEM: Ignoring '{user}@{backend}'. As requested.", [
                            'user' => $userContext->name,
                            'backend' => $backendName
                        ]);
                        continue;
                    }

                    if (true !== (bool)ag($backend, 'export.enabled')) {
                        if ($isCustom) {
                            $this->logger->warning(
                                "SYSTEM: Exporting to a export disabled backend '{user}@{backend}' as requested.",
                                [
                                    'user' => $userContext->name,
                                    'backend' => $backendName
                                ]
                            );
                        } else {
                            $this->logger->info("SYSTEM: Ignoring '{user}@{backend}'. Export disabled.", [
                                'user' => $userContext->name,
                                'backend' => $backendName
                            ]);
                            continue;
                        }
                    }

                    if (!isset($supported[$type])) {
                        $this->logger->error("SYSTEM: Ignoring '{user}@{backend}'. Unexpected type '{type}'.", [
                                'type' => $type,
                                'backend' => $backendName,
                                'user' => $userContext->name,
                                'types' => implode(', ', array_keys($supported)),
                            ]
                        );
                        continue;
                    }

                    if (null === ($url = ag($backend, 'url')) || false === isValidURL($url)) {
                        $this->logger->error("SYSTEM: Ignoring '{user}@{backend}'. Invalid URL '{url}'.", [
                            'url' => $url ?? 'None',
                            'backend' => $backendName,
                            'user' => $userContext->name,
                        ]);
                        continue;
                    }

                    $backend['name'] = $backendName;
                    $backends[$backendName] = $backend;
                }

                if (empty($backends)) {
                    $message = $isCustom ? '[-s, --select-backend] flag did not match any backend.' : 'No backends were found.';
                    $this->logger->warning("{message}. For '{user}'.", [
                        'message' => $message,
                        'user' => $userContext->name,
                    ]);
                    continue;
                }

                foreach ($backends as &$backend) {
                    if (null === ($name = ag($backend, 'name'))) {
                        continue;
                    }

                    $opts = ag($backend, 'options', []);

                    if ($input->getOption('ignore-date')) {
                        $opts[Options::IGNORE_DATE] = true;
                    }

                    if ($input->getOption('trace')) {
                        $opts[Options::DEBUG_TRACE] = true;
                    }

                    if ($input->getOption('dry-run')) {
                        $opts[Options::DRY_RUN] = true;
                    }

                    if ($input->getOption('timeout')) {
                        $opts['client']['timeout'] = $input->getOption('timeout');
                    }

                    $backend['options'] = $opts;
                    $backend['class'] = makeBackend(backend: $backend, name: $name, options: [
                        UserContext::class => $userContext,
                    ])->setLogger($this->logger);
                }

                unset($backend);

                if (false === $input->getOption('force-full')) {
                    $minDate = time();

                    foreach ($backends as $backend) {
                        if (null === ($lastSync = ag($backend, 'export.lastSync', null))) {
                            $this->logger->info(
                                "SYSTEM: Using export mode for '{user}@{backend}'. No export last Sync date found.",
                                [
                                    'user' => $userContext->name,
                                    'backend' => ag($backend, 'name'),
                                ]
                            );

                            $export[ag($backend, 'name')] = $backend;
                            continue;
                        }

                        if (null === ag($backend, 'import.lastSync', null)) {
                            $this->logger->warning(
                                "SYSTEM: Using export mode for '{user}@{backend}'. The backend metadata not imported. You need to run import to populate the database.",
                                [
                                    'user' => $userContext->name,
                                    'backend' => ag($backend, 'name'),
                                ]
                            );

                            $export[ag($backend, 'name')] = $backend;
                            continue;
                        }

                        if ($minDate > $lastSync) {
                            $minDate = $lastSync;
                        }
                    }

                    $lastSync = makeDate($minDate);

                    $this->logger->notice("SYSTEM: Loading '{user}' database items that has changed since '{date}'.", [
                        'date' => (string)$lastSync,
                        'user' => $userContext->name,
                    ]);

                    $entities = $userContext->db->getAll($lastSync);

                    if (count($entities) < 1 && count($export) < 1) {
                        $this->logger->notice("SYSTEM: No play state changes detected since '{date}' for '{user}'.", [
                            'date' => (string)$lastSync,
                            'user' => $userContext->name,
                        ]);
                        continue;
                    }

                    if (count($entities) >= 1) {
                        $this->logger->info(
                            "SYSTEM: Checking '{total}' media items for push mode compatibility for '{user}'.",
                            (function () use ($entities, $input, $userContext): array {
                                $context = [
                                    'total' => number_format(count($entities)),
                                    'user' => $userContext->name,
                                ];

                                if ($input->getOption('trace')) {
                                    foreach ($entities as $entity) {
                                        $context['items'][$entity->id] = $entity->getName();
                                    }
                                }

                                return $context;
                            })()
                        );

                        foreach ($entities as $entity) {
                            foreach ($backends as $backend) {
                                $name = ag($backend, 'name');

                                if (null === ($lastSync = ag($backend, 'export.lastSync', null))) {
                                    continue;
                                }

                                if (false === ag_exists($entity->getMetadata(), $name)) {
                                    $addedDate = ag(
                                        $entity->getMetadata($entity->via),
                                        iState::COLUMN_META_DATA_ADDED_AT
                                    );
                                    $extraMargin = (int)Config::get('export.not_found');

                                    if (null === $addedDate || false === ctype_digit($addedDate)) {
                                        $this->logger->info(
                                            "SYSTEM: Ignoring '{item.id}: {item.title}' for '{user}@{backend}' received invalid added_at '{added_at}' date.",
                                            [
                                                'user' => $userContext->name,
                                                'type' => get_debug_type($addedDate),
                                                'backend' => $name,
                                                'item' => [
                                                    'id' => $entity->id,
                                                    'title' => $entity->getName(),
                                                ],
                                                'added_at' => makeDate($addedDate),
                                                'data' => $input->getOption('trace') ? $entity->getAll() : [],
                                            ]
                                        );
                                        continue;
                                    }

                                    if ($lastSync > ($addedDate + $extraMargin)) {
                                        $this->logger->info(
                                            "SYSTEM: Ignoring '{item.id}: {item.title}' for '{user}@{backend}' waiting period for metadata expired.",
                                            [
                                                'user' => $userContext->name,
                                                'backend' => $name,
                                                'item' => [
                                                    'id' => $entity->id,
                                                    'title' => $entity->getName(),
                                                ],
                                                'wait_period' => [
                                                    'added_at' => makeDate($addedDate),
                                                    'extra_margin' => $extraMargin,
                                                    'last_sync_at' => makeDate($lastSync),
                                                    'diff' => $lastSync - ($addedDate + $extraMargin),
                                                ],
                                            ]
                                        );

                                        continue;
                                    }

                                    if (true === ag_exists($push, $name)) {
                                        unset($push[$name]);
                                    }

                                    $this->logger->info(
                                        "SYSTEM: Using export mode for '{user}@{backend}'. Backend local database entries did not have metadata for '{item.id}: {item.title}'.",
                                        [
                                            'user' => $userContext->name,
                                            'backend' => $name,
                                            'item' => [
                                                'id' => $entity->id,
                                                'title' => $entity->getName(),
                                            ],
                                        ]
                                    );

                                    $export[$name] = $backend;
                                }

                                if (false === ag_exists($export, $name)) {
                                    $push[ag($backend, 'name')] = $backend;
                                }
                            }
                        }
                    }
                } else {
                    $export = $backends;
                    $this->logger->notice(
                        "SYSTEM: Not possible to use push mode when '-f, --force-full' flag is used."
                    );
                }

                $this->logger->notice(
                    "SYSTEM: '{user}' Using push mode for '{push.total}' backends and export mode for '{export.total}' backends.",
                    [
                        'user' => $userContext->name,
                        'push' => [
                            'total' => count($push),
                            'list' => implode(', ', array_keys($push)),
                        ],
                        'export' => [
                            'total' => count($export),
                            'list' => implode(', ', array_keys($export)),
                        ],

                    ]
                );

                if (count($push) >= 1) {
                    $this->push($userContext, $push, $entities);
                }

                if (count($export) >= 1) {
                    $this->export($userContext, $export, $input->getOption('dry-run'), $input->getOption('force-full'));
                }

                $total = count($this->queue->getQueue());

                if ($total >= 1) {
                    $this->logger->notice("SYSTEM: Sending '{total}' change play state requests for '{user}'.", [
                        'total' => $total,
                        'user' => $userContext->name,
                    ]);

                    foreach ($this->queue->getQueue() as $response) {
                        $context = ag($response->getInfo('user_data'), 'context', []);
                        $context['user'] = $userContext->name;

                        try {
                            if (200 !== ($statusCode = $response->getStatusCode())) {
                                $this->logger->error(
                                    "Request to change '{user}@{backend}' '{item.title}' play state returned with unexpected '{status_code}' status code.",
                                    [
                                        'status_code' => $statusCode,
                                        ...$context,
                                    ],
                                );
                                continue;
                            }

                            $this->logger->notice(
                                "Marked '{user}@{backend}' '{item.title}' as '{play_state}'.",
                                $context
                            );
                        } catch (Throwable $e) {
                            $this->logger->error(
                                message: "Exception '{error.kind}' was thrown unhandled during '{user}@{backend}' request to change play state of {item.type} '{item.title}'. '{error.message}' at '{error.file}:{error.line}'.",
                                context: [
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

                    $this->logger->notice("SYSTEM: Sent '{total}' change play state requests for '{user}'.", [
                        'total' => $total,
                        'user' => $userContext->name,
                    ]);
                } else {
                    $this->logger->notice("SYSTEM: No play state changes detected '{user}'.", [
                        'user' => $userContext->name,
                    ]);
                }

                if (true === $input->getOption('dry-run')) {
                    continue;
                }

                foreach ($backends as $backend) {
                    if (null === ($name = ag($backend, 'name'))) {
                        continue;
                    }

                    if (false === (bool)Message::get("{$name}.has_errors", false)) {
                        $userContext->config->set("{$name}.export.lastSync", time());
                    } else {
                        $this->logger->warning(
                            "SYSTEM: Not updating '{user}@{backend}' export last sync date. There was errors recorded during the operation.",
                            [
                                'backend' => $name,
                                'user' => $userContext->name,
                            ]
                        );
                    }
                }

                $userContext->config->persist();
            } catch (Throwable $e) {
                $this->logger->error(
                    "SYSTEM: Unhandled exception '{error.kind}' was thrown during '{user}' export operation. '{error.message}' at '{error.file}:{error.line}'.",
                    [
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'user' => $userContext->name,
                    ]
                );
            } finally {
                $this->queue->reset();
                $userContext->mapper->reset();
            }
        }

        return self::SUCCESS;
    }

    /**
     * Push entities to backends if applicable.
     *
     * @param UserContext $userContext The user context object.
     * @param array $backends An array of backends.
     * @param array $entities An array of entities to be pushed.
     *
     * @return int The success status code.
     */
    protected function push(UserContext $userContext, array $backends, array $entities): int
    {
        $this->logger->notice("Push mode started for '{user}: {backends}'.", [
            'user' => $userContext->name,
            'backends' => implode(', ', array_keys($backends)),
        ]);

        foreach ($backends as $backend) {
            assert($backend['class'] instanceof ClientInterface, 'Backend class must implement ClientInterface.');
            $backend['class']->push(
                entities: $entities,
                queue: $this->queue,
                after: makeDate(ag($backend, 'export.lastSync'))
            );
        }

        $this->logger->notice("Push mode ended for '{user}: {backends}'.", [
            'user' => $userContext->name,
            'backends' => implode(', ', array_keys($backends)),
        ]);

        return self::SUCCESS;
    }

    /**
     * Fallback to export mode if push mode is not supported for the backend.
     *
     * @param UserContext $userContext The user context object.
     * @param array $backends An array of backends to export data to.
     * @param bool $inDryMode Whether the command is in dry mode.
     * @param bool $isFull Whether the command is in full mode.
     */
    protected function export(
        UserContext $userContext,
        array $backends,
        bool $inDryMode,
        bool $isFull,
    ): void {
        $this->logger->notice("Export mode started for '{user}@{backends}'.", [
            'user' => $userContext->name,
            'backends' => implode(', ', array_keys($backends)),
        ]);

        $this->logger->notice("SYSTEM: Preloading '{user}' - '{mapper}' data. Memory: {memory.now}", [
            'user' => $userContext->name,
            'mapper' => afterLast($userContext->mapper::class, '\\'),
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        $userContext->mapper->reset()->loadData();

        $this->logger->notice("SYSTEM: Preloading '{mapper}' data is complete. Memory: {memory.now}", [
            'mapper' => afterLast($userContext->mapper::class, '\\'),
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        $requests = [];

        foreach ($backends as $backend) {
            if (null === ($name = ag($backend, 'name'))) {
                continue;
            }

            $after = true === $isFull ? null : ag($backend, 'export.lastSync', null);

            if (null === $after) {
                $this->logger->notice("SYSTEM: Exporting play state to '{user}@{backend}'.", [
                    'backend' => $name,
                    'user' => $userContext->name,
                ]);
            } else {
                $after = makeDate($after);
                $this->logger->notice("SYSTEM: Exporting play state changes since '{date}' to '{user}@{backend}'.", [
                    'backend' => $name,
                    'user' => $userContext->name,
                    'date' => (string)$after,
                ]);
            }

            assert($backend['class'] instanceof ClientInterface, 'Backend class must implement ClientInterface.');
            array_push($requests, ...$backend['class']->export($userContext->mapper, $this->queue, $after));

            if (false === $inDryMode) {
                if (true === (bool)Message::get("{$name}.has_errors")) {
                    $this->logger->warning(
                        "SYSTEM: Not updating '{user}@{backend}' export last sync date. There was errors recorded during the operation.",
                        [
                            'backend' => $name,
                            'user' => $userContext->name,
                        ]
                    );
                } else {
                    $userContext->config->set("{$name}.export.lastSync", time());
                }
            }
        }

        $start = makeDate();
        $this->logger->notice("SYSTEM: Sending '{total}' play state comparison requests for '{user}'.", [
            'total' => count($requests),
            'user' => $userContext->name,
        ]);

        foreach ($requests as $response) {
            $requestData = $response->getInfo('user_data');
            try {
                $requestData['ok']($response);
            } catch (Throwable $e) {
                $requestData['error']($e);
            }
        }

        $end = makeDate();
        $this->logger->notice(
            "SYSTEM: Completed '{total}' play state comparison requests for '{user}' in '{time.duration}'s. Parsed '{responses.size}' of data.",
            [
                'user' => $userContext->name,
                'total' => count($requests),
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

        $this->logger->notice("Export mode ended for '{user}: {backends}'.", [
            'user' => $userContext->name,
            'backends' => implode(', ', array_keys($backends)),
        ]);
    }

    private function in_array(array $list, string $search): bool
    {
        return array_any($list, fn($item) => str_starts_with($search, $item));
    }
}
