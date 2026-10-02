<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class VersionCommand extends Command
{
    public const string ROUTE = 'version';

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Show application version information.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $sha = (string) Config::get('version_sha', 'unknown');

        $output->writeln([
            '<info>WatchState ' . get_app_version() . '</info>',
            '<comment>Branch:</comment> <info>' . Config::get('version_branch', 'unknown') . '</info>',
            '<comment>Build:</comment> <info>' . Config::get('version_build', 'unknown') . '</info>',
            '<comment>SHA:</comment> <href=https://github.com/arabcoders/watchstate/commit/' . $sha . '><info>' . $sha . '</info></>',
        ]);

        return self::SUCCESS;
    }
}
