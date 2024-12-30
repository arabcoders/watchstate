<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Options;
use DateTimeInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const string ROUTE = 'config:list';

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('List Added backends.')
            ->setHelp('This command list your configured backends.');
    }

    /**
     * Runs the command.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int The command exit code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
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
