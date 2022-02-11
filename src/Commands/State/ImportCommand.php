<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Entity\StateEntity;
use App\Libs\Extends\CliLogger;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Servers\ServerInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Uri;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class ImportCommand extends Command
{
    public function __construct(private ImportInterface $mapper, private LoggerInterface $logger)
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('state:import')
            ->setDescription('Import watch state from servers.')
            ->addOption('read-mapper', null, InputOption::VALUE_OPTIONAL, 'Configured Mapper.', $this->mapper::class)
            ->addOption('redirect-logger', 'r', InputOption::VALUE_NONE, 'Redirect logger to stdout.')
            ->addOption('memory-usage', 'm', InputOption::VALUE_NONE, 'Show memory usage.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full import.')
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

            array_push($promises, ...$class->pull($this->mapper, $after));

            if (true === Data::get(sprintf('%s.no_import_update', $name))) {
                $this->logger->notice(
                    sprintf('Not updating \'%s\' last sync time as the server reported an error.', $name)
                );
            } else {
                Config::save(sprintf('servers.%s.import.lastSync', $name), time());
            }
        }

        $this->logger->notice(sprintf('Waiting on (%d) HTTP Requests.', count($promises)));
        Utils::settle($promises)->wait();
        $this->logger->notice(sprintf('Finished waiting on (%d) HTTP Requests.', count($promises)));

        $this->logger->notice(sprintf('Committing (%d) Changes.', count($this->mapper)));
        $operations = $this->mapper->commit();
        $this->logger->notice('Finished Committing the changes.');

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
                    $operations[StateEntity::TYPE_MOVIE]['added'] ?? 0,
                    $operations[StateEntity::TYPE_MOVIE]['updated'] ?? 0,
                    $operations[StateEntity::TYPE_MOVIE]['failed'] ?? 0,
                    $operations[StateEntity::TYPE_EPISODE]['added'] ?? 0,
                    $operations[StateEntity::TYPE_EPISODE]['updated'] ?? 0,
                    $operations[StateEntity::TYPE_EPISODE]['failed'] ?? 0,
                )
            );
        }

        // -- Update Server.yaml with new lastSync date.
        file_put_contents(
            Config::get('path') . DS . 'config' . DS . 'servers.yaml',
            Yaml::dump(Config::get('servers', []), 8, 2)
        );

        return self::SUCCESS;
    }
}
