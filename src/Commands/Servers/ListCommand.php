<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class ListCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('servers:list')
            ->addOption('use-config', null, InputOption::VALUE_REQUIRED, 'Use different servers.yaml.')
            ->setDescription('List active servers.');
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('use-config'))) {
            if (!is_string($config) || !is_file($config) || !is_readable($config)) {
                throw new RuntimeException('Unable to read data given config.');
            }
            Config::save('servers', Yaml::parseFile($config));
        }

        $list = [];

        $table = new Table($output);
        $table->setHeaders(
            [
                'Name',
                'Type',
                'URL',
                'WH Import',
                'WH Push',
                'Last Manual Import at',
                'Last Manual Export at'
            ]
        );

        foreach (Config::get('servers', []) as $name => $server) {
            $list[] = [
                $name,
                ag($server, 'type'),
                ag($server, 'url'),
                ag($server, 'webhook.token') && ag($server, 'webhook.import') ? 'Enabled' : 'Disabled',
                true === ag($server, 'webhook.push') ? 'Enabled' : 'Disabled',
                ($lastImportSync = ag($server, 'import.lastSync')) ? makeDate($lastImportSync) : 'Never',
                ($lastExportSync = ag($server, 'export.lastSync')) ? makeDate($lastExportSync) : 'Never',
            ];
        }

        $table->setRows($list);

        $table->render();

        return self::SUCCESS;
    }
}
