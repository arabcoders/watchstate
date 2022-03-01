<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Entity\StateInterface;
use App\Libs\Extends\CliLogger;
use App\Libs\Servers\ServerInterface;
use Nyholm\Psr7\Uri;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class QueueCommand extends Command
{
    public function __construct(
        private LoggerInterface $logger,
        private CacheInterface $cache
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('webhooks:queued')
            ->setDescription('Push Webhook Queued watchstate events.')
            ->addOption('redirect-logger', 'r', InputOption::VALUE_NONE, 'Redirect logger to stdout.')
            ->addOption('memory-usage', 'm', InputOption::VALUE_NONE, 'Show memory usage.')
            ->addOption('keep-queue', null, InputOption::VALUE_NONE, 'Do not empty queue after run is done.')
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
            ->addOption('use-config', null, InputOption::VALUE_REQUIRED, 'Use different servers.yaml.');
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->cache->has('queue')) {
            $output->writeln('<info>No Items was queued.</info>', OutputInterface::VERBOSITY_DEBUG);
            return self::SUCCESS;
        }

        $entities = [];

        foreach ($this->cache->get('queue') as $entityId => $entityData) {
            $entities[$entityId] = Container::get(StateInterface::class)::fromArray($entityData);
        }

        if (empty($entities)) {
            $this->cache->delete('queue');
            $output->writeln('<info>No Items was queued.</info>', OutputInterface::VERBOSITY_DEBUG);
            return self::SUCCESS;
        }

        $list = [];
        $serversFilter = (string)$input->getOption('servers-filter');
        $selected = explode(',', $serversFilter);
        $isCustom = !empty($serversFilter) && count($selected) >= 1;
        $supported = Config::get('supported', []);

        foreach (Config::get('servers', []) as $serverName => $server) {
            $type = strtolower(ag($server, 'type', 'unknown'));

            if ($isCustom && !in_array($serverName, $selected, true)) {
                $output->writeln(
                    sprintf(
                        '<error>Ignoring \'%s\' as requested by [-s, --servers-filter] filter.</error>',
                        $serverName
                    ),
                    OutputInterface::VERBOSITY_VERBOSE
                );
                continue;
            }

            if (!$isCustom && true !== ag($server, 'export.webhook')) {
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

            $list[] = [
                'name' => $serverName,
                'kind' => $supported[$type],
                'server' => $server,
            ];
        }

        if (empty($list)) {
            throw new RuntimeException(
                $isCustom ? '--servers-filter/-s did not return any server.' : 'No servers were found.'
            );
        }

        $logger = null;

        if ($input->getOption('redirect-logger') || $input->getOption('memory-usage')) {
            $logger = new CliLogger($output, (bool)$input->getOption('memory-usage'));
        }

        $requests = [];

        if (null !== $logger) {
            $this->logger = $logger;
        }

        foreach ($list as &$server) {
            $name = ag($server, 'name');
            Data::addBucket($name);

            $class = Container::getNew(ag($server, 'kind'));
            assert($class instanceof ServerInterface);

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

            $class = $class->setUp(
                name:    $name,
                url:     new Uri(ag($server, 'server.url')),
                token:   ag($server, 'server.token', null),
                userId:  ag($server, 'server.user', null),
                persist: ag($server, 'server.persist', []),
                options: $opts
            );

            $server['class'] = $class;

            if (null !== $logger) {
                $class = $class->setLogger($logger);
            }

            array_push($requests, ...$class->pushStates($entities));
        }

        unset($server);

        $total = count($requests);

        if ($total >= 1) {
            $this->logger->notice(sprintf('Waiting on (%d) (Stats Change) Requests.', $total));
            foreach ($requests as $response) {
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

        // -- Update Server.yaml
        file_put_contents(
            Config::get('path') . '/config/servers.yaml',
            Yaml::dump(Config::get('servers', []), 8, 2)
        );

        if (!$input->getOption('keep-queue')) {
            $this->cache->delete('queue');
        }

        return self::SUCCESS;
    }
}
