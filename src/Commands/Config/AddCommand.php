<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Routable;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
final class AddCommand extends Command
{
    public const ROUTE = 'config:add';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Add new backend.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name');
    }

    /**
     * @throws \Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $opts = [
            '--add' => true,
        ];

        if ($input->getOption('config')) {
            $opts['--config'] = $input->getOption('config');
        }

        $opts['backend'] = $input->getArgument('backend');

        return $this->getApplication()?->find(ManageCommand::ROUTE)->run(new ArrayInput($opts), $output);
    }
}
