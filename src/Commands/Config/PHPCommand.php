<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PHPCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('config:php')
            ->setDescription('Generate PHP Config')
            ->addOption('fpm', null, InputOption::VALUE_NONE, 'Generate FPM Config.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
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
