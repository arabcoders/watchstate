<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\HTTP_STATUS;
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
            ->addOption(
                'envfile',
                null,
                InputOption::VALUE_REQUIRED,
                'Environment file.',
                Config::get('path') . '/config/.env'
            )
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Key to update.')
            ->addOption('set', 'e', InputOption::VALUE_REQUIRED, 'Value to set.')
            ->addOption('delete', 'd', InputOption::VALUE_NONE, 'Delete key.')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List All Supported keys.')
            ->setHelp(
                r(
                    <<<HELP

                    This command display the environment variables that was loaded during execution of the tool.

                    -------------------------------
                    <notice>[ Environment variables rules ]</notice>
                    -------------------------------

                    * the key MUST be in CAPITAL LETTERS. For example [<flag>WS_CRON_IMPORT</flag>].
                    * the key MUST start with [<flag>WS_</flag>]. For example [<flag>WS_CRON_EXPORT</flag>].
                    * the value is usually simple type, usually string unless otherwise stated.
                    * the key SHOULD attempt to mirror the key path in default config, If not applicable or otherwise impossible it
                    should then use an approximate path.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to load environment variables?</question>

                    You can load environment variables in many ways. However, the recommended methods are:

                    <question>(1) Via Docker compose file</>

                    You can load environment variables via [<comment>compose.yaml</comment>] file by adding them under the [<comment>environment</comment>] key.
                    For example, to enable import task, do the following:

                    -------------------------------
                    services:
                      watchstate:
                        image: ghcr.io/arabcoders/watchstate:latest
                        restart: unless-stopped
                        container_name: watchstate
                        <flag>environment:</flag>
                          - <flag>WS_CRON_IMPORT</flag>=<value>1</value>
                    -------------------------------

                    <question>(2) Via .env file</question>

                    We automatically look for [<value>.env</value>] in this path [<value>{path}</value>]. The file usually
                    does not exist unless you have created it.

                    The file format is simple <flag>key</flag>=<value>value</value> per line. For example, to enable import task, edit the [<value>.env</value>] and add

                    -------------------------------
                    <flag>WS_CRON_IMPORT</flag>=<value>1</value>
                    -------------------------------

                    HELP,
                    [
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

        if (HTTP_STATUS::HTTP_OK !== $response->status) {
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

            $keys[] = $item;
        }

        $this->displayContent($keys, $output, $mode);

        return self::SUCCESS;
    }
}
