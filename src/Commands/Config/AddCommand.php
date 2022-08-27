<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Routable;
use Symfony\Component\Console\Exception\ExceptionInterface;
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
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name')
            ->setHelp(
                r(
                    <<<HELP

                    This command allow you to add new backend.
                    This command require <notice>interaction</notice> to work.

                    This command is shortcut for running the following command:

                    {cmd} <cmd>{manage_route}</cmd> --add -- <value>backend_name</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'manage_route' => ManageCommand::ROUTE,
                    ]
                )
            );
    }

    /**
     * @throws ExceptionInterface
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $opts = [
            '--add' => true,
        ];

        if ($input->getOption('config')) {
            $opts['--config'] = $input->getOption('config');
        }

        $opts['backend'] = strtolower($input->getArgument('backend'));

        return $this->getApplication()?->find(ManageCommand::ROUTE)->run(new ArrayInput($opts), $output) ?? 1;
    }
}
