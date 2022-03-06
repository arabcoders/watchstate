<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use App\Libs\Extends\CliLogger;
use Exception;
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
            ->addOption('redirect-logger', 'r', InputOption::VALUE_NONE, 'Redirect logger to stdout.')
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
                'Change server user ID.'
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
                'Comma seperated IPS/CIDR to link a server to specific IPS. Useful for Multi Plex servers setup.',
            )
            ->addOption(
                'webhook-server-uuid',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit this server to specific UUID. Useful for Multi Plex servers setup.',
            )
            ->addOption(
                'webhook-update-server-uuid',
                null,
                InputOption::VALUE_NONE,
                'Get the server unique id from server.'
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
                $output->writeln('<error>Unable to read data given config.</error>');
            }
            Config::save('servers', Yaml::parseFile($config));
        } else {
            $config = Config::get('path') . '/config/servers.yaml';
        }

        $name = $input->getArgument('name');
        $ref = "servers.{$name}";

        if (null === Config::get("{$ref}.type", null)) {
            $output->writeln(
                sprintf('<error>No server named \'%s\' was found in %s.</error>', $name, $config)
            );
            return self::FAILURE;
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

                $output->writeln(
                    sprintf('<info>The API key for \'%s\' webhook endpoint is: %s</info>', $name, $apiToken)
                );

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

            Config::save("{$ref}.webhook.import", (bool)$status);
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

            Config::save("{$ref}.webhook.push", (bool)self::ON_OFF_FLAGS[$statusName]);
        }

        // -- $ref.webhook.ips
        if ($input->getOption('webhook-require-ips')) {
            Config::save("{$ref}.webhook.ips", explode(',', $input->getOption('webhook-require-ips')));
        }

        // -- $ref.webhook.uuid
        if ($input->getOption('webhook-server-uuid')) {
            Config::save("{$ref}.webhook.uuid", $input->getOption('webhook-server-uuid'));
        }

        // -- $ref.webhook.uid (Pull from server)
        if ($input->getOption('webhook-update-server-uuid')) {
            $server = makeServer(Config::get($ref), $name);
            if ($input->getOption('redirect-logger')) {
                $server->setLogger(new CliLogger($output, false));
            }

            $uuid = $server->getServerUUID();

            if (null === $uuid) {
                $output->writeln(
                    sprintf(
                        '<error>Unable to get \'%s\' server unique id. Please manually set it at `server.yaml` under key of `webhook.uuid`</error>',
                        $name
                    )
                );

                return self::FAILURE;
            }

            $output->writeln(
                sprintf('<info>Updating \'%s\' server unique id to: %s</info>', $name, $uuid)
            );

            if (Config::get("{$ref}.webhook.uuid") !== $uuid) {
                Config::save("{$ref}.webhook.uuid", $uuid);
            }
        }

        file_put_contents($config, Yaml::dump(Config::get('servers', []), 8, 2));

        return self::SUCCESS;
    }
}
