<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\ConfigFile;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Class EditCommand
 *
 * This class allows the user to edit backend config settings inline.
 */
#[Cli(command: self::ROUTE)]
final class EditCommand extends Command
{
    public const ROUTE = 'config:edit';

    public function __construct(private LoggerInterface $logger)
    {
        parent::__construct();
    }

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Edit backend settings inline.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Key to update.')
            ->addOption('set', 'e', InputOption::VALUE_REQUIRED, 'Value to set.')
            ->addOption('delete', 'd', InputOption::VALUE_NONE, 'Delete value.')
            ->addOption('regenerate-webhook-token', 'g', InputOption::VALUE_NONE, 'Re-generate backend webhook token.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->setHelp(
                r(
                    <<<HELP

                    This command allow you to <notice>edit</notice> backend config settings <notice>inline</notice>.

                    The [<flag>-k, --key</flag>] accept string value. the list of officially supported keys are:

                    [{keyNames}]

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to edit config setting?</question>

                    {cmd} <cmd>{route}</cmd> <flag>-k</flag> <value>key</value> <flag>-e</flag> <value>value</value> <flag>-s</flag> <value>backend_name</value>

                    <question># How to change the re-generate webhook token?</question>

                    {cmd} <cmd>{route}</cmd> <flag>-g -s</flag> <value>backend_name</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'manage_route' => ManageCommand::ROUTE,
                        'keyNames' => implode(
                            ', ',
                            array_map(
                                fn($val) => '<value>' . $val . '</value>',
                                array_keys(
                                    array_filter(
                                        array: require __DIR__ . '/../../../config/backend.spec.php',
                                        callback: fn($val, $key) => $val,
                                        mode: ARRAY_FILTER_USE_BOTH
                                    )
                                )
                            ),
                        )
                    ]
                )
            );
    }

    /**
     * Runs the command.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     * @param null|array $rerun The rerun array. Default is null.
     *
     * @return int The command status code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output, null|array $rerun = null): int
    {
        $name = $input->getOption('select-backend');

        if (empty($name)) {
            $output->writeln(r('<error>ERROR: Backend not specified. Please use [-s, --select-backend].</error>'));
            return self::FAILURE;
        }

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

        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml');
        $configFile->setLogger($this->logger);

        if (null === $configFile->get("{$name}.type", null)) {
            $output->writeln(r('<error>ERROR: Backend \'{name}\' not found.</error>', ['name' => $name]));
            return self::FAILURE;
        }

        if ($input->getOption('regenerate-webhook-token')) {
            try {
                $webhookToken = bin2hex(random_bytes(Config::get('webhook.tokenLength')));

                $output->writeln(r('<info>The webhook token for \'{name}\' is: \'{token}\'.</info>', [
                    'name' => $name,
                    'token' => $webhookToken
                ]));

                $configFile->set("{$name}.webhook.token", $webhookToken);
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
                if ($configFile->has("{$name}.{$key}")) {
                    $val = $configFile->get("{$name}.{$key}", '[No value]');
                } else {
                    $val = '[Not set]';
                }

                $output->writeln(is_scalar($val) ? (string)$val : r('Type({type})', ['type' => get_debug_type($val)]));
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

                if ($value === $configFile->get("{$name}.{$key}", null)) {
                    $output->writeln('<comment>Not updating. Value already matches.</comment>');
                    return self::SUCCESS;
                }

                $configFile->set("{$name}.{$key}", $value);

                $output->writeln(r("<info>{name}: Updated '{key}' key value to '{value}'.</info>", [
                    'name' => $name,
                    'key' => $key,
                    'value' => is_bool($value) ? (true === $value ? 'true' : 'false') : $value,
                ]));
            }

            if ($input->getOption('delete')) {
                if (false === $configFile->has("{$name}.{$key}")) {
                    $output->writeln(r("<error>{name}: '{key}' key does not exist.</error>", [
                        'name' => $name,
                        'key' => $key
                    ]));
                    return self::FAILURE;
                }

                $configFile->delete("{$name}.{$key}");
                $output->writeln(r("<info>{name}: Removed '{key}' key.</info>", [
                    'name' => $name,
                    'key' => $key
                ]));
            }
        }

        $configFile->persist();

        return self::SUCCESS;
    }

    /**
     * This method completes the suggestions for a given input based on certain conditions.
     *
     * @param CompletionInput $input The completion input object.
     * @param CompletionSuggestions $suggestions The completion suggestions object.
     *
     * @return void
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestOptionValuesFor('key')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (require __DIR__ . '/../../../config/backend.spec.php' as $name => $val) {
                if (false === $val) {
                    continue;
                }

                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
