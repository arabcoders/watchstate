<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class ListCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('servers:list')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->setDescription('List servers.');
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                Config::save('servers', Yaml::parseFile($this->checkCustomServersFile($config)));
            } catch (RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        }

        $list = [];

        $table = new Table($output);
        $table->setHeaders(
            [
                'Server Name',
                'Server Type',
                'Webhook: Import',
                'Webhook: Push',
                'Scheduled Task: Import',
                'Scheduled Task: Export'
            ]
        );

        $x = 0;
        $servers = Config::get('servers', []);
        $count = count($servers);

        foreach ($servers as $name => $server) {
            $x++;
            if (true === ag($server, 'import.enabled')) {
                $importStatus = ($date = ag($server, 'import.lastSync')) ? makeDate($date) : 'Never';
            } else {
                $importStatus = 'Disabled';
            }
            if (true === ag($server, 'export.enabled')) {
                $exportStatus = ($date = ag($server, 'export.lastSync')) ? makeDate($date) : 'Never';
            } else {
                $exportStatus = 'Disabled';
            }

            $list[] = [
                $name,
                ucfirst(ag($server, 'type')),
                ag($server, 'webhook.token') && ag($server, 'webhook.import') ? 'Enabled' : 'Disabled',
                true === ag($server, 'webhook.push') ? 'Enabled' : 'Disabled',
                $importStatus,
                $exportStatus,
            ];

            if ($x < $count) {
                $list[] = new TableSeparator();
            }
        }

        $table->setStyle('box')->setRows($list)->render();

        return self::SUCCESS;
    }
}
