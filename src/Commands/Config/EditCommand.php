<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Enums\Http\Status;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class EditCommand
 *
 * This class allows the user to edit backend config settings inline.
 */
#[Cli(command: self::ROUTE)]
final class EditCommand extends Command
{
    public const string ROUTE = 'config:edit';

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Edit backend settings inline.')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Key to update.')
            ->addOption('set', 'e', InputOption::VALUE_REQUIRED, 'Value to set.')
            ->addOption('delete', 'd', InputOption::VALUE_NONE, 'Delete value.')
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
                                array_column(require __DIR__ . '/../../../config/servers.spec.php', 'key')
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

        if (null === ($key = $input->getOption('key'))) {
            $output->writeln('<error>ERROR: [-k, --key] flag is required.</error>');
            return self::FAILURE;
        }

        $hasSet = null !== $input->getOption('set');

        $json = [];
        if ($input->getOption('delete')) {
            $method = 'DELETE';
        } elseif ($hasSet) {
            $method = 'POST';
            $json['value'] = $input->getOption('set');
        } else {
            $method = 'GET';
        }

        $response = apiRequest($method, "/backend/{$name}/option/{$key}", $json);

        if (Status::HTTP_OK !== $response->status) {
            $output->writeln(r("<error>API error. {status}: {message}</error>", [
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        if ($input->getOption('delete')) {
            $output->writeln(r("<info>Key '{key}' was deleted.</info>", ['key' => $key]));
            return self::SUCCESS;
        }

        if ($hasSet) {
            if ('bool' === ag($response->body, 'type', 'string')) {
                $value = true === (bool)ag($response->body, 'value') ? 'On (True)' : 'Off (False)';
            } else {
                $value = ag($json, 'value');
            }

            $output->writeln(
                r("<info>Key '<value>{key}</value>' was updated with value '<value>{value}</value>'.</info>", [
                    'key' => $key,
                    'value' => $value,
                ])
            );

            return self::SUCCESS;
        }

        $output->writeln((string)ag($response->body, 'value', '[not_set]'));

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

            foreach (require __DIR__ . '/../../../config/servers.spec.php' as $column) {
                if (false === (bool)ag($column, 'visible', false)) {
                    continue;
                }

                if (empty($currentValue) || str_starts_with(ag($column, 'key', ''), $currentValue)) {
                    $suggest[] = ag($column, 'key', '');
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
