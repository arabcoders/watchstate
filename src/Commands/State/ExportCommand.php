<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Data;
use App\Libs\Extends\CliLogger;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Servers\ServerInterface;
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
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full export.')
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
            ->addOption('use-config', null, InputOption::VALUE_REQUIRED, 'Use different servers.yaml.')
            ->addOption(
                'mapper-class',
                null,
                InputOption::VALUE_OPTIONAL,
                'Configured mapper.',
                afterLast($this->mapper::class, '\\')
            )
            ->addOption('mapper-preload', null, InputOption::VALUE_NONE, 'Preload Mapper database into memory.')
            ->addOption(
                'storage-pdo-single-transaction',
                null,
                InputOption::VALUE_NONE,
                'Set Single transaction mode for PDO driver.'
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($newConfig = $input->getOption('use-config'))) {
            if (!is_string($newConfig) || !is_file($newConfig) || !is_readable($newConfig)) {
                throw new RuntimeException('Unable to read data given config.');
            }
            Config::save('servers', Yaml::parseFile($newConfig));
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

        foreach (Config::get('servers', []) as $name => $server) {
            $type = strtolower(ag($server, 'type', 'unknown'));

            if ($isCustom && !in_array($name, $selected, true)) {
                $this->logger->info(sprintf('Ignoring \'%s\' as requested by --servers-filter.', $name));
                continue;
            }

            if (true !== ag($server, 'export.enabled')) {
                $this->logger->info(sprintf('Ignoring \'%s\' as requested by \'servers.yaml\'.', $name));
                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->error(
                    sprintf(
                        'Unexpected type for server \'%s\'. Was Expecting one of [%s], but got \'%s\' instead.',
                        $name,
                        implode('|', array_keys($supported)),
                        $type
                    )
                );
                return self::FAILURE;
            }

            if (null === ag($server, 'url')) {
                $this->logger->error(sprintf('Server \'%s\' has no URL.', $name));
                return self::FAILURE;
            }

            $server['name'] = $name;
            $list[$name] = $server;
        }

        if (empty($list)) {
            throw new RuntimeException(
                $isCustom ? '[-s, --servers-filter] Filter did not match any server.' : 'No server were found.'
            );
        }

        if (count($list) >= 1 && $input->getOption('mapper-preload')) {
            $this->logger->info('Preloading all mapper data.');
            $this->mapper->loadData();
            $this->logger->info('Finished preloading mapper data.');
        }

        if (($this->storage instanceof PDOAdapter) && $input->getOption('storage-pdo-single-transaction')) {
            $this->storage->singleTransaction();
        }

        $requests = [];

        foreach ($list as $name => &$server) {
            Data::addBucket($name);

            $opts = ag($server, 'server.options', []);

            if ($input->getOption('ignore-date')) {
                $opts[ServerInterface::OPT_EXPORT_IGNORE_DATE] = true;
            }

            if ($input->getOption('proxy')) {
                $opts['client']['proxy'] = $input->getOption('proxy');
            }

            if ($input->getOption('no-proxy')) {
                $opts['client']['no_proxy'] = $input->getOption('no-proxy');
            }

            $server['options'] = $opts;
            $server['class'] = makeServer($server, $name);

            if (null !== $logger) {
                $server['class'] = $server['class']->setLogger($logger);
            }

            $after = $input->getOption('force-full') ? null : ag($server, 'server.import.lastSync', null);

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

            array_push($requests, ...$server['class']->export($this->mapper, $after));

            if (true === Data::get(sprintf('%s.no_export_update', $name))) {
                $this->logger->notice(
                    sprintf('Not updating \'%s\' export date, as the server reported an error.', $name)
                );
            } else {
                Config::save(sprintf('servers.%s.export.lastSync', $name), time());
            }
        }

        unset($server);

        $this->logger->notice(sprintf('Waiting on (%d) (Compare State) Requests.', count($requests)));

        foreach ($requests as $response) {
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
        }

        $this->logger->notice(sprintf('Finished waiting on (%d) Requests.', count($requests)));

        $changes = $this->mapper->getQueue();
        $total = count($changes);

        if ($total >= 1) {
            $this->logger->notice(sprintf('Waiting on (%d) (Stats Change) Requests.', $total));
            foreach ($changes as $response) {
                $requestData = $response->getInfo('user_data');
                try {
                    if (200 !== $response->getStatusCode()) {
                        throw new ServerException($response);
                    }
                    $this->logger->debug(
                        sprintf(
                            'Processed: State (%s) - %s',
                            ag($requestData, 'state', '??'),
                            ag($requestData, 'itemName', '??'),
                        )
                    );
                } catch (ExceptionInterface $e) {
                    $this->logger->error($e->getMessage());
                }
            }
            $this->logger->notice(sprintf('Finished waiting on (%d) Requests.', $total));
        } else {
            $this->logger->notice('No state change detected.');
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

        file_put_contents(
            $newConfig ?? Config::get('path') . '/config/servers.yaml',
            Yaml::dump(Config::get('servers', []), 8, 2)
        );

        return self::SUCCESS;
    }
}
