<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use App\Libs\Options;
use App\Libs\Routable;
use DateTimeInterface;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[Routable(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const ROUTE = 'servers:list';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->setDescription('List Added backends.');
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                Config::save('servers', Yaml::parseFile($this->checkCustomBackendsFile($config)));
            } catch (RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        }

        $list = [];

        foreach (Config::get('servers', []) as $name => $backend) {
            $import = 'Disabled';

            if (true === (bool)ag($backend, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                $import = 'Metadata only';
            }

            if (true === (bool)ag($backend, 'import.enabled')) {
                $import = 'Play state & Metadata';
            }

            $importLastRun = ($date = ag($backend, 'import.lastSync')) ? makeDate($date) : 'No record';
            $exportLastRun = ($date = ag($backend, 'export.lastSync')) ? makeDate($date) : 'No record';

            $list[] = [
                'Name' => $name,
                'Type' => ucfirst(ag($backend, 'type')),
                'Import' => $import,
                'Export' => true === (bool)ag($backend, 'export.enabled') ? 'Enabled' : 'Disabled',
                'LastImportDate' => ($importLastRun instanceof DateTimeInterface) ? $importLastRun->format(
                    'Y-m-d H:i:s T'
                ) : $importLastRun,
                'LastExportDate' => ($exportLastRun instanceof DateTimeInterface) ? $exportLastRun->format(
                    'Y-m-d H:i:s T'
                ) : $exportLastRun,
            ];
        }

        $this->displayContent($list, $output, $input->getOption('output'));

        return self::SUCCESS;
    }
}
