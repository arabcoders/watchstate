<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Entity\StateInterface;
use App\Libs\Extends\CliLogger;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Storage\PDO\PDOAdapter;
use App\Libs\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ImportCommand extends Command
{
    public const TASK_NAME = 'import';

    public function __construct(
        private StorageInterface $storage,
        private ImportInterface $mapper,
        private LoggerInterface $logger
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('state:import')
            ->setDescription('Import watch state from servers.')
            ->addOption('redirect-logger', 'r', InputOption::VALUE_NONE, 'Redirect logger to stdout.')
            ->addOption('memory-usage', 'm', InputOption::VALUE_NONE, 'Show memory usage.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full import. (ignore lastSync date)')
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
                'Set request timeout in seconds for each request.'
            )
            ->addOption(
                'servers-filter',
                's',
                InputOption::VALUE_OPTIONAL,
                'Sync selected servers, comma seperated. \'s1,s2\'.',
                ''
            )
            ->addOption(
                'import-unwatched',
                null,
                InputOption::VALUE_NONE,
                '--DEPRECATED-- will be removed in v1.x. We import the item regardless of watched/unwatched state.'
            )
            ->addOption('stats-show', null, InputOption::VALUE_NONE, 'Show final status.')
            ->addOption(
                'stats-filter',
                null,
                InputOption::VALUE_OPTIONAL,
                'Filter final status output e.g. (servername.key)',
                null
            )
            ->addOption(
                'mapper-direct',
                null,
                InputOption::VALUE_NONE,
                'Uses less memory. However, it\'s significantly slower then default mapper.'
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
            Config::save('servers', Yaml::parseFile($config));
            $custom = true;
        } else {
            $custom = false;
            $config = Config::get('path') . '/config/servers.yaml';
        }

        if ($input->getOption('mapper-direct')) {
            $this->mapper = Container::get(DirectMapper::class);
        }

        $list = [];
        $serversFilter = (string)$input->getOption('servers-filter');
        $selected = explode(',', $serversFilter);
        $isCustom = !empty($serversFilter) && count($selected) >= 1;
        $supported = Config::get('supported', []);

        $logger = null;

        if ($input->getOption('redirect-logger') || $input->getOption('memory-usage')) {
            $logger = new CliLogger($output, (bool)$input->getOption('memory-usage'));
        }

        if (null !== $logger) {
            $this->logger = $logger;
            $this->mapper->setLogger($logger);
        }

        foreach (Config::get('servers', []) as $serverName => $server) {
            $type = strtolower(ag($server, 'type', 'unknown'));

            if ($isCustom && !in_array($serverName, $selected, true)) {
                $this->logger->info(sprintf('Ignoring \'%s\' as requested by --servers-filter.', $serverName));
                continue;
            }

            if (true !== ag($server, 'import.enabled')) {
                $this->logger->info(sprintf('Ignoring \'%s\' as requested by \'%s\'.', $serverName, $config));
                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->error(
                    sprintf(
                        'Unexpected type for server \'%s\'. Was Expecting one of [%s], but got \'%s\' instead.',
                        $serverName,
                        implode('|', array_keys($supported)),
                        $type
                    )
                );

                return self::FAILURE;
            }

            if (null === ag($server, 'url')) {
                $this->logger->error(sprintf('Server \'%s\' has no URL.', $serverName));
                return self::FAILURE;
            }

            $server['name'] = $serverName;
            $list[$serverName] = $server;
        }

        if (empty($list)) {
            $output->writeln(
                sprintf(
                    '<error>%s</error>',
                    $isCustom ? '--servers-filter/-s did not return any servers.' : 'No servers were found.'
                )
            );
            return self::FAILURE;
        }

        /** @var array<array-key,ResponseInterface> $queue */
        $queue = [];

        if (count($list) >= 1 && !$input->getOption('mapper-direct')) {
            $this->logger->info('Preloading all mapper data.');
            $this->mapper->loadData();
            $this->logger->info('Finished preloading mapper data.');
        }

        if ($this->storage instanceof PDOAdapter) {
            $this->storage->singleTransaction();
        }

        foreach ($list as $name => &$server) {
            Data::addBucket($name);

            $opts = ag($server, 'options', []);

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

            $after = true === $input->getOption('force-full') ? null : ag($server, 'import.lastSync', null);

            if (null === $after) {
                $this->logger->notice(
                    sprintf('Importing \'%s\' play state changes since beginning.', $name)
                );
            } else {
                $after = makeDate($after);
                $this->logger->notice(
                    sprintf('Importing \'%s\' play state changes since \'%s\'.', $name, $after)
                );
            }

            array_push($queue, ...$server['class']->pull($this->mapper, $after));

            if (true === Data::get(sprintf('%s.no_import_update', $name))) {
                $this->logger->notice(
                    sprintf('Not updating \'%s\' last sync time as the server reported an error.', $name)
                );
            } else {
                Config::save(sprintf('servers.%s.import.lastSync', $name), time());
            }
        }

        unset($server);

        $this->logger->notice(sprintf('Waiting on (%d) HTTP Requests.', count($queue)));

        foreach ($queue as $_key => $response) {
            $requestData = $response->getInfo('user_data');
            try {
                if (200 === $response->getStatusCode()) {
                    $requestData['ok']($response);
                } else {
                    $requestData['error']($response);
                }
            } catch (ExceptionInterface $e) {
                $requestData['error']($e);
            }

            $queue[$_key] = null;

            gc_collect_cycles();
        }

        unset($queue);
        $this->logger->notice('Finished waiting HTTP Requests.');

        $total = count($this->mapper);

        if ($total >= 1) {
            $this->logger->notice(sprintf('Committing (%d) Changes.', $total));
        }

        $operations = $this->mapper->commit();

        if ($input->getOption('stats-show')) {
            Data::add('operations', 'stats', $operations);
            $output->writeln(
                Yaml::dump(Data::get($input->getOption('stats-filter'), []), 8, 2)
            );
        } else {
            $a = [
                [
                    'Type' => ucfirst(StateInterface::TYPE_MOVIE),
                    'Added' => $operations[StateInterface::TYPE_MOVIE]['added'] ?? 'None',
                    'Updated' => $operations[StateInterface::TYPE_MOVIE]['updated'] ?? 'None',
                    'Failed' => $operations[StateInterface::TYPE_MOVIE]['failed'] ?? 'None',
                ],
                new TableSeparator(),
                [
                    'Type' => ucfirst(StateInterface::TYPE_EPISODE),
                    'Added' => $operations[StateInterface::TYPE_EPISODE]['added'] ?? 'None',
                    'Updated' => $operations[StateInterface::TYPE_EPISODE]['updated'] ?? 'None',
                    'Failed' => $operations[StateInterface::TYPE_EPISODE]['failed'] ?? 'None',
                ],
            ];

            (new Table($output))->setHeaders(array_keys($a[0]))->setStyle('box')->setRows(array_values($a))->render();
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
