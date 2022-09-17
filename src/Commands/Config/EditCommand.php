<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Config;
use App\Libs\Routable;
use RuntimeException;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

#[Routable(command: self::ROUTE), Routable(command: 'servers:edit')]
final class EditCommand extends Command
{
    public const ROUTE = 'config:edit';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Edit backend settings inline.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Key to update.')
            ->addOption('set', 's', InputOption::VALUE_REQUIRED, 'Value to set.')
            ->addOption('delete', 'd', InputOption::VALUE_NONE, 'Delete value.')
            ->addOption('regenerate-webhook-token', 'g', InputOption::VALUE_NONE, 'Re-generate backend webhook token.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name')
            ->setAliases(['servers:edit'])
            ->setHelp(
                r(
                    <<<HELP

                    This command allow you to <notice>edit</notice> backend config settings <notice>inline</notice>.

                    The [<flag>--key</flag>] accept string value. the list of officially supported keys are:

                    [{keyNames}]

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to edit config setting?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--key</flag> <value>key</value> <flag>--set</flag> <value>value</value> -- <value>backend_name</value>

                    <question># How to change the webhook token?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--regenerate-webhook-token</flag> -- <value>backend_name</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'manage_route' => ManageCommand::ROUTE,
                        'keyNames' => implode(
                            ', ',
                            array_map(
                                fn($val) => '<value>' . $val . '</value>',
                                require __DIR__ . '/../../../config/backend.spec.php'
                            )
                        )
                    ]
                )
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output, null|array $rerun = null): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                $custom = true;
                $backends = Yaml::parseFile($this->checkCustomBackendsFile($config));
            } catch (RuntimeException $e) {
                $output->writeln(r('<error>{error}</error>', ['error' => $e->getMessage()]));
                return self::FAILURE;
            }
        } else {
            $custom = false;
            $config = Config::get('path') . '/config/servers.yaml';
            if (!file_exists($config)) {
                touch($config);
            }
            $backends = (array)Config::get('servers', []);
        }

        $name = $input->getArgument('backend');

        if (!isValidName($name) || strtolower($name) !== $name) {
            $output->writeln(
                r(
                    '<error>ERROR:</error> Invalid [<value>{name}</value>] name was given. Only [<value>a-z, 0-9, _</value>] are allowed.',
                    [
                        'name' => $name
                    ]
                )
            );
            return self::FAILURE;
        }

        if (null === ($backend = ag($backends, $name, null))) {
            $output->writeln(r('<error>ERROR: Backend \'{name}\' not found.</error>', ['name' => $name]));
            return self::FAILURE;
        }

        if ($input->getOption('regenerate-webhook-token')) {
            try {
                $webhookToken = bin2hex(random_bytes(Config::get('webhook.tokenLength')));

                $output->writeln(
                    r('<info>The webhook token for \'{name}\' is: \'{token}\'.</info>', [
                        'name' => $name,
                        'token' => $webhookToken
                    ])
                );

                $backend = ag_set($backend, 'webhook.token', $webhookToken);
            } catch (Throwable $e) {
                $output->writeln(r('<error>ERROR: {error}</error>', ['error' => $e->getMessage()]));
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
                $output->writeln(ag($backend, $key));
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

                if ($value === ag($backend, $key, null)) {
                    $output->writeln('<comment>Not updating. Value already matches.</comment>');
                    return self::SUCCESS;
                }

                $backend = ag_set($backend, $key, $value);

                $output->writeln(
                    r('<info>{name}: Updated \'{key}\' key value to \'{value}\'.</info>', [
                        'name' => $name,
                        'key' => $key,
                        'value' => is_bool($value) ? (true === $value ? 'true' : 'false') : $value,
                    ])
                );
            }

            if ($input->getOption('delete')) {
                if (false === ag_exists($backend, $key)) {
                    $output->writeln(
                        r('<error>{name}: \'{key}\' key does not exists.</error>', [
                            'name' => $name,
                            'key' => $key
                        ])
                    );
                    return self::FAILURE;
                }

                $backend = ag_delete($backend, $key);
                $output->writeln(
                    r('<info>{name}: Removed \'{key}\' key.</info>', [
                        'name' => $name,
                        'key' => $key
                    ])
                );
            }
        }

        if (false === $custom) {
            copy($config, $config . '.bak');
        }

        file_put_contents($config, Yaml::dump(ag_set($backends, $name, $backend), 8, 2));

        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestOptionValuesFor('key')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (require __DIR__ . '/../../../config/backend.spec.php' as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
