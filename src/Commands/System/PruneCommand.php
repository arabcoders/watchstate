<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use Psr\Log\LoggerInterface;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class PruneCommand
 *
 * This command removes automatically generated files like logs and backups.
 * It provides an option to run in dry-run mode to see what files will be removed without actually removing them.
 */
#[Cli(command: self::ROUTE)]
final class PruneCommand extends Command
{
    public const ROUTE = 'system:prune';

    public const TASK_NAME = 'prune';

    /**
     * Class Constructor.
     *
     * @param LoggerInterface $logger The logger implementation used for logging.
     */
    public function __construct(private LoggerInterface $logger)
    {
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not perform any actions on files.')
            ->setDescription('Remove automatically generated files.')
            ->setHelp(
                r(
                    <<<HELP

                    This command remove automatically generated files. like logs and backups.

                    to see what files will be removed without actually removing them. run the following command.

                    {cmd} <cmd>{route}</cmd> <flag>--dry-run</flag> <flag>-vvv</flag>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                    ]
                )
            );
    }

    /**
     * Executes the command.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int The exit status code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $time = time();

        $directories = [
            [
                'path' => Config::get('tmpDir') . '/logs',
                'base' => Config::get('tmpDir'),
                'filter' => '*.log',
                'time' => strtotime('-7 DAYS', $time)
            ],
            [
                'path' => Config::get('tmpDir') . '/webhooks',
                'base' => Config::get('tmpDir'),
                'filter' => '*.json',
                'time' => strtotime('-3 DAYS', $time)
            ],
            [
                'path' => Config::get('tmpDir') . '/profiler',
                'base' => Config::get('tmpDir'),
                'filter' => '*.json',
                'time' => strtotime('-3 DAYS', $time)
            ],
            [
                'path' => Config::get('tmpDir') . '/debug',
                'base' => Config::get('tmpDir'),
                'filter' => '*.json',
                'time' => strtotime('-3 DAYS', $time)
            ],
            [
                'path' => Config::get('path') . '/backup',
                'base' => Config::get('path'),
                'filter' => '*.*.json',
                'time' => strtotime('-9 DAYS', $time)
            ],
        ];

        $inDryRunMode = $input->getOption('dry-run');

        foreach ($directories as $item) {
            $path = ag($item, 'path');

            if (null === ($expiresAt = ag($item, 'time'))) {
                $this->logger->warning('Error No expected time to live was found for [{path}].', [
                    'path' => $path
                ]);
                continue;
            }

            if (null === $path || !is_dir($path)) {
                if (true === (bool)ag($item, 'report', true)) {
                    $this->logger->warning('Path [{path}] not found or inaccessible.', [
                        'path' => $path
                    ]);
                }
                continue;
            }

            foreach (glob(ag($item, 'path') . '/' . ag($item, 'filter')) as $file) {
                $file = new SplFileInfo($file);

                $fileName = $file->getBasename();

                if ('.' === $fileName || '..' === $fileName || true === $file->isDir() || false === $file->isFile()) {
                    $this->logger->debug('Path [{path}] is not considered valid file.', [
                        'path' => $file->getRealPath(),
                    ]);
                    continue;
                }

                if ($file->getMTime() > $expiresAt) {
                    $this->logger->debug('File [{file}] Not yet expired. {ttl} left seconds.', [
                        'file' => after($file->getRealPath(), ag($item, 'base') . '/'),
                        'ttl' => number_format($file->getMTime() - $expiresAt),
                    ]);
                    continue;
                }

                $this->logger->notice('Removing [{file}].', [
                    'file' => after($file->getRealPath(), ag($item, 'base') . '/')
                ]);

                if (false === $inDryRunMode) {
                    unlink($file->getRealPath());
                }
            }
        }

        return self::SUCCESS;
    }
}
