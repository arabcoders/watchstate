<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class PHPCommand
 *
 * This command is used to generate expected values for php.ini and fpm pool worker.
 * To generate fpm values, use the "--fpm" option.
 */
#[Cli(command: self::ROUTE)]
final class PHPCommand extends Command
{
    public const ROUTE = 'system:php';

    /**
     * Configures the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Generate php config.')
            ->addOption('fpm', null, InputOption::VALUE_NONE, 'Generate php-fpm config.')
            ->setHelp(
                r(
                    <<<HELP

                    This command generate expected values for <notice>php.ini</notice> and <notice>fpm</notice> pool worker.

                    To generate fpm values run:

                    {cmd} <cmd>{route}</cmd> <flag>--fpm</flag>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                    ]
                )
            );
    }

    /**
     * Runs the command based on the input options.
     *
     * @param InputInterface $input The input options.
     * @param OutputInterface $output The output interface for displaying messages.
     * @return int The exit code of the command execution.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $input->getOption('fpm') ? $this->makeFPM($output) : $this->makeConfig($output);
    }

    /**
     * Print the php.ini configuration.
     *
     * @param OutputInterface $output The OutputInterface object to write the configuration to.
     *
     * @return int The status code indicating the success of the method.
     */
    protected function makeConfig(OutputInterface $output): int
    {
        $config = Config::get('php.ini', []);

        foreach ($config as $key => $val) {
            $output->writeln(sprintf('%s=%s', $key, $this->escapeValue($val)));
        }

        return self::SUCCESS;
    }

    /**
     * Print the PHP-FPM configuration.
     *
     * @param OutputInterface $output The OutputInterface object to write the configuration to.
     *
     * @return int The status code indicating the success of the method.
     */
    protected function makeFPM(OutputInterface $output): int
    {
        $config = Config::get('php.fpm', []);

        foreach ($config as $pool => $options) {
            $output->writeln(sprintf('[%s]', $pool));
            foreach ($options ?? [] as $key => $val) {
                $output->writeln(sprintf('%s=%s', $key, $val));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Escape the given value.
     *
     * @param mixed $val The value to escape.
     *
     * @return mixed The escaped value.
     */
    private function escapeValue(mixed $val): mixed
    {
        if (is_bool($val) || is_int($val)) {
            return (int)$val;
        }

        return $val ?? '';
    }
}
