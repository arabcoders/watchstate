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

final class EditCommand extends Command
{
    private const ON_OFF_FLAGS = [
        'enabled' => true,
        'enable' => true,
        'yes' => true,
        'disabled' => false,
        'disable' => false,
        'no' => false,
    ];

    protected function configure(): void
    {
        $values = implode('|', array_keys(self::ON_OFF_FLAGS));

        $this->setName('servers:edit')
            ->setDescription('Edit Server settings.')
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Change server type. Expected value is one of [%s]',
                    implode('|', array_keys(Config::get('supported', [])))
                )
            )
            ->addOption(
                'url',
                null,
                InputOption::VALUE_REQUIRED,
                'Change server url.'
            )
            ->addOption(
                'token',
                null,
                InputOption::VALUE_REQUIRED,
                'Change server API key.'
            )
            ->addOption(
                'user',
                null,
                InputOption::VALUE_REQUIRED,
                'Change server User Id.'
            )
            ->addOption(
                'export-status',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Enable/Disable manual exporting to this server. Expected value is one of [%s]', $values)
            )
            ->addOption(
                'import-status',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Enable/Disable manual importing from this server. Expected value is one of [%s]', $values)
            )
            ->addOption(
                'webhook-key-generate',
                null,
                InputOption::VALUE_NONE,
                'Generate API key for this server. *WILL NOT* override existing key.'
            )
            ->addOption(
                'webhook-key-regenerate',
                null,
                InputOption::VALUE_NONE,
                'Regenerate API key, it will invalidate old keys please update related server config.'
            )
            ->addOption(
                'webhook-key-length',
                null,
                InputOption::VALUE_OPTIONAL,
                'Change default API key random generator length.',
                (int)Config::get('webhook.keyLength', 16)
            )
            ->addOption(
                'webhook-import-status',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Enable/Disable the webhook api for this server. Expected value is one of [%s]', $values)
            )
            ->addOption(
                'webhook-require-ips',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma seperated ips/CIDR to link a server to specific ips. Useful for Multi Plex servers setup.',
            )
            ->addOption(
                'webhook-server-uuid',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit this server specific UUID. Useful for Multi Plex servers setup.',
            )
            ->addOption(
                'webhook-push-status',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Enable/Disable pushing to this server on webhook events. Expected value are [%s]', $values)
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

        // -- $type
        if ($input->getOption('type')) {
            if (!array_key_exists($input->getOption('type'), Config::get('supported', []))) {
                $output->writeln(
                    sprintf(
                        '<error>Unexpected value for --type, was expecting one of [%s] but got \'%s\' instead.',
                        implode('|', array_keys(Config::get('supported', []))),
                        $input->getOption('type')
                    )
                );
                return self::INVALID;
            }

            Config::save("{$ref}.type", $input->getOption('type'));
        }

        // -- $ref.url
        if ($input->getOption('url')) {
            if (!filter_var($input->getOption('url'), FILTER_VALIDATE_URL)) {
                $output->writeln(sprintf('<error>Invalid --url value \'%s\' was given.', $input->getOption('url')));
                return self::INVALID;
            }

            Config::save("{$ref}.url", $input->getOption('url'));
        }

        // -- $ref.user
        if ($input->getOption('user')) {
            if (!is_string($input->getOption('user')) && !is_int($input->getOption('user'))) {
                $output->writeln(
                    sprintf(
                        '<error>Expecting --user value to be string or integer. but got \'%s\' instead.',
                        get_debug_type($input->getOption('user'))
                    )
                );
                return self::INVALID;
            }

            Config::save("{$ref}.user", $input->getOption('user'));
        }

        // -- $ref.token
        if ($input->getOption('token')) {
            if (!is_string($input->getOption('token')) && !is_int($input->getOption('token'))) {
                $output->writeln(
                    sprintf(
                        '<error>Expecting --token value to be string or integer. but got \'%s\' instead.',
                        get_debug_type($input->getOption('token'))
                    )
                );
                return self::INVALID;
            }

            Config::save("{$ref}.token", $input->getOption('token'));
        }

        // -- $ref.export.enabled
        if ($input->getOption('export-status')) {
            $statusName = strtolower($input->getOption('export-status'));

            if (!array_key_exists($statusName, self::ON_OFF_FLAGS)) {
                $output->writeln(
                    sprintf(
                        '<error>Unexpected value for --export-status, was expecting one of [%s] but got \'%s\' instead.',
                        implode('|', array_keys(self::ON_OFF_FLAGS)),
                        $statusName
                    )
                );
                return self::INVALID;
            }

            Config::save("{$ref}.export.enabled", (bool)self::ON_OFF_FLAGS[$statusName]);
        }

        // -- $ref.import.enabled
        if ($input->getOption('import-status')) {
            $statusName = strtolower($input->getOption('import-status'));

            if (!array_key_exists($statusName, self::ON_OFF_FLAGS)) {
                $output->writeln(
                    sprintf(
                        '<error>Unexpected value for --import-status, was expecting one of [%s] but got \'%s\' instead.',
                        implode('|', array_keys(self::ON_OFF_FLAGS)),
                        $statusName
                    )
                );
                return self::INVALID;
            }

            Config::save("{$ref}.export.enabled", (bool)self::ON_OFF_FLAGS[$statusName]);
        }

        // -- $ref.webhook.token
        if ($input->getOption('webhook-key-generate') || $input->getOption('webhook-key-regenerate')) {
            if (!Config::get("{$ref}.webhook.token") || $input->getOption('webhook-key-regenerate')) {
                $apiToken = bin2hex(random_bytes($input->getOption('api-key-length')));

                $output->writeln(sprintf('<info>Server \'%s\' Webhook API key is: %s</info>', $name, $apiToken));

                Config::save("{$ref}.webhook.token", $apiToken);
            }
        }

        // -- $ref.webhook.enabled
        if ($input->getOption('webhook-import-status')) {
            $statusName = strtolower($input->getOption('webhook-import-status'));

            if (!array_key_exists($statusName, self::ON_OFF_FLAGS)) {
                $output->writeln(
                    sprintf(
                        '<error>Unexpected value for --webhook-import-status, was expecting one of [%s] but got \'%s\' instead.',
                        implode('|', array_keys(self::ON_OFF_FLAGS)),
                        $statusName
                    )
                );
                return self::INVALID;
            }

            $status = self::ON_OFF_FLAGS[$statusName];

            Config::save($ref . '.webhook.import', (bool)$status);
        }

        // -- $ref.webhook.push
        if ($input->getOption('webhook-push-status')) {
            $statusName = strtolower($input->getOption('webhook-push-status'));

            if (!array_key_exists($statusName, self::ON_OFF_FLAGS)) {
                $output->writeln(
                    sprintf(
                        '<error>Unexpected value for --webhook-push-status, was expecting one of [%s] but got \'%s\' instead.',
                        implode('|', array_keys(self::ON_OFF_FLAGS)),
                        $statusName
                    )
                );

                return self::INVALID;
            }

            Config::save($ref . '.webhook.push', (bool)self::ON_OFF_FLAGS[$statusName]);
        }

        // -- $ref.webhook.ips
        if ($input->getOption('webhook-require-ips')) {
            Config::save($ref . '.webhook.ips', explode(',', $input->getOption('webhook-require-ips')));
        }

        // -- $ref.webhook.uuid
        if ($input->getOption('webhook-server-uuid')) {
            Config::save($ref . '.webhook.uuid', $input->getOption('webhook-server-uuid'));
        }

        file_put_contents($config, Yaml::dump(Config::get('servers', []), 8, 2));

        return self::SUCCESS;
    }
}
