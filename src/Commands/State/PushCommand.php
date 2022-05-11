<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Entity\StateInterface;
use App\Libs\Extends\CliLogger;
use App\Libs\Options;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class PushCommand extends Command
{
    public const TASK_NAME = 'push';

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
        $this->setName('state:push')
            ->setDescription('Push queued state change events.')
            ->addOption('redirect-logger', 'r', InputOption::VALUE_NONE, 'Redirect logger to stdout.')
            ->addOption('memory-usage', 'm', InputOption::VALUE_NONE, 'Show memory usage.')
            ->addOption('keep-queue', null, InputOption::VALUE_NONE, 'Do not empty queue after run is successful.')
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
                'ignore-date',
                null,
                InputOption::VALUE_NONE,
                'Ignore date comparison. Push db state to the server regardless of date.'
            )
            ->addOption('queue-show', null, InputOption::VALUE_NONE, 'Show queued items.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws InvalidArgumentException
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output);
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function process(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->cache->has('queue')) {
            $output->writeln('<info>No items in the queue.</info>', OutputInterface::VERBOSITY_DEBUG);
            return self::SUCCESS;
        }

        $entities = [];

        foreach ($this->cache->get('queue') as $entityId => $entityData) {
            $entities[$entityId] = Container::get(StateInterface::class)::fromArray($entityData);
        }

        if (empty($entities)) {
            $this->cache->delete('queue');
            $output->writeln('<info>No items in the queued.</info>', OutputInterface::VERBOSITY_DEBUG);
            return self::SUCCESS;
        }

        if ($input->getOption('queue-show')) {
            $rows = [];

            $x = 0;
            $count = count($entities);

            foreach ($entities as $entity) {
                $x++;

                $rows[] = [
                    $entity->getName(),
                    $entity->isWatched() ? 'Yes' : 'No',
                    $entity->via ?? '??',
                    makeDate($entity->updated),
                ];

                if ($x < $count) {
                    $rows[] = new TableSeparator();
                }
            }

            (new Table($output))->setHeaders(['Media Title', 'Played', 'Via', 'Record Date']
            )->setStyle('box')->setRows($rows)->render();

            return self::SUCCESS;
        }

        $list = [];
        $supported = Config::get('supported', []);

        foreach (Config::get('servers', []) as $serverName => $server) {
            $type = strtolower(ag($server, 'type', 'unknown'));

            if (true !== (bool)ag($server, 'webhook.push')) {
                $output->writeln(
                    sprintf('<error>Ignoring \'%s\' as requested by user config option.</error>', $serverName),
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
            throw new RuntimeException('No servers were found.');
        }

        $logger = null;

        if ($input->getOption('redirect-logger') || $input->getOption('memory-usage')) {
            $logger = new CliLogger($output, (bool)$input->getOption('memory-usage'));
        }

        $requests = [];

        if (null !== $logger) {
            $this->logger = $logger;
        }

        foreach ($list as $name => &$server) {
            Data::addBucket($name);
            $opts = ag($server, 'server.options', []);

            if ($input->getOption('ignore-date')) {
                $opts[Options::IGNORE_DATE] = true;
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

            array_push($requests, ...$server['class']->push($entities));
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
                    $this->logger->notice(
                        sprintf(
                            '%s Processed \'%s\'. Set remote state to \'%s\'.',
                            ag($requestData, 'server', '??'),
                            ag($requestData, 'itemName', '??'),
                            ag($requestData, 'state', '??'),
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

            Config::save(sprintf('servers.%s.persist', $name), $server['class']->getPersist());
        }

        $config = Config::get('path') . '/config/servers.yaml';

        if (is_writable(dirname($config))) {
            copy($config, $config . '.bak');
        }

        file_put_contents($config, Yaml::dump(Config::get('servers', []), 8, 2));

        if (!$input->getOption('keep-queue')) {
            $this->cache->delete('queue');
        }

        return self::SUCCESS;
    }
}
