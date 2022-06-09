<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Data;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class ExportCommand extends Command
{
    public const TASK_NAME = 'export';

    public function __construct(
        private StorageInterface $storage,
        private DirectMapper $mapper,
        private QueueRequests $queue,
        private LoggerInterface $logger
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('state:export')
            ->setDescription('Export play state to backends.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full export. Ignore last export date.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit changes to backends.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption('servers-filter', 's', InputOption::VALUE_OPTIONAL, 'Select backends. Comma (,) seperated.', '')
            ->addOption('exclude', null, InputOption::VALUE_NONE, 'Inverse --servers-filter logic to exclude.')
            ->addOption('ignore-date', 'i', InputOption::VALUE_NONE, 'Ignore date comparison.')
            ->addOption('trace', null, InputOption::VALUE_NONE, 'Enable debug tracing mode.')
            ->addOption(
                'always-update-metadata',
                null,
                InputOption::VALUE_NONE,
                'Always update the locally stored metadata from backend.'
            )
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            // -- @RELEASE remove force-* options
            ->addOption('force-export-mode', null, InputOption::VALUE_NONE, 'Force export mode. [NO LONGER USED].')
            ->addOption('force-push-mode', null, InputOption::VALUE_NONE, 'Force push mode. [NO LONGER USED].');
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
                $this->checkCustomServersFile($config);
                $custom = true;
                Config::save('servers', Yaml::parseFile($config));
            } catch (RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        } else {
            $custom = false;
            $config = Config::get('path') . '/config/servers.yaml';
        }

        $backends = [];
        $backendsFilter = (string)$input->getOption('servers-filter');
        $selected = explode(',', $backendsFilter);
        $isCustom = !empty($backendsFilter) && count($selected) >= 1;
        $supported = Config::get('supported', []);
        $export = $push = $entities = [];

        if (true === $input->getOption('dry-run')) {
            $output->writeln('<info>Dry run mode. No changes will be committed to backends.</info>');
        }

        foreach (Config::get('servers', []) as $name => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if ($isCustom && $input->getOption('exclude') === in_array($name, $selected)) {
                $this->logger->info(
                    sprintf('%s: Ignoring backend as requested by [-s, --servers-filter].', $name)
                );
                continue;
            }

            if (true !== ag($backend, 'export.enabled')) {
                $this->logger->info(sprintf('%s: Ignoring backend as requested by user config.', $name));
                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->error(
                    sprintf(
                        '%s: Unexpected type. Expecting \'%s\' but got \'%s\'.',
                        $name,
                        implode(', ', array_keys($supported)),
                        $type
                    )
                );
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === filter_var($url, FILTER_VALIDATE_URL)) {
                $this->logger->error(sprintf('%s: Backend does not have valid url.', $name), ['url' => $url ?? 'None']);
                continue;
            }

            $backend['name'] = $name;
            $backends[$name] = $backend;
        }

        if (empty($backends)) {
            $output->writeln(
                sprintf(
                    '<error>%s</error>',
                    $isCustom ? '[-s, --servers-filter] Filter did not match any backend.' : 'No backends were found.'
                )
            );
            return self::FAILURE;
        }

        foreach ($backends as &$backend) {
            if (null === ($name = ag($backend, 'name'))) {
                continue;
            }

            Data::addBucket($name);

            $opts = ag($backend, 'options', []);

            if ($input->getOption('ignore-date')) {
                $opts[Options::IGNORE_DATE] = true;
            }

            if ($input->getOption('dry-run')) {
                $opts[Options::DRY_RUN] = true;
            }

            if ($input->getOption('timeout')) {
                $opts['client']['timeout'] = $input->getOption('timeout');
            }

            $backend['options'] = $opts;
            $backend['class'] = makeServer($backend, $name)->setLogger($this->logger);
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

                if ($minDate > $lastSync) {
                    $minDate = $lastSync;
                }
            }

            $lastSync = makeDate($minDate);

            $this->logger->notice('STORAGE: Loading changed items since [%(date)].', [
                'date' => $lastSync->format('Y-m-d H:i:s T')
            ]);

            $entities = $this->storage->getAll($lastSync);

            if (count($entities) < 1 && count($export) < 1) {
                $this->logger->notice('STORAGE: No play state change detected since [%(date)].', [
                    'date' => $lastSync,
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

                        if (null === ag($backend, 'export.lastSync', null)) {
                            continue;
                        }

                        if (false === ag_exists($entity->getMetadata(), $name)) {
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

        $total = $this->queue->getQueue();

        if (count($total) >= 1) {
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
        } else {
            $this->logger->notice('No play state changes detected.');
        }

        if (false === $input->getOption('dry-run')) {
            foreach ($backends as $backend) {
                if (null === ($name = ag($backend, 'name'))) {
                    continue;
                }

                if (true === (bool)Data::get(sprintf('%s.no_export_update', $name))) {
                    $this->logger->notice(
                        sprintf('%s: Not updating last export date. Backend reported an error.', $name)
                    );
                } else {
                    Config::save(sprintf('servers.%s.export.lastSync', $name), time());
                    Config::save(sprintf('servers.%s.persist', $name), $backend['class']->getPersist());
                }
            }

            if (false === $custom && is_writable(dirname($config))) {
                copy($config, $config . '.bak');
            }

            file_put_contents($config, Yaml::dump(Config::get('servers', []), 8, 2));
        }

        $this->logger->notice(sprintf('Using WatchState Version - \'%s\'.', getAppVersion()));

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
                queue:    $this->queue,
                after:    makeDate(ag($backend, 'export.lastSync'))
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

        if ($input->getOption('always-update-metadata')) {
            $mapperOpts[Options::MAPPER_ALWAYS_UPDATE_META] = true;
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

        $this->storage->singleTransaction();

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
                if (true === (bool)Data::get(sprintf('%s.no_export_update', $name))) {
                    $this->logger->notice('Not updating last export date. [%(backend)] report an error.', [
                        'backend' => $name,
                    ]);
                } else {
                    Config::save(sprintf('servers.%s.export.lastSync', $name), time());
                }
            }
        }

        unset($server);

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
