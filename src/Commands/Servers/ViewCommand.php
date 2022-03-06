<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class ViewCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('servers:view')
            ->setDescription('View Server/s settings.')
            ->addOption(
                'servers-filter',
                's',
                InputOption::VALUE_OPTIONAL,
                'View selected servers, comma seperated. \'s1,s2\'.',
                ''
            )
            ->addOption('use-config', null, InputOption::VALUE_REQUIRED, 'Use different servers.yaml.')
            ->addArgument('filter', InputArgument::OPTIONAL, '');
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('use-config'))) {
            if (!is_string($config) || !is_file($config) || !is_readable($config)) {
                $output->writeln('<error>Unable to read data given config.</error>');
            }
            Config::save('servers', Yaml::parseFile($config));
        }

        $list = [];
        $serversFilter = (string)$input->getOption('servers-filter');
        $selected = array_map('trim', explode(',', $serversFilter));
        $isCustom = !empty($serversFilter) && count($selected) >= 1;
        $filter = $input->getArgument('filter');

        foreach (Config::get('servers', []) as $serverName => $server) {
            if ($isCustom && !in_array($serverName, $selected, true)) {
                $output->writeln(
                    sprintf(
                        '<comment>Ignoring \'%s\' as requested by [-s, --servers-filter] flag.</comment>',
                        $serverName
                    ),
                    OutputInterface::VERBOSITY_DEBUG
                );
                continue;
            }

            $server['name'] = $serverName;
            $list[$serverName] = $server;
        }

        if (empty($list)) {
            throw new RuntimeException(
                $isCustom ? '--servers-filter/-s did not return any server.' : 'No server were found.'
            );
        }

        $rows = [];
        foreach ($list as $serverName => $server) {
            $rows[] = [
                $serverName,
                Yaml::dump(ag($server, $filter, 'Not configured, or invalid key.'), 8, 2)
            ];
        }

        (new Table($output))->setHeaders(['Server', 'Filter: ' . (empty($filter) ? 'None' : $filter)])->setRows(
            $rows
        )->render();

        return self::SUCCESS;
    }
}
