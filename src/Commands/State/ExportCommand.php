<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Data;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Options;
use App\Libs\Servers\ServerInterface;
use App\Libs\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Throwable;

class ExportCommand extends Command
{
    public const TASK_NAME = 'export';

    public function __construct(
        private StorageInterface $storage,
        private ExportInterface $mapper,
        private LoggerInterface $logger
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('state:export')
            ->setDescription('Export local play state to backends.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full export. Ignore last sync date.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit any changes.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption('servers-filter', 's', InputOption::VALUE_OPTIONAL, 'Select backends. Comma (,) seperated.', '')
            ->addOption('ignore-date', 'i', InputOption::VALUE_NONE, 'Ignore date comparison.')
            ->addOption('trace', null, InputOption::VALUE_NONE, 'Enable Debug Tracing mode.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output);
    }

    protected function process(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            if (!is_string($config) || !is_file($config) || !is_readable($config)) {
                throw new RuntimeException('Unable to read given config file.');
            }
            $custom = true;
            Config::save('servers', Yaml::parseFile($config));
        } else {
            $custom = false;
            $config = Config::get('path') . '/config/servers.yaml';
        }

        $backends = [];
        $backendsFilter = (string)$input->getOption('servers-filter');
        $selected = explode(',', $backendsFilter);
        $isCustom = !empty($backendsFilter) && count($selected) >= 1;
        $supported = Config::get('supported', []);

        if ($input->getOption('dry-run')) {
            $output->writeln('<info>Dry run mode. No changes will be committed.</info>');
        }

        $this->logger->info(sprintf('Using WatchState Version - \'%s\'.', getAppVersion()));

        foreach (Config::get('servers', []) as $name => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if ($isCustom && false === in_array($name, $selected)) {
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

        if (true === $this->isPushable($backends, $input)) {
            $this->logger->info('Using push mode.');
            $this->push($backends, $input);
        } else {
            $this->logger->info('Using export mode.');
            $this->export($backends, $input);
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

        return self::SUCCESS;
    }

    protected function push(array $backends): int
    {
        $minDate = time();

        foreach ($backends as $backend) {
            if (null === ($lastSync = ag($backend, 'export.lastSync', null))) {
                throw new RuntimeException(
                    sprintf('%s: does not have recorded export lastSync.', ag($backend, 'name'))
                );
            }

            if ($minDate > $lastSync) {
                $minDate = $lastSync;
            }
        }

        $lastSync = makeDate($minDate);

        $this->logger->notice(
            sprintf('STORAGE: Preloading changed items since \'%s\'.', $lastSync->format('Y-m-d H:i:s T'))
        );

        $entities = $this->storage->getAll($lastSync);

        if (empty($entities)) {
            $this->logger->notice('STORAGE: No items changed since last export date.', [
                'date' => $lastSync,
            ]);
            return self::SUCCESS;
        }

        $this->logger->notice(sprintf('STORAGE: Found \'%d\' changed items.', count($entities)));

        $requests = [];

        foreach ($backends as $backend) {
            if (null === ag($backend, 'name')) {
                continue;
            }

            assert($backend['class'] instanceof ServerInterface);

            array_push($requests, ...$backend['class']->push($entities, makeDate(ag($backend, 'export.lastSync'))));
        }

        $total = count($requests);

        if ($total < 1) {
            $this->logger->notice('No play state changes detected.');
            return self::SUCCESS;
        }

        $this->logger->notice(sprintf('HTTP: Sending \'%d\' change play state requests.', $total));

        foreach ($requests as $response) {
            $requestData = $response->getInfo('user_data');

            try {
                if (200 !== $response->getStatusCode()) {
                    $this->logger->error(
                        sprintf(
                            '%s: Request to change \'%s\' play state responded with unexpected status code \'%d\'.',
                            ag($requestData, 'server', '??'),
                            ag($requestData, 'itemName', '??'),
                            $response->getStatusCode()
                        )
                    );
                    continue;
                }

                $this->logger->notice(
                    sprintf(
                        '%s: Marked \'%s\' as \'%s\'.',
                        ag($requestData, 'server', '??'),
                        ag($requestData, 'itemName', '??'),
                        ag($requestData, 'state', '??'),
                    )
                );
            } catch (ExceptionInterface $e) {
                $this->logger->error($e->getMessage());
            }
        }

        $this->logger->notice(sprintf('HTTP: Processed \'%d\' change play state requests.', $total));

        return self::SUCCESS;
    }

    /**
     * Pull and compare status and then push.
     *
     * @param array $backends
     * @param InputInterface $input
     *
     * @return mixed
     */
    protected function export(array $backends, InputInterface $input): mixed
    {
        $this->logger->notice('MAPPER: Preloading database into memory.');
        $this->mapper->loadData();
        $this->logger->notice('MAPPER: Finished Preloading database.');

        $this->storage->singleTransaction();

        $requests = [];

        foreach ($backends as $backend) {
            if (null === ($name = ag($backend, 'name'))) {
                continue;
            }

            $after = true === $input->getOption('force-full') ? null : ag($backend, 'export.lastSync', null);

            if (null === $after) {
                $this->logger->notice(sprintf('%s: Exporting all local play state to this backend.', $name));
            } else {
                $after = makeDate($after);
                $this->logger->notice(
                    sprintf(
                        '%s: Exporting play state changes since \'%s\' to this backend.',
                        $name,
                        $after->format('Y-m-d H:i:s T')
                    )
                );
            }

            assert($backend['class'] instanceof ServerInterface);

            array_push($requests, ...$backend['class']->export($this->mapper, $after));

            if (false === $input->getOption('dry-run')) {
                if (true === (bool)Data::get(sprintf('%s.no_export_update', $name))) {
                    $this->logger->notice(
                        sprintf('%s: Not updating last export date. Backend reported an error.', $name)
                    );
                } else {
                    Config::save(sprintf('servers.%s.export.lastSync', $name), time());
                }
            }
        }

        unset($server);

        $this->logger->notice(sprintf('HTTP: Sending \'%d\' state comparison requests.', count($requests)));

        foreach ($requests as $response) {
            $requestData = $response->getInfo('user_data');
            try {
                $requestData['ok']($response);
            } catch (Throwable $e) {
                $requestData['error']($e);
            }
        }

        $this->logger->notice(sprintf('HTTP: Finished sending \'%d\' state comparison requests.', count($requests)));

        $changes = $this->mapper->getQueue();
        $total = count($changes);

        if ($total >= 1) {
            $this->logger->notice(sprintf('HTTP: Sending \'%d\' stats change requests.', $total));
            foreach ($changes as $response) {
                $requestData = $response->getInfo('user_data');
                try {
                    if (200 !== $response->getStatusCode()) {
                        throw new ServerException($response);
                    }
                    $this->logger->notice(
                        sprintf(
                            '%s: Marked \'%s\' as \'%s\'.',
                            ag($requestData, 'server', '??'),
                            ag($requestData, 'itemName', '??'),
                            ag($requestData, 'state', '??'),
                        )
                    );
                } catch (ExceptionInterface $e) {
                    $this->logger->error($e->getMessage());
                }
            }
            $this->logger->notice(sprintf('HTTP: Finished sending \'%d\' state change requests.', $total));
        } else {
            $this->logger->notice('No state changes detected.');
        }

        return [];
    }

    /**
     * Is the number of changes exceed export threshold.
     *
     * @param array $backends
     * @param InputInterface $input
     *
     * @return bool
     */
    protected function isPushable(array $backends, InputInterface $input): bool
    {
        if (true === $input->getOption('force-full')) {
            $this->logger->info('Not possible to use push mode when [-f, --force-full] flag is used.');
            return false;
        }

        $threshold = Config::get('export.threshold', 300);

        foreach ($backends as $backend) {
            if (null === ($name = ag($backend, 'name'))) {
                continue;
            }

            if (false === (bool)ag($backend, 'import.enabled')) {
                $this->logger->info(
                    sprintf('%s: Import are disabled from this backend. Falling back to export mode.', $name)
                );
                return false;
            }

            if (null === ($after = ag($backend, 'export.lastSync', null))) {
                $this->logger->info(
                    sprintf('%s: This backend has not been synced before. falling back to export mode.', $name)
                );
                return false;
            }

            $count = $this->storage->getCount(makeDate($after));

            if ($count > $threshold) {
                $this->logger->info(
                    sprintf('%s: Media changes exceed push threshold. falling back to export mode.', $name),
                    [
                        'threshold' => $threshold,
                        'changes' => $count,
                    ]
                );
                return false;
            }
        }

        return true;
    }
}
