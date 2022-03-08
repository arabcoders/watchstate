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
    public const ON_OFF_FLAGS = [
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
                'Change server user id.'
            )
            ->addOption(
                'uuid',
                null,
                InputOption::VALUE_REQUIRED,
                'Change server unique id.',
            )
            ->addOption(
                'uuid-from-server',
                null,
                InputOption::VALUE_NONE,
                'Pull the server unique id directly from server.'
            )
            ->addOption(
                'export-enabled',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Enable/Disable manual exporting to this server. Expected value is one of [%s]', $values)
            )
            ->addOption(
                'import-enabled',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Enable/Disable manual importing from this server. Expected value is one of [%s]', $values)
            )
            ->addOption(
                'webhook-token-generate',
                null,
                InputOption::VALUE_NONE,
                'Generate webhook token for the server. It will not override existing one.'
            )
            ->addOption(
                'webhook-token-regenerate',
                null,
                InputOption::VALUE_NONE,
                'Re-generate webhook token. It will invalidate old token.'
            )
            ->addOption(
                'webhook-token-length',
                null,
                InputOption::VALUE_OPTIONAL,
                'Change the bytes length of webhook token.',
                (int)Config::get('webhook.tokenLength', 16)
            )
            ->addOption(
                'webhook-import',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Enable/Disable the webhook endpoint for this server. Expected value is one of [%s]', $values)
            )
            ->addOption(
                'webhook-push',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Enable/Disable pushing to this server on webhook events. Expected value are [%s]', $values)
            )
            ->addOption(
                'webhook-match-user',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Enable/Disable user check on webhook request. Expected value is one of [%s]', $values)
            )
            ->addOption(
                'webhook-match-uuid',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Enable/Disable server unique id check on webhook request. Expected value is one of [%s]',
                    $values
                )
            )
            ->addOption('use-config', null, InputOption::VALUE_REQUIRED, 'Use different servers.yaml.')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Do not create copy servers.yaml before editing.')
            ->addArgument('name', InputArgument::REQUIRED, 'Server name');
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $error = false;

        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('use-config'))) {
            if (!is_string($config) || !is_file($config) || !is_readable($config)) {
                $output->writeln('<error>Unable to read data given config.</error>');
                return self::FAILURE;
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
                $error = true;
            } else {
                Config::save("{$ref}.type", $input->getOption('type'));
            }
        }

        // -- $ref.url
        if ($input->getOption('url')) {
            if (!filter_var($input->getOption('url'), FILTER_VALIDATE_URL)) {
                $output->writeln(sprintf('<error>Invalid --url value \'%s\' was given.', $input->getOption('url')));
                $error = true;
            } else {
                Config::save("{$ref}.url", $input->getOption('url'));
            }
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
                $error = true;
            } else {
                Config::save("{$ref}.user", $input->getOption('user'));
            }
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
                $error = true;
            } else {
                Config::save("{$ref}.token", $input->getOption('token'));
            }
        }

        // -- $ref.uuid
        if ($input->getOption('uuid')) {
            if (!is_string($input->getOption('uuid')) && !is_int($input->getOption('uuid'))) {
                $output->writeln(
                    sprintf(
                        '<error>Expecting --uuid value to be string or integer. but got \'%s\' instead.',
                        get_debug_type($input->getOption('uuid'))
                    )
                );
                $error = true;
            } else {
                Config::save("{$ref}.uuid", $input->getOption('uuid'));
            }
        }

        // -- $ref.uid (Pull from server)
        if ($input->getOption('uuid-from-server')) {
            try {
                $server = makeServer(Config::get($ref), $name);

                if ($input->getOption('redirect-logger')) {
                    $server->setLogger(new CliLogger($output, false));
                }

                $uuid = $server->getServerUUID(true);

                if (null === $uuid) {
                    $output->writeln(
                        sprintf(
                            '<error>Unable to get \'%s\' unique id. Set it manually via [--uuid=UNIQUE_ID] flag</error>',
                            $name
                        )
                    );
                    $error = true;
                } else {
                    if (Config::get("{$ref}.uuid") !== $uuid) {
                        $output->writeln(
                            sprintf('<info>Updating \'%s\' server unique id to: %s</info>', $name, $uuid)
                        );
                        Config::save("{$ref}.uuid", $uuid);
                    }
                }
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                $error = true;
            }
        }

        // -- $ref.export.enabled
        if ($input->getOption('export-enabled')) {
            $statusName = strtolower($input->getOption('export-enabled'));

            if (!array_key_exists($statusName, self::ON_OFF_FLAGS)) {
                $output->writeln(
                    sprintf(
                        '<error>Unexpected value for --export-enabled, was expecting one of [%s] but got \'%s\' instead.',
                        implode('|', array_keys(self::ON_OFF_FLAGS)),
                        $statusName
                    )
                );
                $error = true;
            } else {
                Config::save("{$ref}.export.enabled", (bool)self::ON_OFF_FLAGS[$statusName]);
            }
        }

        // -- $ref.import.enabled
        if ($input->getOption('import-enabled')) {
            $statusName = strtolower($input->getOption('import-enabled'));

            if (!array_key_exists($statusName, self::ON_OFF_FLAGS)) {
                $output->writeln(
                    sprintf(
                        '<error>Unexpected value for --import-enabled, was expecting one of [%s] but got \'%s\' instead.',
                        implode('|', array_keys(self::ON_OFF_FLAGS)),
                        $statusName
                    )
                );
                $error = true;
            } else {
                Config::save("{$ref}.export.enabled", (bool)self::ON_OFF_FLAGS[$statusName]);
            }
        }

        // -- $ref.webhook.token
        if ($input->getOption('webhook-token-generate') || $input->getOption('webhook-token-regenerate')) {
            if (!Config::get("{$ref}.webhook.token") || $input->getOption('webhook-token-regenerate')) {
                try {
                    $apiToken = bin2hex(random_bytes($input->getOption('webhook-token-length')));

                    $output->writeln(
                        sprintf('<info>The API key for \'%s\' webhook endpoint is: %s</info>', $name, $apiToken)
                    );

                    Config::save("{$ref}.webhook.token", $apiToken);
                } catch (\Throwable $e) {
                    $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                    $error = true;
                }
            }
        }

        // -- $ref.webhook.import
        if ($input->getOption('webhook-import')) {
            $statusName = strtolower($input->getOption('webhook-import'));

            if (!array_key_exists($statusName, self::ON_OFF_FLAGS)) {
                $output->writeln(
                    sprintf(
                        '<error>Unexpected value for --webhook-import, was expecting one of [%s] but got \'%s\' instead.',
                        implode('|', array_keys(self::ON_OFF_FLAGS)),
                        $statusName
                    )
                );
                $error = true;
            } else {
                Config::save("{$ref}.webhook.import", (bool)self::ON_OFF_FLAGS[$statusName]);
            }
        }

        // -- $ref.webhook.push
        if ($input->getOption('webhook-push')) {
            $statusName = strtolower($input->getOption('webhook-push'));

            if (!array_key_exists($statusName, self::ON_OFF_FLAGS)) {
                $output->writeln(
                    sprintf(
                        '<error>Unexpected value for --webhook-push, was expecting one of [%s] but got \'%s\' instead.',
                        implode('|', array_keys(self::ON_OFF_FLAGS)),
                        $statusName
                    )
                );
                $error = true;
            } else {
                Config::save("{$ref}.webhook.push", (bool)self::ON_OFF_FLAGS[$statusName]);
            }
        }

        // -- $ref.webhook.match.user
        if ($input->getOption('webhook-match-user')) {
            $statusName = strtolower($input->getOption('webhook-match-user'));

            if (!array_key_exists($statusName, self::ON_OFF_FLAGS)) {
                $output->writeln(
                    sprintf(
                        '<error>Unexpected value for --webhook-match-user, was expecting one of [%s] but got \'%s\' instead.',
                        implode('|', array_keys(self::ON_OFF_FLAGS)),
                        $statusName
                    )
                );
                $error = true;
            } else {
                if (null === Config::get("{$ref}.user")) {
                    $output->writeln(
                        '<error>ERROR: To enable user matching in webhook context, please set the user first using --user flag.'
                    );
                    $error = true;
                } else {
                    Config::save("{$ref}.webhook.match.user", (bool)self::ON_OFF_FLAGS[$statusName]);
                }
            }
        }

        if (!$input->getOption('no-backup') && is_writable(dirname($config))) {
            copy($config, $config . '.bak');
        }

        file_put_contents($config, Yaml::dump(Config::get('servers', []), 8, 2));

        return false !== $error ? self::SUCCESS : self::FAILURE;
    }
}
