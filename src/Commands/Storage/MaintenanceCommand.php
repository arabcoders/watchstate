<?php

declare(strict_types=1);

namespace App\Commands\Storage;

use App\Command;
use App\Libs\Storage\StorageInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class MaintenanceCommand extends Command
{
    public function __construct(private StorageInterface $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('storage:maintenance')
            ->setDescription('Run maintenance tasks on storage backend.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $this->storage->maintenance();

        return self::SUCCESS;
    }
}
