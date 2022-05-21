<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Entity\StateInterface;
use App\Libs\Options;
use App\Libs\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class PushCommand extends Command
{
    public const TASK_NAME = 'push';

    public function __construct(
        private LoggerInterface $logger,
        private CacheInterface $cache,
        private StorageInterface $storage,
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('state:push')
            ->setDescription('Push queued webhook queued events.')
            ->addOption('keep-queue', null, InputOption::VALUE_NONE, 'Do not empty queue after run is successful.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit changes to backends.')
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
            );
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
            $output->writeln('<info>No items in the queue.</info>', OutputInterface::VERBOSITY_VERY_VERBOSE);
            return self::SUCCESS;
        }

        $entities = $items = [];

        foreach ($this->cache->get('queue', []) as $item) {
            $items[] = Container::get(StateInterface::class)::fromArray($item);
        }

        if (!empty($items)) {
            foreach ($this->storage->find(...$items) as $item) {
                $entities[$item->id] = $item;
            }
        }

        $items = null;

        if (empty($entities)) {
            $this->cache->delete('queue');
            $output->writeln('<info>No items in the queue.</info>', OutputInterface::VERBOSITY_VERY_VERBOSE);
            return self::SUCCESS;
        }

        $this->logger->info(sprintf('Using WatchState Version - \'%s\'.', getAppVersion()));

        $list = [];
        $supported = Config::get('supported', []);

        foreach (Config::get('servers', []) as $serverName => $server) {
            $type = strtolower(ag($server, 'type', 'unknown'));

            if (true !== (bool)ag($server, 'webhook.push')) {
                $this->logger->info(sprintf('%s: Ignoring backend as requested by user config.', $serverName));
                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->error(
                    sprintf(
                        '%s: Unexpected type. Expecting \'%s\', but got \'%s\'.',
                        $serverName,
                        implode(', ', array_keys($supported)),
                        $type
                    )
                );
                continue;
            }

            if (null === ($url = ag($server, 'url')) || false === filter_var($url, FILTER_VALIDATE_URL)) {
                $this->logger->error(
                    sprintf('%s: Backend does not have valid url.', $serverName),
                    [
                        'url' => $url ?? 'None'
                    ]
                );
                return self::FAILURE;
            }

            $server['name'] = $serverName;
            $list[$serverName] = $server;
        }

        if (empty($list)) {
            $output->writeln('No Backends have push via webhook enabled.');
            return self::FAILURE;
        }

        $requests = [];

        foreach ($list as $name => &$server) {
            Data::addBucket((string)$name);
            $opts = ag($server, 'options', []);

            if ($input->getOption('ignore-date')) {
                $opts[Options::IGNORE_DATE] = true;
            }

            if ($input->getOption('dry-run')) {
                $opts[Options::DRY_RUN] = true;
            }

            if ($input->getOption('proxy')) {
                $opts['client']['proxy'] = $input->getOption('proxy');
            }

            if ($input->getOption('no-proxy')) {
                $opts['client']['no_proxy'] = $input->getOption('no-proxy');
            }

            $server['options'] = $opts;
            $server['class'] = makeServer($server, $name);

            array_push($requests, ...$server['class']->push($entities));
        }

        unset($server);

        $total = count($requests);

        if ($total >= 1) {
            $this->logger->notice(sprintf('HTTP: Waiting on \'%d\' change play state requests.', $total));
            foreach ($requests as $response) {
                $requestData = $response->getInfo('user_data');
                try {
                    if (200 !== $response->getStatusCode()) {
                        $this->logger->error(
                            sprintf(
                                '%s: Request to change \'%s\' state responded with unexpected http status code \'%d\'.',
                                ag($requestData, 'server', '??'),
                                ag($requestData, 'itemName', '??'),
                                $response->getStatusCode()
                            )
                        );
                        continue;
                    }

                    $this->logger->notice(
                        sprintf(
                            '%s: Marking \'%s\' as \'%s\'.',
                            ag($requestData, 'server', '??'),
                            ag($requestData, 'itemName', '??'),
                            ag($requestData, 'state', '??'),
                        )
                    );
                } catch (ExceptionInterface $e) {
                    $this->logger->error($e->getMessage());
                }
            }
            $this->logger->notice(sprintf('HTTP: Finished processing \'%d\' change play state requests.', $total));
        } else {
            $this->logger->notice('No play state change detected.');
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

        if (!$input->getOption('keep-queue') && !$input->getOption('dry-run')) {
            $this->cache->delete('queue');
        }

        return self::SUCCESS;
    }
}
