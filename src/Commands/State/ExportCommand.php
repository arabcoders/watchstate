<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Backends\Common\ClientInterface;
use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\StreamLogHandler;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\Stream;
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
     * @param iDB $db The instance of the iDB class.
     * @param DirectMapper $mapper The instance of the DirectMapper class.
     * @param QueueRequests $queue The instance of the QueueRequests class.
     * @param iLogger $logger The instance of the iLogger class.
     */
    public function __construct(
        private iDB $db,
        private DirectMapper $mapper,
        private QueueRequests $queue,
        private iLogger $logger
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
            $this->logger->setHandlers([new StreamLogHandler(new Stream($logfile, 'w'), $output)]);
        }

        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml');
        $configFile->setLogger($this->logger);

        $backends = [];
        $selected = $input->getOption('select-backend');
        $isCustom = !empty($selected) && count($selected) > 0;
        $supported = Config::get('supported', []);
        $export = $push = $entities = [];

        if (true === $input->getOption('dry-run')) {
            $this->logger->notice('Dry run mode. No changes will be committed to backends.');
        }

        foreach ($configFile->getAll() as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if ($isCustom && $input->getOption('exclude') === in_array($backendName, $selected)) {
                $this->logger->info('{backend}: Ignoring backend as requested by [-s, --select-backend].', [
                    'backend' => $backendName
                ]);
                continue;
            }

            if (true !== ag($backend, 'export.enabled')) {
                $this->logger->info('{backend}: Ignoring backend as requested by user config.', [
                    'backend' => $backendName
                ]);
                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->error('{backend}: Is using unexpected type \'{type}\'. Expecting \'{types}\'.', [
                    'type' => $type,
                    'backend' => $backendName,
                    'types' => implode(', ', array_keys($supported)),
                ]);
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === isValidURL($url)) {
                $this->logger->error('{backend}: Backend does not have valid url.', [
                    'url' => $url ?? 'None',
                    $backendName,
                ]);
                continue;
            }

            $backend['name'] = $backendName;
            $backends[$backendName] = $backend;
        }

        if (empty($backends)) {
            $this->logger->warning(
                $isCustom ? '[-s, --select-backend] flag did not match any backend.' : 'No backends were found.'
            );
            return self::FAILURE;
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
            $backend['class'] = $this->getBackend($name, $backend)->setLogger($this->logger);
        }

        unset($backend);

        if (false === $input->getOption('force-full')) {
            $minDate = time();

            foreach ($backends as $backend) {
                if (null === ($lastSync = ag($backend, 'export.lastSync', null))) {
                    $this->logger->info(
                        'SYSTEM: Using export mode for [{backend}] as the backend did have last export date.',
                        [
                            'backend' => ag($backend, 'name'),
                        ]
                    );

                    $export[ag($backends, 'name')] = $backend;
                    continue;
                }

                if (null === ag($backend, 'import.lastSync', null)) {
                    $this->logger->warning(
                        'SYSTEM: Using export mode for [{backend}]. server data is not yet imported. please run state:import',
                        [
                            'backend' => ag($backend, 'name'),
                        ]
                    );

                    $export[ag($backends, 'name')] = $backend;
                    continue;
                }

                if ($minDate > $lastSync) {
                    $minDate = $lastSync;
                }
            }

            $lastSync = makeDate($minDate);

            $this->logger->notice('DATABASE: Loading changed items since [{date}].', [
                'date' => $lastSync->format('Y-m-d H:i:s T')
            ]);

            $entities = $this->db->getAll($lastSync);

            if (count($entities) < 1 && count($export) < 1) {
                $this->logger->notice('DATABASE: No play state change detected since [{date}].', [
                    'date' => $lastSync->format('Y-m-d H:i:s T')
                ]);
                return self::SUCCESS;
            }

            if (count($entities) >= 1) {
                $this->logger->info(
                    'SYSTEM: Checking [{total}] media items for push mode compatibility.',
                    (function () use ($entities, $input): array {
                        $context = [
                            'total' => number_format(count($entities)),
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
                            $addedDate = ag($entity->getMetadata($entity->via), iState::COLUMN_META_DATA_ADDED_AT);
                            $extraMargin = (int)Config::get('export.not_found');

                            if (null !== $addedDate && $lastSync > ($addedDate + $extraMargin)) {
                                $this->logger->info(
                                    'SYSTEM: Ignoring [{item.title}] for [{backend}] waiting period for metadata expired.',
                                    [
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
                                'SYSTEM: Using export mode for [{backend}] as the backend did not register metadata for [{item.id}: {item.title}].',
                                [
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
            $this->logger->notice('Not possible to use push mode when [-f, --force-full] flag is used.');
        }

        $this->logger->notice(
            'SYSTEM: Using push mode for [{push.total}] backends and export mode for [{export.total}] backends.',
            [
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
            $this->push($push, $entities);
        }

        if (count($export) >= 1) {
            $this->export($export, $input, $configFile);
        }

        $total = count($this->queue->getQueue());

        if ($total >= 1) {
            $this->logger->notice('SYSTEM: Sending [{total}] change play state requests.', [
                'total' => $total
            ]);

            foreach ($this->queue->getQueue() as $response) {
                $context = ag($response->getInfo('user_data'), 'context', []);

                try {
                    if (200 !== ($statusCode = $response->getStatusCode())) {
                        $this->logger->error(
                            'Request to change [{backend}] [{item.title}] play state returned with unexpected [{status_code}] status code.',
                            [
                                'status_code' => $statusCode,
                                ...$context,
                            ],
                        );
                        continue;
                    }

                    $this->logger->notice('Marked [{backend}] [{item.title}] as [{play_state}].', $context);
                } catch (Throwable $e) {
                    $this->logger->error(
                        message: 'Exception [{error.kind}] was thrown unhandled during [{backend}] request to change play state of {item.type} [{item.title}]. Error [{error.message} @ {error.file}:{error.line}].',
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

            $this->logger->notice('SYSTEM: Sent [{total}] change play state requests.', [
                'total' => $total
            ]);

            $this->logger->notice('Using WatchState Version - \'{version}\'.', ['version' => getAppVersion()]);
        } else {
            $this->logger->notice('SYSTEM: No play state changes detected.');
        }

        if (false === $input->getOption('dry-run')) {
            foreach ($backends as $backend) {
                if (null === ($name = ag($backend, 'name'))) {
                    continue;
                }

                if (false === (bool)Message::get("{$name}.has_errors", false)) {
                    $configFile->set("{$name}.export.lastSync", time());
                } else {
                    $this->logger->warning(
                        'SYSTEM: Not updating last export date for [{backend}]. Backend reported an error.',
                        [
                            'backend' => $name,
                        ]
                    );
                }
            }

            $configFile->persist();
        }

        return self::SUCCESS;
    }

    /**
     * Push entities to backends if applicable.
     *
     * @param array $backends An array of backends.
     * @param array $entities An array of entities to be pushed.
     *
     * @return int The success status code.
     */
    protected function push(array $backends, array $entities): int
    {
        $this->logger->notice('Push mode start.', [
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

        $this->logger->notice('Push mode ends.', [
            'backends' => implode(', ', array_keys($backends)),
        ]);

        return self::SUCCESS;
    }

    /**
     * Fallback to export mode if push mode is not supported for the backend.
     *
     * @param array $backends An array of backends to export data to.
     * @param InputInterface $input The input containing export options.
     * @param ConfigFile $configFile The instance of the ConfigFile class.
     */
    protected function export(array $backends, InputInterface $input, ConfigFile $configFile): void
    {
        $this->logger->notice('Export mode start.', [
            'backends' => implode(', ', array_keys($backends)),
        ]);

        $mapperOpts = [];

        if ($input->getOption('dry-run')) {
            $mapperOpts[Options::DRY_RUN] = true;
        }

        if ($input->getOption('trace')) {
            $mapperOpts[Options::DEBUG_TRACE] = true;
        }

        if (!empty($mapperOpts)) {
            $this->mapper->setOptions(options: $mapperOpts);
        }

        $this->logger->notice('SYSTEM: Preloading {mapper} data.', [
            'mapper' => afterLast($this->mapper::class, '\\'),
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        $this->mapper->reset()->loadData();

        $this->logger->notice('SYSTEM: Preloading {mapper} data is complete.', [
            'mapper' => afterLast($this->mapper::class, '\\'),
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        $this->db->singleTransaction();

        $requests = [];

        foreach ($backends as $backend) {
            if (null === ($name = ag($backend, 'name'))) {
                continue;
            }

            $after = true === $input->getOption('force-full') ? null : ag($backend, 'export.lastSync', null);

            if (null === $after) {
                $this->logger->notice('SYSTEM: Exporting play state to [{backend}].', [
                    'backend' => $name,
                ]);
            } else {
                $after = makeDate($after);
                $this->logger->notice('SYSTEM: Exporting play state changes since [{date}] to [{backend}].', [
                    'backend' => $name,
                    'date' => $after->format('Y-m-d H:i:s T')
                ]);
            }

            assert($backend['class'] instanceof ClientInterface, 'Backend class must implement ClientInterface.');
            array_push($requests, ...$backend['class']->export($this->mapper, $this->queue, $after));

            if (false === $input->getOption('dry-run')) {
                if (true === (bool)Message::get("{$name}.has_errors")) {
                    $this->logger->warning('SYSTEM: Not updating last export date. [{backend}] report an error.', [
                        'backend' => $name,
                    ]);
                } else {
                    $configFile->set("{$name}.export.lastSync", time());
                }
            }
        }

        $this->logger->notice('SYSTEM: Sending [{total}] play state comparison requests.', [
            'total' => count($requests),
        ]);

        foreach ($requests as $response) {
            $requestData = $response->getInfo('user_data');
            try {
                $requestData['ok']($response);
            } catch (Throwable $e) {
                $requestData['error']($e);
            }
        }

        $this->logger->notice('SYSTEM: Sent [{total}] play state comparison requests.', [
            'total' => count($requests),
        ]);

        $this->logger->notice('Export mode ends.', [
            'backends' => implode(', ', array_keys($backends)),
        ]);
    }
}
