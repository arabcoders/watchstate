<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Routable;
use App\Libs\Storage\StorageInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
final class MakeCommand extends Command
{
    public const ROUTE = 'system:db:make';

    public function __construct(private StorageInterface $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Create database migration file.')
            ->addArgument('filename', InputArgument::REQUIRED, 'Migration name.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $file = $this->storage->makeMigration($input->getArgument('filename'));

        $output->writeln(sprintf('<info>Created new migration at \'%s\'.</info>', $file));

        return self::SUCCESS;
    }
}
