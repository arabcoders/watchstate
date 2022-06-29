<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Config;
use App\Libs\Routable;
use Psr\Log\LoggerInterface;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
final class PruneCommand extends Command
{
    public const ROUTE = 'system:prune';

    public const TASK_NAME = 'prune';

    public function __construct(private LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption(
                'older-than',
                null,
                InputOption::VALUE_REQUIRED,
                'Delete logs older than.',
                Config::get('logs.prune.after', '-3 DAYS')
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not take any action.')
            ->setDescription('Delete old logs files.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $time = $input->getOption('older-than');

        if ('disable' === $time) {
            $this->logger->notice('Pruning is disabled.');
            return self::SUCCESS;
        }

        if (false === ($expiresAt = strtotime($time, time()))) {
            $this->logger->error('Invalid older than date was given.', [
                'date' => $time,
            ]);
            return self::FAILURE;
        }

        $directories = [
            [
                'path' => Config::get('tmpDir') . '/logs',
                'filter' => '*.log',
            ],
            [
                // -- @RELEASE - remove path.
                'path' => Config::get('tmpDir') . '/logs/tasks',
                'filter' => '*.log',
                'report' => false,
            ],
            [
                'path' => Config::get('tmpDir') . '/webhooks',
                'filter' => '*.json',
            ],
            [
                'path' => Config::get('tmpDir') . '/profiler',
                'filter' => '*.json',
            ],
            [
                'path' => Config::get('tmpDir') . '/debug',
                'filter' => '*.json',
            ],
        ];

        $inDryRunMode = $input->getOption('dry-run');

        foreach ($directories as $item) {
            $path = ag($item, 'path');

            if (null === $path || !is_dir($path)) {
                if (true === (bool)ag($item, 'report', true)) {
                    $this->logger->warning('Path [%(path)] not found or inaccessible.', [
                        'path' => $path
                    ]);
                }
                continue;
            }

            foreach (glob(ag($item, 'path') . '/' . ag($item, 'filter')) as $file) {
                $file = new SplFileInfo($file);

                $fileName = $file->getBasename();

                if ('.' === $fileName || '..' === $fileName || true === $file->isDir() || false === $file->isFile()) {
                    $this->logger->debug('Path [%(path)] is not considered valid file.', [
                        'path' => $file->getRealPath(),
                    ]);
                    continue;
                }

                if ($file->getMTime() > $expiresAt) {
                    $this->logger->debug('File [%(file)] Not yet expired. %(ttl) left seconds.', [
                        'file' => after($file->getRealPath(), Config::get('tmpDir') . '/'),
                        'ttl' => number_format($file->getMTime() - $expiresAt),
                    ]);
                    continue;
                }

                $this->logger->notice('Removing [%(file)].', [
                    'file' => after($file->getRealPath(), Config::get('tmpDir'))
                ]);

                if (false === $inDryRunMode) {
                    unlink($file->getRealPath());
                }
            }
        }

        return self::SUCCESS;
    }

}
