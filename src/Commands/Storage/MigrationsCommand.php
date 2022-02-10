<?php

declare(strict_types=1);

namespace App\Commands\Storage;

use App\Command;
use App\Libs\Storage\StorageInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrationsCommand extends Command
{
    public function __construct(private StorageInterface $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('storage:migrations')
            ->setDescription('Update storage backend schema.')
            ->addOption('extra', null, InputOption::VALUE_OPTIONAL, 'Extra options', null)
            ->addOption('fresh', 'f', InputOption::VALUE_NONE, 'Start migrations from start')
            ->addArgument('direction', InputArgument::OPTIONAL, 'Migrations path (up/down).', 'up');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->storage->migrations($input->getArgument('direction'), $input, $output);
    }
}
