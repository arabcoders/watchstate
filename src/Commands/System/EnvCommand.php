<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

/**
 * Class EnvCommand
 *
 * This command displays the environment variables that were loaded during the execution of the tool.
 */
#[Cli(command: self::ROUTE)]
final class EnvCommand extends Command
{
    public const string ROUTE = 'system:env';

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Show/edit environment variables.')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Key to update.')
            ->addOption('set', 'e', InputOption::VALUE_REQUIRED, 'Value to set.')
            ->addOption('delete', 'd', InputOption::VALUE_NONE, 'Delete key.')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List All Supported keys.')
            ->addOption('expose', 'x', InputOption::VALUE_NONE, 'Expose Hidden values.')
            ->setHelp(
                r(
                    <<<HELP

                    This command display the environment variables that was loaded during execution of the tool.

                    -------------------------------
                    <notice>[ Environment variables rules ]</notice>
                    -------------------------------

                    * The key MUST be in CAPITAL LETTERS. For example [<flag>WS_CRON_IMPORT</flag>].
                    * The key MUST start with [<flag>WS_</flag>]. For example [<flag>WS_CRON_EXPORT</flag>].
                    * The value is simple string. No complex data types are allowed. or shell expansion variables.
                    * The value MUST be in one line. No multi-line values are allowed.
                    * The key SHOULD attempt to mirror the key path in default config, If not applicable or otherwise impossible it
                    should then use an approximate path.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to load environment variables?</question>

                    For <comment>WatchState</comment> specific environment variables, we recommend using the <comment>WebUI</comment>,
                    to manage the environment variables. However, you can also use this command to manage the environment variables.

                    We use this file to load your environment variables:

                    - <flag>{path}</flag>/<comment>.env</comment>

                    To load container specific variables i,e, the keys that does not start with <comment>WS_</comment> prefix,
                    you can use the <comment>compose.yaml</comment> file.

                    For example,
                    -------------------------------
                    services:
                      watchstate:
                        image: ghcr.io/arabcoders/watchstate:latest
                        restart: unless-stopped
                        container_name: watchstate
                        <flag>environment:</flag>
                          - <flag>HTTP_PORT</flag>=<value>8080</value>
                          - <flag>DISABLE_CACHE</flag>=<value>1</value>
                    .......
                    -------------------------------

                    <question># How to set environment variables?</question>

                    To set an environment variable, you can use the following command:

                    {cmd} <cmd>{route}</cmd> <flag>-k <value>ENV_NAME</value> -e <value>ENV_VALUE</value></flag>

                    <notice>Note: if you are using a space within the value you need to use the long form --set, for example:

                    {cmd} <cmd>{route}</cmd> <flag>-k <value>ENV_NAME</value> --set=<notice>"</notice><value>ENV VALUE</value><notice>"</notice></flag>

                    As you can notice the spaced value is wrapped with double <value>""</value> quotes.</notice>

                    <question># How to see all possible environment variables?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--list</flag>

                    <question># How to delete environment variable?</question>

                    {cmd} <cmd>{route}</cmd> <flag>-d -k</flag> <value>ENV_NAME</value>

                    <question># How to get specific environment variable value?</question>

                    {cmd} <cmd>{route}</cmd> <flag>-k</flag> <value>ENV_NAME</value>

                    <notice>This will show the hidden value if the environment variable marked as sensitive.</notice>

                    <question># How to expose the hidden values for secret environment variables?</question>

                    You can use the <flag>--expose</flag> flag to expose the hidden values. for both <flag>--list</flag>
                    or just the normal table display. For example:

                    {cmd} <cmd>{route}</cmd> <flag>--expose</flag>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'path' => after(Config::get('path') . '/config', ROOT_PATH),
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
        if ($input->getOption('list')) {
            return $this->handleEnvList($input, $output, true);
        }

        if ($input->getOption('key')) {
            return $this->handleEnvUpdate($input, $output);
        }

        return $this->handleEnvList($input, $output, false);
    }

    private function handleEnvUpdate(iInput $input, iOutput $output): int
    {
        $key = strtoupper($input->getOption('key'));

        if (!$input->getOption('set') && !$input->getOption('delete')) {
            $output->writeln((string)env($key, ''));
            return self::SUCCESS;
        }

        if (true === (bool)$input->getOption('delete')) {
            $response = APIRequest('DELETE', '/system/env/' . $key);
        } else {
            $response = APIRequest('POST', '/system/env/' . $key, ['value' => $input->getOption('set')]);
        }

        if (Status::OK !== $response->status) {
            $output->writeln(r("<error>API error. {status}: {message}</error>", [
                'key' => $key,
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        $output->writeln(r("<info>Key '{key}' was {action}.</info>", [
            'key' => $key,
            'action' => true === (bool)$input->getOption('delete') ? 'deleted' : 'updated',
        ]));

        return self::SUCCESS;
    }

    private function handleEnvList(iInput $input, iOutput $output, bool $all = true): int
    {
        $query = [];

        if (false === $all) {
            $query['set'] = 1;
        }

        $response = APIRequest('GET', '/system/env', opts: [
            'query' => $query,
        ]);

        $keys = [];

        $mode = $input->getOption('output');

        $data = ag($response->body, 'data', []);
        foreach ($data as $info) {
            $item = [
                'key' => $info['key'],
                'description' => $info['description'],
                'type' => $info['type'],
                'value' => ag($info, 'value', 'Not set'),
            ];

            if (true === (bool)ag($info, 'mask') && !$input->getOption('expose')) {
                $item['value'] = '*HIDDEN*';
            }

            if ('table' === $mode) {
                unset($item['description']);
            }

            $keys[] = $item;
        }

        $this->displayContent($keys, $output, $mode);

        return self::SUCCESS;
    }
}
