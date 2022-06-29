<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use App\Libs\Routable;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

#[Routable(command: self::ROUTE)]
final class UnifyCommand extends Command
{
    public const ROUTE = 'servers:unify';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Unify [ServerType] webhook API key.')
            ->addOption(
                'servers-filter',
                's',
                InputOption::VALUE_OPTIONAL,
                'Only Unify selected servers, comma seperated. \'s1,s2\'.',
                ''
            )
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument(
                'type',
                InputArgument::REQUIRED,
                sprintf(
                    'Server type to unify. Expecting one of [%s]',
                    implode('|', array_keys(Config::get('supported', [])))
                ),
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        $custom = false;
        if (($config = $input->getOption('config'))) {
            try {
                $this->checkCustomServersFile($config);
                $custom = true;
                Config::save('servers', Yaml::parseFile($config));
            } catch (\RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        } else {
            $config = Config::get('path') . '/config/servers.yaml';
            if (!file_exists($config)) {
                touch($config);
            }
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
                try {
                    $apiToken = bin2hex(random_bytes(Config::get('webhook.tokenLength')));
                } catch (Throwable $e) {
                    $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                    return self::FAILURE;
                }
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

        // -- check for server unique identifier before unifying.
        foreach ($list as $serverName => $server) {
            $ref = ag($server, 'ref');

            if (null !== Config::get("{$ref}.uuid", null)) {
                continue;
            }

            $output->writeln(sprintf('<error>ERROR %s: does not have server unique id set.</error>', $serverName));
            $output->writeln('<comment>Please run this command to update server info.</comment>');
            $output->writeln(sprintf(commandContext() . 'servers:manage \'%s\' ', $serverName));
            return self::FAILURE;
        }

        try {
            $apiToken = array_keys($keys ?? [])[0] ?? bin2hex(random_bytes(Config::get('webhook.tokenLength')));
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return self::FAILURE;
        }

        foreach ($list as $server) {
            $ref = ag($server, 'ref');
            Config::save("{$ref}.webhook.token", $apiToken);
        }

        if (false === $custom) {
            copy($config, $config . '.bak');
        }

        file_put_contents($config, Yaml::dump(Config::get('servers', []), 8, 2));

        $output->writeln(sprintf('<comment>Unified the API key of %d %s servers.</comment>', count($list), $type));
        $output->writeln(sprintf('<info>%s global webhook API key is: %s</info>', ucfirst($type), $apiToken));
        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestArgumentValuesFor('type')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (array_keys(Config::get('supported', [])) as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
