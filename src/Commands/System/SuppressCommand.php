<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\API\System\Suppressor;
use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Enums\Http\Status;
use App\Libs\Extends\ConsoleOutput;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

/**
 * Class SuppressCommand
 *
 * This command manage the Log Suppressor.
 */
#[Cli(command: self::ROUTE)]
final class SuppressCommand extends Command
{
    public const string ROUTE = 'system:suppress';

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Log message Suppressor controller.')
            ->addOption('add', 'a', InputOption::VALUE_NONE, 'Add suppression rule.')
            ->addOption('edit', 'e', InputOption::VALUE_REQUIRED, 'Edit suppression rule.')
            ->addOption('delete', 'd', InputOption::VALUE_REQUIRED, 'Delete Suppression rule.')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Suppression rule type.')
            ->addOption('rule', 'r', InputOption::VALUE_REQUIRED, 'Suppression rule.')
            ->addOption('example', 'x', InputOption::VALUE_REQUIRED, 'Suppression rule example.')
            ->setHelp(
                r(
                    <<<HELP
                    The Suppressor is a tool that allows you to suppress certain log messages from being shown/recorded.

                    Supported suppression types: '{types}'.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question>How to add simple suppression rule?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--add --type <value>contains</value> --rule '<value>hide_me</value>' --example '<value>this string hide_me contains the suppressed word.</value>'</flag>

                    <question>How to add regex (Regular expression) suppression rule?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--add --type <value>regex</value> --rule "<value>/this rule \'(\d+)\'/is</value>" --example '<value>This rule '123' is matched dynamically.</value>'</flag>

                    <question>How to delete rule?</question>

                    First, get the rule id by listing the rules. Then use the following command to delete the rule.

                    {cmd} <cmd>{route}</cmd> <flag>--delete <value>id</value></flag>

                    <question>How to edit rule?</question>

                    First, get the rule id by listing the rules. Then use the following command to edit the rule.

                    {cmd} <cmd>{route}</cmd> <flag>--edit <value>id</value> --rule '<value>new_rule</value>' --example '<value>new example</value>'</flag>

                    <notice>The rule type cannot be changed.</notice>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'types' => implode(
                            ', ',
                            array_map(fn($val) => '<value>' . $val . '</value>', Suppressor::TYPES)
                        ),
                    ]
                )
            );
    }

    /**
     * Run the command.
     *
     * @param iInput $input The input interface.
     * @param iOutput $output The output interface.
     *
     * @return int The exit code.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        assert($output instanceof ConsoleOutput, new RuntimeException('Expecting ConsoleOutput instance.'));

        $output = $output->withNoSuppressor();

        if ($input->getOption('add')) {
            return $this->handleSuppressAdd($input, $output);
        }

        if ($input->getOption('edit')) {
            return $this->handleSuppressEdit($input, $output);
        }

        if ($input->getOption('delete')) {
            return $this->handleSuppressDelete($input, $output);
        }

        return $this->suppressList($input, $output);
    }

    private function handleSuppressEdit(iInput $input, iOutput $output): int
    {
        if (null === ($id = $input->getOption('edit')) || empty($id)) {
            $output->writeln(r("<error>Invalid suppressor id.</error>"));
            return self::FAILURE;
        }

        $response = APIRequest('GET', '/system/suppressor/' . $id);
        if (Status::OK !== $response->status) {
            $output->writeln(r("<error>API error. {status}: {message}</error>", [
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        $args = $response->body;
        $changed = false;

        if ($input->getOption('type')) {
            $changed = true;
            $args['type'] = $input->getOption('type');
        }

        if ($input->getOption('rule')) {
            $changed = true;
            $args['rule'] = $input->getOption('rule');
        }

        if ($input->getOption('example')) {
            $changed = true;
            $args['example'] = $input->getOption('example');
        }

        if (false === $changed) {
            $output->writeln(r("<error>No changes detected.</error>"));
            return self::FAILURE;
        }

        return $this->handleSuppressAdd($input, $output, $args);
    }

    private function handleSuppressAdd(iInput $input, iOutput $output, array $args = []): int
    {
        $id = ag($args, 'id', null);
        $type = ag($args, 'type', $input->getOption('type'));
        $rule = ag($args, 'rule', $input->getOption('rule'));
        $example = ag($args, 'example', $input->getOption('example'));

        if (empty($type)) {
            $output->writeln(r("<error>Invalid suppressor type.</error>"));
            return self::FAILURE;
        }

        if (empty($rule)) {
            $output->writeln(r("<error>Invalid suppressor rule.</error>"));
            return self::FAILURE;
        }

        if (empty($example)) {
            $output->writeln(r("<error>Invalid suppressor example.</error>"));
            return self::FAILURE;
        }

        $data = [
            'type' => $type,
            'rule' => $rule,
            'example' => $example,
        ];

        $response = APIRequest($id ? 'PUT' : 'POST', '/system/suppressor' . ($id ? '/' . $id : ''), $data);

        if (Status::OK !== $response->status) {
            $output->writeln(r("<error>API error. {status}: {message}</error>", [
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        $output->writeln(r("<info>Suppressor rule successfully {action}.</info>", [
            'action' => null !== $id ? 'edited' : 'added'
        ]));
        return self::SUCCESS;
    }

    private function handleSuppressDelete(iInput $input, iOutput $output): int
    {
        if (null === ($id = $input->getOption('delete')) || empty($id)) {
            $output->writeln(r("<error>Invalid suppressor id.</error>"));
            return self::FAILURE;
        }

        $response = APIRequest('DELETE', '/system/suppressor/' . $id);
        if (Status::OK !== $response->status) {
            $output->writeln(r("<error>API error. {status}: {message}</error>", [
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        $output->writeln(r("<info>Suppressor rule deleted successfully.</info>"));
        return self::SUCCESS;
    }

    private function suppressList(iInput $input, iOutput $output): int
    {
        $response = APIRequest('GET', '/system/suppressor');

        if (Status::OK !== $response->status) {
            $output->writeln(r("<error>API error. {status}: {message}</error>", [
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        $this->displayContent(ag($response->body, 'items', []), $output, $input->getOption('output'));

        return self::SUCCESS;
    }
}
