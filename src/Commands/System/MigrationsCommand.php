<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Routable;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
final class MigrationsCommand extends Command
{
    public const ROUTE = 'system:db:migrations';

    public function __construct(private iDB $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Run database migrations.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Start migrations from start.')
            ->setHelp(
                <<<HELP

This command runs database schema migrations to make sure you database is up to date.
You do not need to run this command unless told by the team. This is done automatically on container startup.

HELP
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $opts = [];

        if ($input->getOption('force')) {
            $opts['fresh'] = true;
        }

        return $this->db->migrations(iDB::MIGRATE_UP, $opts);
    }
}
