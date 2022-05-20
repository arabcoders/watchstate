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
use Throwable;

final class EditCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('servers:edit')
            ->setDescription('Edit Server settings inline.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Key to update.')
            ->addOption('set', 's', InputOption::VALUE_REQUIRED, 'Value to set.')
            ->addOption('delete', 'd', InputOption::VALUE_NONE, 'Delete value.')
            ->addOption(
                'regenerate-api-key',
                null,
                InputOption::VALUE_NONE,
                'Re-generate backend webhook token. *Not used. will be removed*'
            )
            ->addOption('regenerate-webhook-token', 'g', InputOption::VALUE_NONE, 'Re-generate backend webhook token.')
            ->addArgument('name', InputArgument::REQUIRED, 'Server name');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output, null|array $rerun = null): int
    {
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

        if (!isValidName($name)) {
            $output->writeln(
                sprintf(
                    '<error>ERROR: Invalid \'%s\' name was given. Only \'A-Z, a-z, 0-9, _\' are allowed.</error>',
                    $name,
                )
            );
            return self::FAILURE;
        }

        if (null === ($server = ag($servers, $name, null))) {
            $output->writeln(sprintf('<error>ERROR: Server \'%s\' not found.</error>', $name));
            return self::FAILURE;
        }

        if ($input->getOption('regenerate-api-key') || $input->getOption('regenerate-webhook-token')) {
            try {
                $apiToken = bin2hex(random_bytes(Config::get('webhook.tokenLength')));

                $output->writeln(
                    sprintf('<info>The API key for \'%s\' webhook endpoint is: \'%s\'.</info>', $name, $apiToken)
                );

                $server = ag_set($server, 'webhook.token', $apiToken);
            } catch (Throwable $e) {
                $output->writeln(sprintf('<error>ERROR: %s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        } else {
            if (null === ($key = $input->getOption('key'))) {
                $output->writeln('<error>ERROR: [-k, --key] flag is required.</error>');
                return self::FAILURE;
            }

            $value = $input->getOption('set');

            if (null !== $value && $input->getOption('delete')) {
                $output->writeln(
                    '<error>ERROR: cannot use both [-s, --set] and [-d, --delete] flags as the same time.</error>'
                );

                return self::FAILURE;
            }

            if (null === $value && !$input->getOption('delete')) {
                $output->writeln(ag($server, $key));
                return self::SUCCESS;
            }

            if (null !== $value) {
                if (true === ctype_digit($value)) {
                    $value = (int)$value;
                } elseif (true === is_numeric($value) && true === str_contains($value, '.')) {
                    $value = (float)$value;
                } elseif ('true' === strtolower((string)$value) || 'false' === strtolower((string)$value)) {
                    $value = 'true' === $value;
                } else {
                    $value = (string)$value;
                }

                if ($value === ag($server, $key, null)) {
                    $output->writeln('<comment>Not updating. Value already matches.</comment>');
                    return self::SUCCESS;
                }

                $server = ag_set($server, $key, $value);

                $output->writeln(
                    sprintf(
                        '<info>%s: Updated \'%s\' key value to \'%s\'.</info>',
                        $name,
                        $key,
                        is_bool($value) ? (true === $value ? 'true' : 'false') : $value,
                    )
                );
            }

            if ($input->getOption('delete')) {
                if (false === ag_exists($server, $key)) {
                    $output->writeln(sprintf('<error>%s: \'%s\' key does not exists.</error>', $name, $key));
                    return self::FAILURE;
                }

                $server = ag_delete($server, $key);
                $output->writeln(sprintf('<info>%s: Removed \'%s\' key.</info>', $name, $key));
            }
        }

        if (false === $custom) {
            copy($config, $config . '.bak');
        }

        $servers = ag_set($servers, $name, $server);

        file_put_contents($config, Yaml::dump($servers, 8, 2));

        return self::SUCCESS;
    }

}
