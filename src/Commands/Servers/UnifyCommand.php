<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class UnifyCommand extends Command
{
    protected function configure(): void
    {
        $supported = array_keys(Config::get('supported', []));

        $this->setName('servers:unify')
            ->setDescription('Unify [ServerType] webhook API key.')
            ->addOption('redirect-logger', 'r', InputOption::VALUE_NONE, 'Redirect logger to stdout.')
            ->addOption(
                'webhook-token-length',
                null,
                InputOption::VALUE_OPTIONAL,
                'Change default API key random generator length.',
                (int)Config::get('webhook.tokenLength', 16)
            )
            ->addOption(
                'servers-filter',
                's',
                InputOption::VALUE_OPTIONAL,
                'Only Unify selected servers, comma seperated. \'s1,s2\'.',
                ''
            )
            ->addOption('use-config', null, InputOption::VALUE_REQUIRED, 'Use different servers.yaml.')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Do not create copy servers.yaml before editing.')
            ->addArgument(
                'type',
                InputArgument::REQUIRED,
                sprintf('Server type to unify. Expecting one of [%s]', implode('|', $supported)),
            );
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('use-config'))) {
            if (!is_string($config) || !is_file($config) || !is_readable($config)) {
                $output->writeln('<error>Unable to read data from given config.</error>');
            }
            Config::save('servers', Yaml::parseFile($config));
        } else {
            $config = Config::get('path') . '/config/servers.yaml';
        }

        $type = strtolower((string)$input->getArgument('type'));

        if (!array_key_exists($type, Config::get('supported', []))) {
            $message = sprintf(
                '<error>Invalid type was given. Expecting one of [%s] but got \'%s\' instead.',
                implode('|', array_keys(Config::get('supported', []))),
                $type
            );

            $output->writeln($message);
            return self::FAILURE;
        }

        $serversFilter = (string)$input->getOption('servers-filter');
        $selected = explode(',', $serversFilter);
        $selected = array_map('trim', $selected);
        $isCustom = !empty($serversFilter) && count($selected) >= 1;

        $list = $keys = [];

        foreach (Config::get('servers', []) as $serverName => $server) {
            if (ag($server, 'type') !== $type) {
                $output->writeln(
                    sprintf(
                        '<comment>Ignoring \'%s\' not %s server type. (type: %s).</comment>',
                        $serverName,
                        $type,
                        ag($server, 'type')
                    ),
                    OutputInterface::VERBOSITY_DEBUG
                );
                continue;
            }

            if ($isCustom && !in_array($serverName, $selected, true)) {
                $output->writeln(
                    sprintf(
                        '<comment>Ignoring \'%s\' as requested by [-s, --servers-filter] filter.</comment>',
                        $serverName
                    ),
                    OutputInterface::VERBOSITY_DEBUG
                );
                continue;
            }

            $server['name'] = $serverName;
            $server['ref'] = "servers.{$serverName}";

            $list[$serverName] = $server;

            if (null === ($apiToken = ag($server, 'webhook.token', null))) {
                $apiToken = bin2hex(random_bytes($input->getOption('webhook-token-length')));
            }

            $keys[$apiToken] = 1;
        }

        $count = count($list);

        if (0 === $count) {
            $message = sprintf(
                $isCustom ? '--servers-filter/-s did not return any %s server.' : 'No %s servers were found.',
                $type
            );
            $output->writeln(sprintf('<error>%s</error>', $message));
            return self::FAILURE;
        }

        if (1 === $count) {
            $output->writeln(sprintf('<info>We found only one %s server, therefore, no need to unify.</info>', $type));
            return self::SUCCESS;
        }

        if (count($keys) <= 1) {
            $output->writeln(sprintf('<info>%s Webhook API keys is already unified.</info>', ucfirst($type)));
            return self::SUCCESS;
        }

        // -- check for uuid before unifying.
        foreach ($list as $serverName => $server) {
            $ref = ag($server, 'ref');

            if (null !== Config::get("{$ref}.uuid", null)) {
                continue;
            }

            $output->writeln(sprintf('<error>ERROR %s: does not have server unique id set.</error>', $serverName));
            $output->writeln('<comment>Please run this command to update server info.</comment>');
            $output->writeln(sprintf(commandContext() . 'servers:edit --uuid-from-server -- \'%s\' ', $serverName));
            $output->writeln('<comment>Or manually set the uuid using the following command.</comment>');
            $output->writeln(
                sprintf(commandContext() . 'servers:edit --uuid=[SERVER_UNIQUE_ID] -- \'%s\' ', $serverName)
            );
            return self::FAILURE;
        }

        $apiToken = array_keys($keys ?? [])[0] ?? bin2hex(random_bytes($input->getOption('webhook-token-length')));

        foreach ($list as $server) {
            $ref = ag($server, 'ref');
            Config::save("{$ref}.webhook.token", $apiToken);
        }

        if (!$input->getOption('no-backup') && is_writable(dirname($config))) {
            copy($config, $config . '.bak');
        }

        file_put_contents($config, Yaml::dump(Config::get('servers', []), 8, 2));

        $output->writeln(sprintf('<comment>Unified the API key of %d %s servers.</comment>', count($list), $type));
        $output->writeln(sprintf('<info>%s global webhook API key is: %s</info>', ucfirst($type), $apiToken));
        return self::SUCCESS;
    }
}
