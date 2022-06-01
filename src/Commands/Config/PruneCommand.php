<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Config;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PruneCommand extends Command
{
    public const TASK_NAME = 'prune';

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
            ->setDescription('Prune old logs files.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $time = $input->getOption('older-than');

        if ('disable' === $time) {
            $output->writeln('Pruning is disabled.', OutputInterface::VERBOSITY_DEBUG);
            return self::SUCCESS;
        }

        $expiresAt = strtotime($input->getOption('older-than'), time());

        $paths = [
            [
                'path' => Config::get('tmpDir') . '/logs/crons',
                'filter' => '*.log',
            ],
            [
                'path' => Config::get('tmpDir') . '/webhooks',
                'filter' => '*.json',
            ],
            [
                'path' => Config::get('tmpDir') . '/debug',
                'filter' => '*.json',
            ],
        ];

        foreach ($paths as $item) {
            if (!is_dir(ag($item, 'path'))) {
                $output->writeln(sprintf('Path \'%s\' does not exists.', ag($item, 'path')));
                continue;
            }

            foreach (glob(ag($item, 'path') . '/' . ag($item, 'filter')) as $file) {
                $file = new SplFileInfo($file);

                $fileName = $file->getBasename();

                if ('.' === $fileName || '..' === $fileName || $file->isDir() || !$file->isFile()) {
                    continue;
                }

                if ($file->getMTime() > $expiresAt) {
                    continue;
                }

                $output->writeln(sprintf('Deleting %s', $file->getRealPath()));
                unlink($file->getRealPath());
            }
        }

        return self::SUCCESS;
    }

}
