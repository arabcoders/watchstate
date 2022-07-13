<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Config;
use App\Libs\Routable;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
final class PHPCommand extends Command
{
    public const ROUTE = 'system:php';

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

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $input->getOption('fpm') ? $this->makeFPM($output) : $this->makeConfig($output);
    }

    protected function makeConfig(OutputInterface $output): int
    {
        $config = Config::get('php.ini', []);

        foreach ($config as $key => $val) {
            $output->writeln(sprintf('%s=%s', $key, $this->escapeValue($val)));
        }

        return self::SUCCESS;
    }

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

    private function escapeValue(mixed $val): mixed
    {
        if (is_bool($val) || is_int($val)) {
            return (int)$val;
        }

        return $val ?? '';
    }
}
