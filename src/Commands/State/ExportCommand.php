<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Data;
use App\Libs\Extends\CliLogger;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Options;
use App\Libs\Storage\PDO\PDOAdapter;
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
            ->setDescription('Export watch state to servers.')
            ->addOption('redirect-logger', 'r', InputOption::VALUE_NONE, 'Redirect logger to stdout.')
            ->addOption('memory-usage', 'm', InputOption::VALUE_NONE, 'Show memory usage.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full export. (will ignore lastSync date)')
            ->addOption(
                'proxy',
                null,
                InputOption::VALUE_REQUIRED,
                'By default the HTTP client uses your ENV: HTTP_PROXY.'
            )
            ->addOption(
                'no-proxy',
                null,
                InputOption::VALUE_REQUIRED,
                'Disables the proxy for a comma-separated list of hosts that do not require it to get reached.'
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'Set request timeout in seconds'
            )
            ->addOption(
                'servers-filter',
                's',
                InputOption::VALUE_OPTIONAL,
                'Sync selected servers, comma seperated. \'s1,s2\'.',
                ''
            )
            ->addOption(
                'ignore-date',
                null,
                InputOption::VALUE_NONE,
                'Ignore date comparison, and update server watched state to match database.'
            )
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
                throw new RuntimeException('Unable to read data given config.');
            }
            $custom = true;
            Config::save('servers', Yaml::parseFile($config));
        } else {
            $custom = false;
            $config = Config::get('path') . '/config/servers.yaml';
        }

        $list = [];
        $logger = null;
        $serversFilter = (string)$input->getOption('servers-filter');
        $selected = explode(',', $serversFilter);
        $isCustom = !empty($serversFilter) && count($selected) >= 1;
        $supported = Config::get('supported', []);

        if ($input->getOption('redirect-logger') || $input->getOption('memory-usage')) {
            $logger = new CliLogger($output, (bool)$input->getOption('memory-usage'));
        }

        if (null !== $logger) {
            $this->logger = $logger;
            $this->mapper->setLogger($logger);
        }

        $this->logger->info(sprintf('Running WatchState Version \'%s\'.', Config::get('version')));

        foreach (Config::get('servers', []) as $name => $server) {
            $type = strtolower(ag($server, 'type', 'unknown'));

            if ($isCustom && !in_array($name, $selected, true)) {
                $this->logger->info(sprintf('%s: Ignoring this server as requested by --servers-filter.', $name));
                continue;
            }

            if (true !== ag($server, 'export.enabled')) {
                $this->logger->info(sprintf('%s: Ignoring this as requested by user config.', $name));
                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->error(
                    sprintf(
                        '%s: Unexpected backend type. Was expecting \'%s\', but got \'%s\' instead.',
                        $name,
                        implode(', ', array_keys($supported)),
                        $type
                    )
                );
                return self::FAILURE;
            }

            if (null === ag($server, 'url')) {
                $this->logger->error(sprintf('%s: Backend does not have valid URL.', $name));
                return self::FAILURE;
            }

            $server['name'] = $name;
            $list[$name] = $server;
        }

        if (empty($list)) {
            $output->writeln(
                sprintf(
                    '<error>%s</error>',
                    $isCustom ? '[-s, --servers-filter] Filter did not match any server.' : 'No servers were found.'
                )
            );
            return self::FAILURE;
        }

        if (count($list) >= 1) {
            $this->logger->info('Preloading all mapper data.');
            $this->mapper->loadData();
            $this->logger->info('Finished preloading mapper data.');
        }

        if ($this->storage instanceof PDOAdapter) {
            $this->storage->singleTransaction();
        }

        $requests = [];

        foreach ($list as $name => &$server) {
            Data::addBucket($name);

            $opts = ag($server, 'options', []);

            if ($input->getOption('ignore-date')) {
                $opts[Options::IGNORE_DATE] = true;
            }

            if ($input->getOption('proxy')) {
                $opts['client']['proxy'] = $input->getOption('proxy');
            }

            if ($input->getOption('no-proxy')) {
                $opts['client']['no_proxy'] = $input->getOption('no-proxy');
            }

            if ($input->getOption('timeout')) {
                $opts['client']['timeout'] = $input->getOption('timeout');
            }

            $server['options'] = $opts;
            $server['class'] = makeServer($server, $name);

            if (null !== $logger) {
                $server['class'] = $server['class']->setLogger($logger);
            }

            $after = true === $input->getOption('force-full') ? null : ag($server, 'export.lastSync', null);

            if (null === $after) {
                $this->logger->notice(sprintf('%s: Exporting all local play state to this backend.', $name));
            } else {
                $after = makeDate($after);
                $this->logger->notice(
                    sprintf('%s: Exporting play state changes since \'%s\' to this backend.', $name, $after)
                );
            }

            array_push($requests, ...$server['class']->export($this->mapper, $after));

            if (true === (bool)Data::get(sprintf('%s.no_export_update', $name))) {
                $this->logger->notice(
                    sprintf('%s: Not updating last export date. Backend reported an error.', $name)
                );
            } else {
                Config::save(sprintf('servers.%s.export.lastSync', $name), time());
            }
        }

        unset($server);

        $this->logger->notice(sprintf('HTTP: Waiting on \'%d\' state comparison requests.', count($requests)));

        foreach ($requests as $response) {
            $requestData = $response->getInfo('user_data');
            try {
                $requestData['ok']($response);
            } catch (Throwable $e) {
                $requestData['error']($e);
            }
        }

        $this->logger->notice(sprintf('HTTP: Finished processing \'%d\' state comparison requests.', count($requests)));

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
            $this->logger->notice(sprintf('HTTP: Finished Processing \'%d\' state change requests.', $total));
        } else {
            $this->logger->notice('No state changes detected.');
        }

        foreach ($list as $server) {
            if (null === ($name = ag($server, 'name'))) {
                continue;
            }

            Config::save(
                sprintf('servers.%s.persist', $name),
                $server['class']->getPersist()
            );
        }

        if (false === $custom && is_writable(dirname($config))) {
            copy($config, $config . '.bak');
        }

        file_put_contents($config, Yaml::dump(Config::get('servers', []), 8, 2));

        return self::SUCCESS;
    }
}
