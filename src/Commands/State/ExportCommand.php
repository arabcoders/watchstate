<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\Routable;
use Psr\Log\LoggerInterface as iLogger;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

#[Routable(command: self::ROUTE)]
class ExportCommand extends Command
{
    public const ROUTE = 'state:export';

    public const TASK_NAME = 'export';

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

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Export play state to backends.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full export. Ignore last export date.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit changes to backends.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption('select-backends', 's', InputOption::VALUE_OPTIONAL, 'Select backends. comma , seperated.', '')
            ->addOption('exclude', null, InputOption::VALUE_NONE, 'Inverse --select-backends logic.')
            ->addOption('ignore-date', 'i', InputOption::VALUE_NONE, 'Ignore date comparison.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addOption('servers-filter', null, InputOption::VALUE_OPTIONAL, '[DEPRECATED] Select backends.', '')
            ->addOption('force-export-mode', null, InputOption::VALUE_NONE, '[DEPRECATED] Force export mode.')
            ->addOption('force-push-mode', null, InputOption::VALUE_NONE, '[DEPRECATED] Force push mode.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output);
    }

    protected function process(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                $custom = true;
                Config::save('servers', Yaml::parseFile($this->checkCustomBackendsFile($config)));
            } catch (RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        } else {
            $custom = false;
            $config = Config::get('path') . '/config/servers.yaml';
        }

        $selectBackends = (string)$input->getOption('select-backends');
        $serversFilter = (string)$input->getOption('servers-filter');

        if (!empty($serversFilter)) {
            $this->logger->warning(
                'The [--servers-filter] flag is deprecated and will be removed in v1.0. Use [--select-backends].'
            );
            if (empty($selectBackends)) {
                $selectBackends = $serversFilter;
            }
        }

        $backends = [];
        $selected = explode(',', $selectBackends);
        $isCustom = !empty($selectBackends) && count($selected) >= 1;
        $supported = Config::get('supported', []);
        $export = $push = $entities = [];

        if (true === $input->getOption('dry-run')) {
            $output->writeln('<info>Dry run mode. No changes will be committed to backends.</info>');
        }

        foreach (Config::get('servers', []) as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if ($isCustom && $input->getOption('exclude') === in_array($backendName, $selected)) {
                $this->logger->info(
                    sprintf('%s: Ignoring backend as requested by [-s, --select-backends].', $backendName)
                );
                continue;
            }

            if (true !== ag($backend, 'export.enabled')) {
                $this->logger->info(sprintf('%s: Ignoring backend as requested by user config.', $backendName));
                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->error(
                    sprintf(
                        '%s: Unexpected type. Expecting \'%s\' but got \'%s\'.',
                        $backendName,
                        implode(', ', array_keys($supported)),
                        $type
                    )
                );
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === filter_var($url, FILTER_VALIDATE_URL)) {
                $this->logger->error(
                    sprintf('%s: Backend does not have valid url.', $backendName),
                    ['url' => $url ?? 'None']
                );
                continue;
            }

            $backend['name'] = $backendName;
            $backends[$backendName] = $backend;
        }

        if (empty($backends)) {
            $output->writeln(
                sprintf(
                    '<error>%s</error>',
                    $isCustom ? '[-s, --select-backends] flag did not match any backend.' : 'No backends were found.'
                )
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
            $backend['class'] = makeBackend($backend, $name)->setLogger($this->logger);
        }

        unset($backend);

        if (false === $input->getOption('force-full')) {
            $minDate = time();

            foreach ($backends as $backend) {
                if (null === ($lastSync = ag($backend, 'export.lastSync', null))) {
                    $this->logger->info(
                        'SYSTEM: Using export mode for [%(backend)] as the backend did have last export date.',
                        [
                            'backend' => ag($backend, 'name'),
                        ]
                    );

                    $export[ag($backends, 'name')] = $backend;
                    continue;
                }

                if (null === ag($backend, 'import.lastSync', null)) {
                    $this->logger->warning(
                        'SYSTEM: Using export mode for [%(backend)]. server data is not yet imported. please run state:import',
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

            $this->logger->notice('DATABASE: Loading changed items since [%(date)].', [
                'date' => $lastSync->format('Y-m-d H:i:s T')
            ]);

            $entities = $this->db->getAll($lastSync);

            if (count($entities) < 1 && count($export) < 1) {
                $this->logger->notice('DATABASE: No play state change detected since [%(date)].', [
                    'date' => $lastSync->format('Y-m-d H:i:s T')
                ]);
                return self::SUCCESS;
            }

            if (count($entities) >= 1) {
                $this->logger->info(
                    'SYSTEM: Checking [%(total)] media items for push mode compatibility.',
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
                                    'SYSTEM: Ignoring [%(item.title)] for [%(backend)] waiting period for metadata expired.',
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
                                'SYSTEM: Using export mode for [%(backend)] as the backend did not register metadata for [%(item.title)].',
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
            'SYSTEM: Using push mode for [%(push.total)] backends and export mode for [%(export.total)] backends.',
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
            $this->export($export, $input);
        }

        $total = count($this->queue->getQueue());

        if ($total >= 1) {
            $this->logger->notice('SYSTEM: Sending [%(total)] change play state requests.', [
                'total' => $total
            ]);

            foreach ($this->queue->getQueue() as $response) {
                $context = ag($response->getInfo('user_data'), 'context', []);

                try {
                    if (200 !== $response->getStatusCode()) {
                        $this->logger->error(
                            'Request to change [%(backend)] [%(item.title)] play state returned with unexpected [%(status_code)] status code.',
                            $context
                        );
                        continue;
                    }

                    $this->logger->notice('Marked [%(backend)] [%(item.title)] as [%(play_state)].', $context);
                } catch (Throwable $e) {
                    $this->logger->error(
                        'Unhandled exception thrown during request to change play state of [%(backend)] %(item.type) [%(item.title)].',
                        [
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

            $this->logger->notice('SYSTEM: Sent [%(total)] change play state requests.', [
                'total' => $total
            ]);

            $this->logger->notice(sprintf('Using WatchState Version - \'%s\'.', getAppVersion()));
        } else {
            $this->logger->notice('SYSTEM: No play state changes detected.');
        }

        if (false === $input->getOption('dry-run')) {
            foreach ($backends as $backend) {
                if (null === ($name = ag($backend, 'name'))) {
                    continue;
                }

                if (false === (bool)Message::get("{$name}.has_errors", false)) {
                    Config::save(sprintf('servers.%s.export.lastSync', $name), time());
                } else {
                    $this->logger->warning(
                        'SYSTEM: Not updating last export date for [%(backend)]. Backend reported an error.',
                        [
                            'backend' => $name,
                        ]
                    );
                }
            }

            if (false === $custom && is_writable(dirname($config))) {
                copy($config, $config . '.bak');
            }

            file_put_contents($config, Yaml::dump(Config::get('servers', []), 8, 2));
        }


        return self::SUCCESS;
    }

    protected function push(array $backends, array $entities): int
    {
        $this->logger->notice('Push mode start.', [
            'backends' => implode(', ', array_keys($backends)),
        ]);

        foreach ($backends as $backend) {
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
     * Pull and compare status and then push.
     *
     * @param array $backends
     * @param InputInterface $input
     */
    protected function export(array $backends, InputInterface $input): void
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

        $this->logger->notice('SYSTEM: Preloading %(mapper) data.', [
            'mapper' => afterLast($this->mapper::class, '\\'),
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        $this->mapper->reset()->loadData();

        $this->logger->notice('SYSTEM: Preloading %(mapper) data is complete.', [
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
                $this->logger->notice('SYSTEM: Exporting play state to [%(backend)].', [
                    'backend' => $name,
                ]);
            } else {
                $after = makeDate($after);
                $this->logger->notice('SYSTEM: Exporting play state changes since [%(date)] to [%(backend)].', [
                    'backend' => $name,
                    'date' => $after->format('Y-m-d H:i:s T')
                ]);
            }

            array_push($requests, ...$backend['class']->export($this->mapper, $this->queue, $after));

            if (false === $input->getOption('dry-run')) {
                if (true === (bool)Message::get("{$name}.has_errors")) {
                    $this->logger->warning('SYSTEM: Not updating last export date. [%(backend)] report an error.', [
                        'backend' => $name,
                    ]);
                } else {
                    Config::save("servers.{$name}.export.lastSync", time());
                }
            }
        }

        $this->logger->notice('SYSTEM: Sending [%(total)] play state comparison requests.', [
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

        $this->logger->notice('SYSTEM: Sent [%(total)] play state comparison requests.', [
            'total' => count($requests),
        ]);

        $this->logger->notice('Export mode ends.', [
            'backends' => implode(', ', array_keys($backends)),
        ]);
    }
}
