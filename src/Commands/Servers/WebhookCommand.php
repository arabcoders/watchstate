<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class WebhookCommand extends Command
{
    private const WEBHOOK_STATUS_VALUES = [
        'enabled' => true,
        'enable' => true,
        'yes' => true,
        'disabled' => false,
        'disable' => false,
        'no' => false,
    ];

    protected function configure(): void
    {
        $this->setName('servers:webhook')
            ->setDescription('Change Server Webhook settings.')
            ->addOption(
                'api-key-generate',
                'g',
                InputOption::VALUE_NONE,
                'Generate API key for this server. *WILL NOT* override existing key.'
            )
            ->addOption(
                'api-key-regenerate',
                'r',
                InputOption::VALUE_NONE,
                'Regenerate API key, it will invalidate old keys please update related server config.'
            )
            ->addOption(
                'api-key-length',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Change default API key random generator length.',
                (int)Config::get('apiKeysLength', 16)
            )
            ->addOption(
                'status',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Enable/Disable the webhook api for this server. Expected value are [%s]',
                    implode('|', array_keys(self::WEBHOOK_STATUS_VALUES))
                )
            )
            ->addOption(
                'push',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Enable/Disable pushing to this server on webhook events. Expected value are [%s]',
                    implode('|', array_keys(self::WEBHOOK_STATUS_VALUES))
                )
            )
            ->addOption('use-config', null, InputOption::VALUE_REQUIRED, 'Use different servers.yaml.')
            ->addArgument('name', InputArgument::REQUIRED, 'Server name');
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('use-config'))) {
            if (!is_string($config) || !is_file($config) || !is_readable($config)) {
                throw new RuntimeException('Unable to read data given config.');
            }
            Config::save('servers', Yaml::parseFile($config));
        } else {
            $config = Config::get('path') . '/config/servers.yaml';
        }

        $name = $input->getArgument('name');
        $ref = "servers.{$name}";

        if (null === Config::get("{$ref}.type", null)) {
            throw new RuntimeException(sprintf('No server named \'%s\' was found in %s.', $name, $config));
        }

        // -- webhook.token
        if ($input->getOption('api-key-generate') || $input->getOption('api-key-regenerate')) {
            if (!Config::get("{$ref}.webhook.token") || $input->getOption('api-key-regenerate')) {
                $apiToken = bin2hex(random_bytes($input->getOption('api-key-length')));

                $output->writeln(sprintf('<info>Server \'%s\' Webhook API key is: %s</info>', $name, $apiToken));

                Config::save("{$ref}.webhook.token", $apiToken);
            }
        }

        // -- webhook.enabled
        if ($input->getOption('status')) {
            $statusName = strtolower($input->getOption('status'));

            if (!array_key_exists($statusName, self::WEBHOOK_STATUS_VALUES)) {
                throw new RuntimeException(
                    sprintf(
                        'Invalid value was given to --status \'%s\', expected values are [%s]',
                        $statusName,
                        implode(', ', array_keys(self::WEBHOOK_STATUS_VALUES))
                    )
                );
            }

            $status = self::WEBHOOK_STATUS_VALUES[$statusName];

            if (true === $status && !Config::get($ref . '.webhook.token')) {
                $output->writeln(
                    sprintf(
                        '<error>You must generate api key for this server \'%s\' using [-g, --generate] flag before you can start using the Webhook API.</error>',
                        $name
                    )
                );

                return self::INVALID;
            }

            Config::save($ref . '.webhook.enabled', (bool)$status);
        }

        // -- webhook.push
        if ($input->getOption('push')) {
            $statusName = strtolower($input->getOption('push'));

            if (!array_key_exists($statusName, self::WEBHOOK_STATUS_VALUES)) {
                throw new RuntimeException(
                    sprintf(
                        'Invalid value was given to --push \'%s\', expected values are [%s]',
                        $statusName,
                        implode(', ', array_keys(self::WEBHOOK_STATUS_VALUES))
                    )
                );
            }

            $status = self::WEBHOOK_STATUS_VALUES[$statusName];

            Config::save($ref . '.webhook.push', (bool)$status);
        }

        file_put_contents($config, Yaml::dump(Config::get('servers', []), 8, 2));

        return self::SUCCESS;
    }
}
