<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Data;
use App\Libs\Entity\StateInterface;
use App\Libs\Extends\CliLogger;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Servers\ServerInterface;
use App\Libs\Storage\PDO\PDOAdapter;
use App\Libs\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ImportCommand extends Command
{
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
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full import.')
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
                'import-unwatched',
                null,
                InputOption::VALUE_NONE,
                'Import unwatched state (note: It Will set items to unwatched if the server has newer date on items)'
            )
            ->addOption('use-config', null, InputOption::VALUE_REQUIRED, 'Use different servers.yaml.')
            ->addOption('stats-show', null, InputOption::VALUE_NONE, 'Show final status.')
            ->addOption(
                'stats-filter',
                null,
                InputOption::VALUE_OPTIONAL,
                'Filter final status output e.g. (servername.key)',
                null
            )
            ->addOption(
                'mapper-class',
                null,
                InputOption::VALUE_OPTIONAL,
                'Configured Mapper.',
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
        $serversFilter = (string)$input->getOption('servers-filter');
        $selected = explode(',', $serversFilter);
        $isCustom = !empty($serversFilter) && count($selected) >= 1;
        $supported = Config::get('supported', []);

        foreach (Config::get('servers', []) as $serverName => $server) {
            $type = strtolower(ag($server, 'type', 'unknown'));

            if ($isCustom && !in_array($serverName, $selected, true)) {
                continue;
            }

            if (true !== ag($server, 'import.enabled')) {
                $output->writeln(
                    sprintf('<error>Ignoring \'%s\' as requested by \'servers.yaml\'.</error>', $serverName),
                    OutputInterface::VERBOSITY_VERBOSE
                );
                continue;
            }

            if (!isset($supported[$type])) {
                $output->writeln(
                    sprintf(
                        '<error>Server \'%s\' Used Unsupported type. Expecting one of \'%s\' but got \'%s\' instead.</error>',
                        $serverName,
                        implode(', ', array_keys($supported)),
                        $type
                    )
                );
                return self::FAILURE;
            }

            if (null === ag($server, 'url')) {
                $output->writeln(sprintf('<error>Server \'%s\' has no url.</error>', $serverName));
                return self::FAILURE;
            }

            $server['name'] = $serverName;
            $list[$serverName] = $server;
        }

        if (empty($list)) {
            throw new RuntimeException(
                $isCustom ? '--servers-filter/-s did not return any server.' : 'No server were found.'
            );
        }

        $logger = null;

        if ($input->getOption('redirect-logger') || $input->getOption('memory-usage')) {
            $logger = new CliLogger($output, (bool)$input->getOption('memory-usage'));
        }

        /** @var array<array-key,ResponseInterface> $queue */
        $queue = [];

        if (null !== $logger) {
            $this->logger = $logger;
            $this->mapper->setLogger($logger);
        }

        if (count($list) >= 1 && $input->getOption('mapper-preload')) {
            $this->logger->info('Preloading all mapper data.');
            $this->mapper->loadData();
            $this->logger->info('Finished preloading mapper data.');
        }

        if (($this->storage instanceof PDOAdapter) && $input->getOption('storage-pdo-single-transaction')) {
            $this->storage->singleTransaction();
        }

        foreach ($list as $name => &$server) {
            Data::addBucket($name);

            $opts = ag($server, 'server.options', []);

            if ($input->getOption('import-unwatched')) {
                $opts[ServerInterface::OPT_IMPORT_UNWATCHED] = true;
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

        if ($total >= 1) {
            $this->logger->notice('Finished Committing the changes.');
        }

        if ($input->getOption('stats-show')) {
            Data::add('operations', 'stats', $operations);
            $output->writeln(
                json_encode(
                    Data::get($input->getOption('stats-filter')),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ),
                OutputInterface::OUTPUT_NORMAL
            );
        } else {
            $output->writeln(
                sprintf(
                    '<info>Movies [A: %d - U: %d - F: %d] - Episodes [A: %d - U: %d - F: %d]</info>',
                    $operations[StateInterface::TYPE_MOVIE]['added'] ?? 0,
                    $operations[StateInterface::TYPE_MOVIE]['updated'] ?? 0,
                    $operations[StateInterface::TYPE_MOVIE]['failed'] ?? 0,
                    $operations[StateInterface::TYPE_EPISODE]['added'] ?? 0,
                    $operations[StateInterface::TYPE_EPISODE]['updated'] ?? 0,
                    $operations[StateInterface::TYPE_EPISODE]['failed'] ?? 0,
                )
            );
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

        // -- Update Server.yaml with new lastSync date.
        file_put_contents(
            $newConfig ?? Config::get('path') . '/config/servers.yaml',
            Yaml::dump(Config::get('servers', []), 8, 2)
        );

        return self::SUCCESS;
    }
}
