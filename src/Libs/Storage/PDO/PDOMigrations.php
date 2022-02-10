<?php

declare(strict_types=1);

namespace App\Libs\Storage\PDO;

use App\Command;
use App\Libs\Config;
use App\Libs\Storage\StorageInterface;
use Exception;
use PDO;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PDOMigrations
{
    private string $path;
    private string $versionFile;

    public function __construct(private PDO $pdo)
    {
        $this->path = __DIR__ . DS . 'Migrations';
        $this->versionFile = Config::get('path') . DS . 'db' . DS . 'pdo_migrations_version';

        if (!file_exists($this->versionFile)) {
            $this->setVersion(0);
        }
    }

    public function up(InputInterface $input, OutputInterface $output): int
    {
        if ($input->hasOption('fresh') && $input->getOption('fresh')) {
            $version = 0;
        } else {
            $version = $this->getVersion();
        }

        $dir = StorageInterface::MIGRATE_UP;

        $run = 0;

        foreach ($this->parseFiles() as $migrate) {
            if ($version >= ag($migrate, 'id')) {
                continue;
            }

            $run++;

            if (!ag($migrate, $dir)) {
                $output->writeln(
                    sprintf(
                        '<error>Migration #%d - %s has no %s. Skipping.</error>',
                        ag($migrate, 'id'),
                        ag($migrate, 'name'),
                        $dir
                    ),
                    OutputInterface::VERBOSITY_DEBUG
                );
            }

            $output->writeln(
                sprintf(
                    '<info>Applying Migration #%d - %s (%s)</info>',
                    ag($migrate, 'id'),
                    ag($migrate, 'name'),
                    $dir
                )
            );

            $data = ag($migrate, $dir);

            $output->writeln(
                sprintf('<comment>Applying %s.</comment>', PHP_EOL . $data),
                OutputInterface::VERBOSITY_DEBUG
            );

            $this->pdo->exec((string)$data);
            $this->setVersion(ag($migrate, 'id'));
        }

        $message = !$run ? sprintf('<comment>No migrations is needed. Version @ %d</comment>', $version) : sprintf(
            '<info>Applied %s migrations. Version @ %d</info>',
            $run,
            $this->getVersion()
        );

        $output->writeln($message);

        return Command::SUCCESS;
    }

    public function down(OutputInterface $output): int
    {
        $output->writeln('<comment>This driver does not support down migrations at this time.</comment>');

        return Command::SUCCESS;
    }

    /**
     * @throws Exception
     */
    public function make(string $name, OutputInterface $output): string
    {
        $name = str_replace(chr(040), '_', $name);

        $fileName = sprintf('%s_%d_%s.sql', $this->getDriver(), time(), $name);

        $file = $this->path . DS . $fileName;

        if (!touch($file)) {
            throw new RuntimeException(sprintf('Unable to create new migration at \'%s\'.', $this->path . DS));
        }

        file_put_contents(
            $file,
            <<<SQL
        -- # migrate_up

        -- Put your upgrade database commands here.

        -- # migrate_down

        -- put your downgrade database commands here.

        SQL
        );

        $output->writeln(sprintf('<info>Created new Migration file at \'%s\'.</info>', $file));

        return $file;
    }

    public function runMaintenance(): int|bool
    {
        return $this->pdo->exec('VACUUM;');
    }

    private function getVersion(): int
    {
        return (int)file_get_contents($this->versionFile);
    }

    private function setVersion(int $version): void
    {
        file_put_contents($this->versionFile, $version);
    }

    private function getDriver(): string
    {
        $driver = $this->pdo->getAttribute($this->pdo::ATTR_DRIVER_NAME);

        if (empty($driver) || !is_string($driver)) {
            $driver = 'unknown';
        }

        return strtolower($driver);
    }

    private function parseFiles(): array
    {
        $migrations = [];
        $driver = $this->getDriver();

        foreach ((array)glob($this->path . DS . '*.sql') as $file) {
            if (!is_string($file) || false === ($f = realpath($file))) {
                throw new RuntimeException(sprintf('Unable to get real path to \'%s\'', $file));
            }

            [$type, $id, $name] = (array)preg_split(
                '#^(\w+)_(\d+)_(.+)\.sql$#',
                basename($f),
                -1,
                PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE
            );

            if ($type !== $driver) {
                continue;
            }

            $id = (int)$id;

            [$up, $down] = (array)preg_split(
                '/^-- #\s+?migrate_down\b/im',
                (string)file_get_contents($f),
                -1,
                PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE
            );

            $up = trim(preg_replace('/^-- #\s+?migrate_up\b/i', '', (string)$up));
            $down = trim((string)$down);

            $migrations[$id] = [
                'type' => $type,
                'id' => $id,
                'name' => $name,
                'up' => $up,
                'down' => $down,
            ];
        }

        return $migrations;
    }
}
