<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Extends\CliLogger;
use App\Libs\Extends\Request;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Servers\ServerInterface;
use Generator;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

use function ag;
use function makeDate;

class ExportCommand extends Command
{
    public function __construct(private ExportInterface $mapper, private Request $http, private LoggerInterface $logger)
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('state:export')
            ->setDescription('Export watch state to servers.')
            ->addOption(
                'read-mapper',
                null,
                InputOption::VALUE_OPTIONAL,
                'Shows what kind of mapper configured.',
                $this->mapper::class
            )
            ->addOption('redirect-logger', 'r', InputOption::VALUE_NONE, 'Redirect logger to stderr.')
            ->addOption('memory-usage', 'm', InputOption::VALUE_NONE, 'Display memory usage.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full export.')
            ->addOption(
                'concurrency',
                null,
                InputOption::VALUE_OPTIONAL,
                'How many Requests to send.',
                (int)Config::get('request.export.concurrency')
            )
            ->addOption(
                'servers-filter',
                's',
                InputOption::VALUE_OPTIONAL,
                'Sync selected servers, comma seperated. \'s1,s2\'.',
                ''
            )
            ->addOption('stats-show', null, InputOption::VALUE_NONE, 'Show final status.')
            ->addOption(
                'stats-filter',
                null,
                InputOption::VALUE_OPTIONAL,
                'Filter final status output e.g. (servername.key)',
                null
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
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

            if (true !== ag($server, 'export.enabled')) {
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
                $isCustom ? '--servers-filter/-s did not return any server.' : 'No server were found.'
            );
        }

        $logger = null;

        if ($input->getOption('redirect-logger') || $input->getOption('memory-usage')) {
            $logger = new CliLogger($output, (bool)$input->getOption('memory-usage'));
        }

        $promises = [];

        if (count($list) >= 1) {
            $this->mapper->loadData();
        }

        if (null !== $logger) {
            $this->logger = $logger;
            $this->mapper->setLogger($logger);
        }

        foreach ($list as $server) {
            $name = ag($server, 'name');
            Data::addBucket($name);

            $class = Container::get(ag($server, 'kind'));
            assert($class instanceof ServerInterface);

            $class = $class->setUp(
                $name,
                new Uri(ag($server, 'server.url')),
                ag($server, 'server.token', null),
                ag($server, 'server.options', [])
            );

            if (null !== $logger) {
                $class = $class->setLogger($logger);
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

            array_push($promises, ...$class->push($this->mapper, $after));

            if (true === Data::get(sprintf('%s.no_export_update', $name))) {
                $this->logger->notice(
                    sprintf('Not updating \'%s\' export date, as the server reported an error.', $name)
                );
            } else {
                Config::save(sprintf('servers.%s.export.lastSync', $name), time());
            }
        }

        $this->logger->notice(sprintf('Waiting on (%d) (Compare State) Requests.', count($promises)));
        Utils::settle($promises)->wait();
        $this->logger->notice(sprintf('Finished waiting on (%d) Requests.', count($promises)));

        $changes = $this->mapper->getQueue();

        if (empty($changes)) {
            $this->logger->notice('No State change detected.');
            return self::SUCCESS;
        }

        $pool = new Pool(
            $this->http,
            (function () use ($changes): Generator {
                foreach ($changes as $request) {
                    yield $request;
                }
            })(),
            [
                'concurrency' => $input->getOption('concurrency'),
                'fulfilled' => function (ResponseInterface $response) {
                },
                'rejected' => function (Throwable $reason) {
                    $this->logger->error($reason->getMessage());
                },
            ]
        );

        $this->logger->notice(sprintf('Waiting on (%d) (Stats Change) Requests.', count($changes)));
        $pool->promise()->wait();
        $this->logger->notice(sprintf('Finished waiting on (%d) Requests.', count($changes)));

        // -- Update Server.yaml with new lastSync date.
        file_put_contents(
            Config::get('path') . DS . 'config' . DS . 'servers.yaml',
            Yaml::dump(Config::get('servers', []), 8, 2)
        );

        return self::SUCCESS;
    }
}
