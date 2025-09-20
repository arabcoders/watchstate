<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const string ROUTE = 'db:list';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Display this user history.')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit results to this number', 20)
            ->addOption(
                'via',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit results to this specified backend. This filter is not reliable. and changes based on last backend query.'
            )
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit results to this specified type can be [movie or episode].'
            )
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Limit results to this specified title.')
            ->addOption('subtitle', null, InputOption::VALUE_REQUIRED, 'Limit results to this specified content title.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Show results that contains this file path.')
            ->addOption('season', null, InputOption::VALUE_REQUIRED, 'Select season number.')
            ->addOption('genre', null, InputOption::VALUE_REQUIRED, 'Filter on genre.')
            ->addOption('episode', null, InputOption::VALUE_REQUIRED, 'Select episode number.')
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'Select year.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Select db record number.')
            ->addOption(
                'sort',
                null,
                InputOption::VALUE_REQUIRED,
                'Set sort by columns. [Example: <flag>--sort</flag> <value>season:asc</value>].',
            )
            ->addOption(
                'guid',
                null,
                InputOption::VALUE_REQUIRED,
                'Search <notice>item</notice> external db ids. [Format: <value>db://id</value>].'
            )
            ->addOption(
                'parent',
                null,
                InputOption::VALUE_REQUIRED,
                'Search <notice>parent</notice> external db ids. [Format: <value>db://id</value>].'
            )
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'For <notice>JSON Fields</notice> key selection.')
            ->addOption('value', null, InputOption::VALUE_REQUIRED, 'For <notice>JSON Fields</notice> value selection.')
            ->addOption(
                'metadata',
                null,
                InputOption::VALUE_NONE,
                'Search in (<notice>metadata</notice>) provided by backends JSON field. Expects [<flag>--key</flag>, <flag>--value</flag>] flags.'
            )
            ->addOption(
                'extra',
                null,
                InputOption::VALUE_NONE,
                'Search in (<notice>extra</notice>) info by backends JSON field. Expects [<flag>--key</flag>, <flag>--value</flag>] flags.'
            )
            ->addOption(
                'exact',
                null,
                InputOption::VALUE_NONE,
                'Use <notice>equal</notice> check instead of <notice>LIKE</notice> for JSON field query.'
            )
            ->addOption(
                'mark-as',
                'm',
                InputOption::VALUE_REQUIRED,
                'Change items play state. Expects [<value>played</value>, <value>unplayed</value>] as value.'
            )
            ->setDescription('List Database entries. [@Removed]')
            ->setHelp('No longer available, please use the WebUI.')->setHidden(true);
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>This command is no longer available, please use the WebUI.</error>');
        return self::FAILURE;
    }
}
