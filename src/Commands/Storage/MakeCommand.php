<?php

declare(strict_types=1);

namespace App\Commands\Storage;

use App\Command;
use App\Libs\Storage\StorageInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MakeCommand extends Command
{
    public function __construct(private StorageInterface $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('storage:make')
            ->setDescription('Create Storage backend migration.')
            ->addOption('extra', null, InputOption::VALUE_OPTIONAL, 'Extra options.', null)
            ->addArgument('name', InputArgument::REQUIRED, 'Migration name');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $this->storage->makeMigration($input->getArgument('name'), $output);

        return self::SUCCESS;
    }
}
