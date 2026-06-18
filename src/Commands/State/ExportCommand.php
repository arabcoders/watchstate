<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Backends\Common\ClientInterface;
use App\Backends\Common\Request;
use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Database\DatabaseInterface;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\RetryableHttpClient;
use App\Libs\Extends\StreamLogHandler;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\Stream;
use App\Libs\UserContext;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
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
        private readonly iImport $mapper,
        private readonly QueueRequests $queue,
        private readonly iLogger $logger,
        private readonly LogSuppressor $suppressor,
        #[Inject(RetryableHttpClient::class)]
        private readonly iHttp $http,
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
        $this
            ->setName(self::ROUTE)
            ->setDescription('Export play state to backends.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full export. Ignore last export date.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit changes to backends.')
            ->addOption(
                'sync-requests',
                null,
                InputOption::VALUE_NONE,
                'Send one request at a time instead of all at once. note: Slower but more reliable.',
            )
            ->addOption(
                'async-requests',
                null,
                InputOption::VALUE_NONE,
                'Send all requests at once. note: Faster but less reliable. Default.',
            )
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user. Default is all users.')
            ->addOption(
                'select-backend',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Select backend.',
            )
            ->addOption(
                'exclude',
                'e',
                InputOption::VALUE_NONE,
                'Inverse --select-backend logic. Exclude selected backends.',
            )
            ->addOption('ignore-date', 'i', InputOption::VALUE_NONE, 'Ignore date comparison.')
            ->addOption('logfile', null, InputOption::VALUE_REQUIRED, 'Save console output to file.');
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
        return $this->single(fn(): int => $this->process($input, $output), $output, [
            iLogger::class => $this->logger,
            Level::class => Level::Error,
        ]);
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
        if (null !== ($logfile = $input->getOption('logfile')) && true === $this->logger instanceof Logger) {
            $this->logger->setHandlers([
                $this->suppressor->withHandler(new StreamLogHandler(new Stream($logfile, 'w'), $output)),
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

        try {
            $users = array_map(
                fn(string $user): UserContext => get_user_context($user, $this->mapper, $this->logger),
                select_users($input->getOption('user')),
            );
        } catch (RuntimeException $e) {
            $this->logger->error($e->getMessage(), exception_log($e));

            return self::FAILURE;
        }

        foreach ($users as $userContext) {
            if ([] === $dbOpts) {
                continue;
            }

            $userContext->db->setOptions($dbOpts);
        }

        if (false === ($syncRequests = $input->getOption('sync-requests'))) {
            $syncRequests = (bool) Config::get('http.default.sync_requests', false);
        }

        if (true === $input->getOption('async-requests')) {
            $syncRequests = false;
        }

        $selected = $input->getOption('select-backend');
        $isCustom = !empty($selected) && count($selected) > 0;
        $supported = Config::get('supported', []);

        if (true === $input->getOption('dry-run')) {
            $this->logger->notice('Dry run mode. No changes will be committed to backends.');
        }

        $this->logger->notice('Using WatchState {full_version}', [
            'full_version' => get_full_version(),
        ]);

        foreach ($users as $userContext) {
            try {
                $cursorUpdatedAt = $this->getCursor($userContext);
                $backends = $export = $push = $entities = [];

                foreach ($userContext->config->getAll() as $backendName => $backend) {
                    $type = strtolower(ag($backend, 'type', 'unknown'));

                    if ($isCustom && $input->getOption('exclude') === $this->in_array($selected, $backendName)) {
                        $this->logger->info("Ignoring '{identity.user}@{identity.backend}'. As requested.", [
                            'identity' => [
                                'user' => $userContext->name,
                                'backend' => $backendName,
                            ],
                        ]);
                        continue;
                    }

                    if (true !== (bool) ag($backend, 'export.enabled')) {
                        if ($isCustom) {
                            $this->logger->warning(
                                "Exporting to a export disabled backend '{identity.user}@{identity.backend}' as requested.",
                                [
                                    'identity' => [
                                        'user' => $userContext->name,
                                        'backend' => $backendName,
                                    ],
                                ],
                            );
                        } else {
                            $this->logger->info("Ignoring '{identity.user}@{identity.backend}'. Export disabled.", [
                                'identity' => [
                                    'user' => $userContext->name,
                                    'backend' => $backendName,
                                ],
                            ]);
                            continue;
                        }
                    }

                    if (!isset($supported[$type])) {
                        $this->logger->error(
                            "Ignoring '{identity.user}@{identity.backend}'. Unexpected type '{type}'.",
                            [
                                'type' => $type,
                                'identity' => [
                                    'backend' => $backendName,
                                    'user' => $userContext->name,
                                ],
                                'types' => implode(', ', array_keys($supported)),
                            ],
                        );
                        continue;
                    }

                    if (null === ($url = ag($backend, 'url')) || false === is_valid_url($url)) {
                        $this->logger->error("Ignoring '{identity.user}@{identity.backend}'. Invalid URL '{url}'.", [
                            'url' => $url ?? 'None',
                            'identity' => [
                                'backend' => $backendName,
                                'user' => $userContext->name,
                            ],
                        ]);
                        continue;
                    }

                    $backend['name'] = $backendName;
                    $backends[$backendName] = $backend;
                }

                if (empty($backends)) {
                    $message = $isCustom ? '[-s, --select-backend] flag did not match any backend.' : 'No backends were found for export.';
                    $this->logger->warning("{message}. For '{identity.user}'.", [
                        'message' => $message,
                        'identity' => [
                            'user' => $userContext->name,
                        ],
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
                    $backend['class'] = make_backend(backend: $backend, name: $name, options: [
                        UserContext::class => $userContext,
                    ])->setLogger($this->logger);
                }

                unset($backend);

                if (false === $input->getOption('force-full')) {
                    $minDate = time();

                    foreach ($backends as $backend) {
                        if (null === ($lastSync = ag($backend, 'export.lastSync', null))) {
                            $this->logger->info(
                                "Using export mode for '{identity.user}@{identity.backend}'. No export last Sync date found.",
                                [
                                    'identity' => [
                                        'user' => $userContext->name,
                                        'backend' => ag($backend, 'name'),
                                    ],
                                ],
                            );

                            $export[ag($backend, 'name')] = $backend;
                            continue;
                        }

                        if (null === ag($backend, 'import.lastSync', null)) {
                            $this->logger->warning(
                                "Using export mode for '{identity.user}@{identity.backend}'. The backend metadata not imported. You need to run import to populate the database.",
                                [
                                    'identity' => [
                                        'user' => $userContext->name,
                                        'backend' => ag($backend, 'name'),
                                    ],
                                ],
                            );

                            $export[ag($backend, 'name')] = $backend;
                            continue;
                        }

                        if ($minDate > $lastSync) {
                            $minDate = $lastSync;
                        }
                    }

                    $lastSync = make_date($minDate);

                    $this->logger->notice("Loading '{identity.user}' database items that has changed since '{date}'.", [
                        'date' => (string) $lastSync,
                        'identity' => [
                            'user' => $userContext->name,
                        ],
                    ]);

                    $entities = $userContext->db->getAll($lastSync, [
                        Options::DATE_COLUMN => iState::COLUMN_UPDATED_AT,
                    ]);

                    if (count($entities) < 1 && count($export) < 1) {
                        $this->logger->notice("No play state changes detected since '{date}' for '{identity.user}'.", [
                            'date' => (string) $lastSync,
                            'identity' => [
                                'user' => $userContext->name,
                            ],
                        ]);
                        continue;
                    }

                    if (count($entities) >= 1) {
                        $this->logger->info(
                            "Checking '{total}' media items for push mode compatibility for '{identity.user}'.",
                            (static function () use ($entities, $input, $userContext): array {
                                $context = [
                                    'total' => number_format(count($entities)),
                                    'identity' => [
                                        'user' => $userContext->name,
                                    ],
                                ];

                                if ($input->getOption('trace')) {
                                    foreach ($entities as $entity) {
                                        $context['items'][$entity->id] = $entity->getName();
                                    }
                                }

                                return $context;
                            })(),
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
                                        iState::COLUMN_META_DATA_ADDED_AT,
                                    );
                                    $extraMargin = (int) Config::get('export.not_found');

                                    if (null === $addedDate || false === ctype_digit($addedDate)) {
                                        $this->logger->info(
                                            "Ignoring '{item.id}: {item.title}' for '{identity.user}@{identity.backend}' received invalid added_at '{added_at}' date.",
                                            [
                                                'identity' => [
                                                    'user' => $userContext->name,
                                                    'backend' => $name,
                                                ],
                                                'type' => get_debug_type($addedDate),
                                                'item' => [
                                                    'id' => $entity->id,
                                                    'title' => $entity->getName(),
                                                ],
                                                'added_at' => $addedDate,
                                                'data' => $input->getOption('trace') ? $entity->getAll() : [],
                                            ],
                                        );
                                        continue;
                                    }

                                    if ($lastSync > ($addedDate + $extraMargin)) {
                                        $this->logger->info(
                                            "Ignoring '{item.id}: {item.title}' for '{identity.user}@{identity.backend}' waiting period for metadata expired.",
                                            [
                                                'identity' => [
                                                    'user' => $userContext->name,
                                                    'backend' => $name,
                                                ],
                                                'item' => [
                                                    'id' => $entity->id,
                                                    'title' => $entity->getName(),
                                                ],
                                                'wait_period' => [
                                                    'added_at' => make_date($addedDate),
                                                    'extra_margin' => $extraMargin,
                                                    'last_sync_at' => make_date($lastSync),
                                                    'diff' => $lastSync - ($addedDate + $extraMargin),
                                                ],
                                            ],
                                        );

                                        continue;
                                    }

                                    if (true === ag_exists($push, $name)) {
                                        unset($push[$name]);
                                    }

                                    $this->logger->info(
                                        "Using export mode for '{identity.user}@{identity.backend}'. Backend local database entries did not have metadata for '{item.id}: {item.title}'.",
                                        [
                                            'identity' => [
                                                'user' => $userContext->name,
                                                'backend' => $name,
                                            ],
                                            'item' => [
                                                'id' => $entity->id,
                                                'title' => $entity->getName(),
                                            ],
                                        ],
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
                        "Not possible to use push mode when '-f, --force-full' flag is used.",
                    );
                }

                $this->logger->notice(
                    "'{identity.user}' Using push mode for '{push.total}' backends and export mode for '{export.total}' backends.",
                    [
                        'identity' => [
                            'user' => $userContext->name,
                        ],
                        'push' => [
                            'total' => count($push),
                            'list' => implode(', ', array_keys($push)),
                        ],
                        'export' => [
                            'total' => count($export),
                            'list' => implode(', ', array_keys($export)),
                        ],
                    ],
                );

                if (count($push) >= 1) {
                    $this->push($userContext, $push, $entities);
                }

                if (count($export) >= 1) {
                    $this->export(
                        $userContext,
                        $export,
                        $input->getOption('dry-run'),
                        $input->getOption('force-full'),
                        $syncRequests,
                    );
                }

                $total = count($this->queue->getQueue());

                if ($total >= 1) {
                    $this->logger->notice("Sending '{total}' change play state requests for '{identity.user}'.", [
                        'total' => $total,
                        'identity' => [
                            'user' => $userContext->name,
                        ],
                    ]);

                    $logger = $this->logger;
                    $user = $userContext->name;

                    send_requests(
                        requests: $this->queue->getQueue(),
                        client: $this->http,
                        sync: $syncRequests,
                        logger: $this->logger,
                        opts: [
                            'ok' => static function (Request $request, iResponse $response) use ($logger, $user): array {
                                if (true === (bool) ag($request->options, 'user_data.' . Options::NO_LOGGING, false)) {
                                    return [];
                                }

                                $context = ag($request->extras, 'context', []);
                                $context['identity']['user'] = $user;
                                $context['identity']['backend'] ??= $context['backend'] ?? null;
                                $context['status_code'] = $response->getStatusCode();

                                if (Status::OK !== Status::tryFrom($context['status_code'])) {
                                    $logger->error(
                                        "Request to change '{identity.user}@{identity.backend}' - '#{item.id}: {item.title}' play state returned with unexpected '{status_code}' status code.",
                                        $context,
                                    );

                                    return [];
                                }

                                $logger->notice(
                                    "Updated '{identity.user}@{identity.backend}' - '#{item.id}: {item.title}' watch state to '{play_state}'.",
                                    $context,
                                );

                                return [];
                            },
                            'error' => static function (Request $request, Throwable $ex) use ($logger, $user): array {
                                if (true === (bool) ag($request->options, 'user_data.' . Options::NO_LOGGING, false)) {
                                    return [];
                                }

                                $context = ag($request->extras, 'context', []);
                                $context['identity']['user'] = $user;
                                $context['identity']['backend'] ??= $context['backend'] ?? null;

                                $logger->error(
                                    "Exception '{exception.type}' was thrown unhandled during '{identity.user}@{identity.backend}' request to change play state of {item.type} '#{item.id}: {item.title}'. {exception.message} at '{exception.file}:{exception.line}'.",
                                    [
                                        ...$context,
                                        ...exception_log($ex),
                                    ],
                                );

                                return [];
                            },
                        ],
                    );

                    $this->logger->notice("Sent '{total}' change play state requests for '{identity.user}'.", [
                        'total' => $total,
                        'identity' => [
                            'user' => $userContext->name,
                        ],
                    ]);
                } else {
                    $this->logger->notice("No play state changes detected for '{identity.user}'.", [
                        'identity' => [
                            'user' => $userContext->name,
                        ],
                    ]);
                }

                if (true === $input->getOption('dry-run')) {
                    continue;
                }

                foreach ($backends as $backend) {
                    if (null === ($name = ag($backend, 'name'))) {
                        continue;
                    }

                    if (false === (bool) Message::get("{$name}.has_errors", false)) {
                        $userContext->config->set(
                            "{$name}.export.lastSync",
                            max((int) ag($backend, 'export.lastSync', 0), $cursorUpdatedAt ?? time()),
                        );
                    } else {
                        $this->logger->warning(
                            "Not updating '{identity.user}@{identity.backend}' export last sync date. There was errors recorded during the operation.",
                            [
                                'identity' => [
                                    'backend' => $name,
                                    'user' => $userContext->name,
                                ],
                            ],
                        );
                    }
                }

                $userContext->config->persist();
            } catch (Throwable $e) {
                $this->logger->error(
                    "Unhandled exception '{exception.type}' was thrown during '{identity.user}' export operation. '{exception.message}' at '{exception.file}:{exception.line}'.",
                    [
                        'identity' => [
                            'user' => $userContext->name,
                        ],
                        ...exception_log($e),
                    ],
                );
            } finally {
                $this->queue->reset();
                $userContext->mapper->reset();
            }
        }

        $this->logger->notice('Using WatchState {full_version}', [
            'full_version' => get_full_version(),
        ]);

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
        $this->logger->notice("Push mode started for '{identity.user}: {backends}'.", [
            'identity' => [
                'user' => $userContext->name,
            ],
            'backends' => implode(', ', array_keys($backends)),
        ]);

        foreach ($backends as $backend) {
            assert($backend['class'] instanceof ClientInterface, 'Backend class must implement ClientInterface.');
            $backend['class']->push(
                entities: $entities,
                queue: $this->queue,
                after: make_date(ag($backend, 'export.lastSync')),
            );
        }

        $this->logger->notice("Push mode ended for '{identity.user}: {backends}'.", [
            'identity' => [
                'user' => $userContext->name,
            ],
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
        bool $syncRequests = false,
    ): void {
        $this->logger->notice("Export mode started for '{identity.user}@{backends}'.", [
            'identity' => [
                'user' => $userContext->name,
            ],
            'backends' => implode(', ', array_keys($backends)),
        ]);

        $this->logger->notice(
            message: "Preloading user '{identity.user}: {mapper}' data. Memory usage '{memory.now}'.",
            context: [
                'identity' => [
                    'user' => $userContext->name,
                ],
                'mapper' => after_last($userContext->mapper::class, '\\'),
                'memory' => [
                    'now' => get_memory_usage(),
                    'peak' => get_peak_memory_usage(),
                ],
            ],
        );

        $time = microtime(true);
        $userContext->mapper->reset()->loadData();

        $this->logger->notice(
            message: "Preloading user '{identity.user}: {mapper}' data completed in '{duration}s'. Memory usage '{memory.now}'.",
            context: [
                'identity' => [
                    'user' => $userContext->name,
                ],
                'mapper' => after_last($userContext->mapper::class, '\\'),
                'duration' => round(microtime(true) - $time, 4),
                'memory' => [
                    'now' => get_memory_usage(),
                    'peak' => get_peak_memory_usage(),
                ],
            ],
        );

        $requests = [];

        foreach ($backends as $backend) {
            if (null === ($name = ag($backend, 'name'))) {
                continue;
            }

            $after = true === $isFull ? null : ag($backend, 'export.lastSync', null);

            if (null === $after) {
                $this->logger->notice("Exporting play state to '{identity.user}@{identity.backend}'.", [
                    'identity' => [
                        'backend' => $name,
                        'user' => $userContext->name,
                    ],
                ]);
            } else {
                $after = make_date($after);
                $this->logger->notice("Exporting play state changes since '{date}' to '{identity.user}@{identity.backend}'.", [
                    'identity' => [
                        'backend' => $name,
                        'user' => $userContext->name,
                    ],
                    'date' => (string) $after,
                ]);
            }

            assert($backend['class'] instanceof ClientInterface, 'Backend class must implement ClientInterface.');
            array_push($requests, ...$backend['class']->export($userContext->mapper, $this->queue, $after));
        }

        $start = microtime(true);
        $this->logger->notice("Sending '{total}' play state comparison {sync}requests for '{identity.user}'.", [
            'total' => count($requests),
            'identity' => [
                'user' => $userContext->name,
            ],
            'sync' => true === $syncRequests ? 'sync ' : '',
        ]);

        send_requests(requests: $requests, client: $this->http, sync: $syncRequests, logger: $this->logger);

        $this->logger->notice("Export mode ended for '{identity.user}: {backends}' in '{duration}'s.", [
            'identity' => [
                'user' => $userContext->name,
            ],
            'backends' => implode(', ', array_keys($backends)),
            'duration' => round(microtime(true) - $start, 4),
        ]);
    }

    private function in_array(array $list, string $search): bool
    {
        return array_any($list, static fn($item) => str_starts_with($search, $item));
    }

    private function getCursor(UserContext $userContext): ?int
    {
        $stmt = $userContext
            ->db
            ->getDBLayer()
            ->query('SELECT MAX(' . iState::COLUMN_UPDATED_AT . ') FROM state');

        if (false === ($max = $stmt->fetchColumn())) {
            return null;
        }

        return null === $max ? null : (int) $max;
    }
}
