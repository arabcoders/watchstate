<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class EditCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('servers:edit')
            ->setDescription('Edit Server settings inline.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Key to update.')
            ->addOption('set', 's', InputOption::VALUE_REQUIRED, 'Value to set.')
            ->addArgument('name', InputArgument::REQUIRED, 'Server name');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output, null|array $rerun = null): int
    {
        if (null === ($key = $input->getOption('key'))) {
            $output->writeln('<error>ERROR: [-k, --key] flag is required.</error>');
            return self::FAILURE;
        }

        if (null === ($value = $input->getOption('set'))) {
            $output->writeln('<error>ERROR: [-s, --set] flag is required.</error>');
            return self::FAILURE;
        }

        $custom = false;

        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                $this->checkCustomServersFile($config);
                $custom = true;
                $servers = (array)Yaml::parseFile($config);
            } catch (\RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        } else {
            $config = Config::get('path') . '/config/servers.yaml';
            if (!file_exists($config)) {
                touch($config);
            }
            $servers = (array)Config::get('servers', []);
        }

        $name = $input->getArgument('name');

        if (null === ag($servers, "{$name}.type", null)) {
            $output->writeln(sprintf('<error>ERROR: Server \'%s\' not found.</error>', $name));
            return self::FAILURE;
        }

        if ($value === ag($servers, "{$name}.{$key}")) {
            $output->writeln('<comment>Not updating. Value already matches.</comment>');
            return self::SUCCESS;
        }

        $servers = ag_set($servers, "{$name}.{$key}", $value);

        $output->writeln(
            sprintf(
                '<info>Updated server:\'%s\' key \'%s\' with value of \'%s\' </info>',
                $name,
                $key,
                $value
            )
        );

        if (false === $custom) {
            copy($config, $config . '.bak');
        }

        file_put_contents($config, Yaml::dump($servers, 8, 2));

        return self::SUCCESS;
    }

}
