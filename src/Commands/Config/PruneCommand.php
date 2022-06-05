<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Config;
use Psr\Log\LoggerInterface;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PruneCommand extends Command
{
    public const TASK_NAME = 'prune';

    public function __construct(private LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('config:prune')
            ->addOption(
                'older-than',
                null,
                InputOption::VALUE_REQUIRED,
                'delete files older specified time',
                Config::get('logs.prune.after', '-3 DAYS')
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not take any action.')
            ->setDescription('Prune old logs files.');
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
                'path' => Config::get('tmpDir') . '/logs/tasks',
                'filter' => '*.log',
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
                $this->logger->warning('Path does not exists.', [
                    'path' => $path
                ]);
                continue;
            }

            foreach (glob(ag($item, 'path') . '/' . ag($item, 'filter')) as $file) {
                $file = new SplFileInfo($file);

                $fileName = $file->getBasename();

                if ('.' === $fileName || '..' === $fileName || true === $file->isDir() || false === $file->isFile()) {
                    $this->logger->debug('Path is not considered valid file.', [
                        'path' => $file->getRealPath(),
                    ]);
                    continue;
                }

                if ($file->getMTime() > $expiresAt) {
                    $this->logger->debug('Path Not yet expired.', [
                        'path' => after($file->getRealPath(), Config::get('tmpDir')),
                        'ttl' => number_format($file->getMTime() - $expiresAt),
                    ]);
                    continue;
                }

                $this->logger->notice('Deleting File.', [
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
