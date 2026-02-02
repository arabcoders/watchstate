<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use PDO;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Component\Process\Process;

#[Cli(command: self::ROUTE)]
class RepairCommand extends Command
{
    public const string ROUTE = 'db:repair';

    public function __construct()
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Attempt to repair broken database.')
            ->addArgument('db', InputOption::VALUE_REQUIRED, 'Database to repair.');
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $db = $input->getArgument('db');
        if (empty($db)) {
            $output->writeln('<error>ERROR:</error> You need to provide path to the sqlite db.');
            return self::FAILURE;
        }

        if (false === file_exists($db)) {
            $output->writeln(r("<error>ERROR:</error> Database '{db}' not found.", ['db' => $db]));
            return self::FAILURE;
        }

        $output->writeln(r("<info>INFO:</info> Attempting to repair database '{db}'.", ['db' => $db]));
        $old_db = new PDO("sqlite:{$db}");
        $version = $old_db->query('PRAGMA user_version')->fetchColumn();

        // -- first copy db to prevent data loss.
        $backup = $db . '.before.repair.db';
        if (false === copy($db, $backup)) {
            $output->writeln(r("<error>ERROR:</error> Failed to copy database '{db}' to '{backup}'.", [
                'db' => $db,
                'backup' => $backup,
            ]));
            return self::FAILURE;
        }

        $output->writeln(r("<info>INFO:</info> Copied database '{db}' to '{backup}' as backup.", [
            'db' => $db,
            'backup' => $backup,
        ]));

        $output->writeln(r("<info>INFO:</info> Attempting to repair database '{db}'.", ['db' => $db]));

        $command = "sqlite3 '{file}' '.dump' | sqlite3 '{file}.new.db'";
        $proc = Process::fromShellCommandline(r($command, ['file' => $db]));
        $proc->setTimeout(null);
        $proc->setIdleTimeout(null);
        $proc->run(static function ($type, $out) use ($output) {
            $text = trim((string) $out);
            if (empty($text)) {
                return;
            }
            if ($type === Process::ERR) {
                $output->writeln(r('<error>ERROR:</error> SQLite3: {text}', ['text' => $text]));
            } else {
                $output->writeln(r('<info>INFO:</info> SQLite3: {text}', ['text' => $text]));
            }
        });
        if ($proc->isSuccessful()) {
            $output->writeln(r("<info>INFO:</info> Database '{db}' repaired successfully.", ['db' => $db]));
        } else {
            $output->writeln(r("<error>ERROR:</error> Failed to repair database '{db}'.", ['db' => $db]));
            return self::FAILURE;
        }

        $command = "sqlite3 '{file}.new.db' 'PRAGMA integrity_check'";
        $proc = Process::fromShellCommandline(r($command, ['file' => $db]));
        $proc->setTimeout(null);
        $proc->setIdleTimeout(null);
        $output->writeln('<info>INFO:</info> Checking database integrity...');
        $proc->run(static function ($type, $out) use ($output) {
            $text = trim((string) $out);
            if (empty($text)) {
                return;
            }
            if ($type === Process::ERR) {
                $output->writeln(r('<error>ERROR:</error> SQLite3: {text}', ['text' => $text]));
            } else {
                $output->writeln(r('<info>INFO:</info> SQLite3: {text}', ['text' => $text]));
            }
        });
        if ($proc->isSuccessful()) {
            $output->writeln(r("<info>INFO:</info> Database '{db}' is valid.", ['db' => $db]));
        } else {
            $output->writeln(r("<error>ERROR:</error> Database '{db}' is not valid.", ['db' => $db]));
            return self::FAILURE;
        }

        $output->writeln(r('<info>INFO:</info> Updating database version to {version}.', [
            'version' => $version,
        ]));
        $pdo = new PDO("sqlite:{$db}.new.db");
        $pdo->exec(r('PRAGMA user_version = {version}', ['version' => $version]));

        $output->writeln(r("<info>INFO:</info> Renaming database '{db}.new.db' to '{db}'.", ['db' => $db]));

        if (!rename("{$db}.new.db", $db)) {
            $output->writeln(r("<error>ERROR:</error> Failed to rename database '{db}.new.db' to '{db}'.", [
                'db' => $db,
            ]));
            return self::FAILURE;
        }

        $output->writeln(
            r("<info>INFO:</info> Repair completed successfully. Database '{db}' is now valid.", ['db' => $db]),
        );

        return self::SUCCESS;
    }
}
