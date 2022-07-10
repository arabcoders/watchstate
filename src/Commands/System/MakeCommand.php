<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Routable;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
final class MakeCommand extends Command
{
    public const ROUTE = 'system:db:make';

    public function __construct(private iDB $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Create database schema migration file.')
            ->addArgument('filename', InputArgument::REQUIRED, 'Migration name.')
            ->setHelp(
                r(
                    <<<HELP

This command creates a migration file for database schema.
This is mostly used for people who develop features for this tool.

By default, migration files stored at [<info>{migrationPath}</info>].

The migration file name must be in [<info>in_english</info>] without spaces.

HELP,
                    ['migrationPath' => after(realpath(__DIR__ . '/../../../migrations'), ROOT_PATH)]
                )

            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $file = $this->db->makeMigration($input->getArgument('filename'));

        $output->writeln(
            sprintf(
                '<info>Created new migration at \'%s\'.</info>',
                after(realpath($file), ROOT_PATH),
            )
        );

        return self::SUCCESS;
    }
}
